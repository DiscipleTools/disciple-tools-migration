<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * System user export/import for migrations (Phase 1).
 *
 * Exports safe user fields (no passwords). On import, maps source user IDs to target IDs.
 * Administrators are migrated like other users: matched by email or login when present, or
 * created with roles from the export. Assigning administrator requires promote_users (and
 * create_users for new accounts).
 */
class Disciple_Tools_Migration_System_Users {

    public const USER_ID_MAP_OPTION = 'dt_migration_user_id_map';

    /**
     * User meta keys safe to include in export/import (no secrets).
     *
     * @var string[]
     */
    private static $allowed_meta_keys = [
        'nickname',
        'first_name',
        'last_name',
        'description',
        'locale',
        'rich_editing',
        'syntax_highlighting',
        'admin_color',
    ];

    /**
     * Builds the system_users export block (API + file).
     *
     * @return array{ source_site: string, exported_at: int, users: array<int, array> }
     */
    public static function build_export_payload() : array {
        $users_export = [];
        $users        = get_users(
            [
                'orderby' => 'ID',
                'order'   => 'ASC',
                'fields'  => 'all',
                'number'  => -1,
            ]
        );

        foreach ( $users as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }
            $meta = [];
            foreach ( self::$allowed_meta_keys as $key ) {
                $val = get_user_meta( $user->ID, $key, true );
                if ( $val !== '' && $val !== false && $val !== null ) {
                    $meta[ $key ] = $val;
                }
            }
            $users_export[] = [
                'id'            => (int) $user->ID,
                'user_login'    => $user->user_login,
                'user_email'    => $user->user_email,
                'user_nicename' => $user->user_nicename,
                'display_name'  => $user->display_name,
                'roles'         => array_values( array_filter( (array) $user->roles ) ),
                'registered'    => $user->user_registered,
                'meta'          => $meta,
            ];
        }

        return [
            'source_site'  => get_site_url(),
            'exported_at'  => time(),
            'users'        => $users_export,
        ];
    }

    /**
     * Whether the exported user row represents a WordPress administrator on the source.
     *
     * @param array $user_row Export row with 'roles' key.
     */
    public static function is_migration_admin_user( array $user_row ) : bool {
        $roles = $user_row['roles'] ?? [];
        return is_array( $roles ) && in_array( 'administrator', $roles, true );
    }

    /**
     * Finds an existing user on this site by email, then login.
     *
     * @param string $email
     * @param string $login
     * @return WP_User|null
     */
    public static function find_existing_user( string $email, string $login ) {
        if ( $email !== '' ) {
            $by_email = get_user_by( 'email', $email );
            if ( $by_email instanceof WP_User ) {
                return $by_email;
            }
        }
        if ( $login !== '' ) {
            $by_login = get_user_by( 'login', $login );
            if ( $by_login instanceof WP_User ) {
                return $by_login;
            }
        }
        return null;
    }

    /**
     * Imports users from export payload and stores old_id => new_id map.
     *
     * @param array $system_users Block from export.system_users.
     * @return array{ error?: string, map: array<string, int>, created: int, mapped_existing: int, admin_mapped: int, admin_created: int }
     */
    public static function apply_import( array $system_users ) : array {
        $out = [
            'map'             => [],
            'created'         => 0,
            'mapped_existing' => 0,
            'admin_mapped'    => 0,
            'admin_created'   => 0,
        ];

        $rows = $system_users['users'] ?? [];
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            return $out;
        }

        if ( ! current_user_can( 'manage_dt' ) ) {
            return array_merge( $out, [
                'error' => __( 'You do not have permission to run user migration on this site.', 'disciple-tools-migration' ),
            ] );
        }

        usort(
            $rows,
            static function ( $a, $b ) {
                $ia = isset( $a['id'] ) ? (int) $a['id'] : 0;
                $ib = isset( $b['id'] ) ? (int) $b['id'] : 0;
                return $ia <=> $ib;
            }
        );

        foreach ( $rows as $row ) {
            $old_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
            if ( $old_id <= 0 ) {
                continue;
            }

            $email = isset( $row['user_email'] ) ? sanitize_email( (string) $row['user_email'] ) : '';
            $login = isset( $row['user_login'] ) ? sanitize_user( (string) $row['user_login'], true ) : '';
            $is_src_admin = self::is_migration_admin_user( $row );

            $existing = self::find_existing_user( $email, $login );
            if ( $existing instanceof WP_User ) {
                $out['map'][ (string) $old_id ] = (int) $existing->ID;
                ++$out['mapped_existing'];

                $norm_roles = self::normalized_roles_from_row( $row );

                if ( is_multisite() && ! is_user_member_of_blog( $existing->ID, get_current_blog_id() ) ) {
                    $add_err = self::add_existing_user_to_current_blog( $existing->ID, $norm_roles );
                    if ( $add_err !== null ) {
                        return array_merge( $out, [ 'error' => $add_err ] );
                    }
                }

                if ( $is_src_admin ) {
                    $roles_err = self::assign_roles_to_wp_user( $existing->ID, $norm_roles );
                    if ( $roles_err !== null ) {
                        return array_merge( $out, [ 'error' => $roles_err ] );
                    }
                    ++$out['admin_mapped'];
                }

                self::maybe_update_user_profile( $existing->ID, $row );
                continue;
            }

            if ( ! current_user_can( 'create_users' ) ) {
                return array_merge( $out, [
                    'error' => sprintf(
                        /* translators: 1: old user ID */
                        __( 'Cannot create missing user (source ID %1$d): create_users capability required.', 'disciple-tools-migration' ),
                        $old_id
                    ),
                ] );
            }

            $norm_roles = self::normalized_roles_from_row( $row );
            if ( in_array( 'administrator', $norm_roles, true ) && ! current_user_can( 'promote_users' ) ) {
                return array_merge( $out, [
                    'error' => sprintf(
                        /* translators: 1: old user ID */
                        __( 'Cannot create administrator account for source ID %1$d: promote_users capability required.', 'disciple-tools-migration' ),
                        $old_id
                    ),
                ] );
            }

            $new_id = self::create_user_from_row( $row );
            if ( is_wp_error( $new_id ) ) {
                return array_merge( $out, [
                    'error' => sprintf(
                        /* translators: 1: old user ID, 2: error message */
                        __( 'Failed to create user for source ID %1$d: %2$s', 'disciple-tools-migration' ),
                        $old_id,
                        $new_id->get_error_message()
                    ),
                ] );
            }
            $out['map'][ (string) $old_id ] = (int) $new_id;
            ++$out['created'];
            if ( $is_src_admin ) {
                ++$out['admin_created'];
            }
        }

        $option_value = [
            'source_site'  => isset( $system_users['source_site'] ) ? esc_url_raw( (string) $system_users['source_site'] ) : '',
            'exported_at'  => isset( $system_users['exported_at'] ) ? (int) $system_users['exported_at'] : 0,
            'imported_at'  => time(),
            'map'          => $out['map'],
        ];
        update_option( self::USER_ID_MAP_OPTION, $option_value, false );

        return $out;
    }

    /**
     * Returns the stored migration user ID map (target ID per source ID string key), or null.
     *
     * @return array<string, int>|null
     */
    public static function get_stored_user_id_map() : ?array {
        $opt = get_option( self::USER_ID_MAP_OPTION, null );
        if ( ! is_array( $opt ) || empty( $opt['map'] ) || ! is_array( $opt['map'] ) ) {
            return null;
        }
        $map = [];
        foreach ( $opt['map'] as $old => $new ) {
            $map[ (string) $old ] = (int) $new;
        }
        return $map;
    }

    /**
     * Remaps a source user ID to the target site user ID if known.
     *
     * @param int $source_user_id User ID from Server A export.
     * @return int Target user ID, or original if no mapping.
     */
    public static function remap_user_id( int $source_user_id ) : int {
        if ( $source_user_id <= 0 ) {
            return $source_user_id;
        }
        $map = self::get_stored_user_id_map();
        if ( $map === null ) {
            return $source_user_id;
        }
        $key = (string) $source_user_id;
        return isset( $map[ $key ] ) ? $map[ $key ] : $source_user_id;
    }

    /**
     * @param int   $user_id
     * @param array $row
     */
    private static function maybe_update_user_profile( int $user_id, array $row ) : void {
        $args = [ 'ID' => $user_id ];
        if ( isset( $row['display_name'] ) && $row['display_name'] !== '' ) {
            $args['display_name'] = sanitize_text_field( (string) $row['display_name'] );
        }
        if ( ! empty( $row['meta'] ) && is_array( $row['meta'] ) ) {
            if ( isset( $row['meta']['first_name'] ) ) {
                $args['first_name'] = sanitize_text_field( (string) $row['meta']['first_name'] );
            }
            if ( isset( $row['meta']['last_name'] ) ) {
                $args['last_name'] = sanitize_text_field( (string) $row['meta']['last_name'] );
            }
            if ( isset( $row['meta']['description'] ) ) {
                $args['description'] = sanitize_textarea_field( (string) $row['meta']['description'] );
            }
        }
        if ( count( $args ) > 1 ) {
            wp_update_user( $args );
        }
        if ( ! empty( $row['meta'] ) && is_array( $row['meta'] ) ) {
            foreach ( self::$allowed_meta_keys as $key ) {
                if ( ! isset( $row['meta'][ $key ] ) ) {
                    continue;
                }
                update_user_meta( $user_id, $key, wp_unslash( $row['meta'][ $key ] ) );
            }
        }
    }

    /**
     * Role slugs from the export row, with administrator ensured when the row is a source admin.
     *
     * @param array $row Export user row.
     * @return string[]
     */
    private static function normalized_roles_from_row( array $row ) : array {
        $roles = isset( $row['roles'] ) && is_array( $row['roles'] ) ? $row['roles'] : [];
        $roles = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
        if ( self::is_migration_admin_user( $row ) && ! in_array( 'administrator', $roles, true ) ) {
            $roles[] = 'administrator';
        }
        return $roles;
    }

    /**
     * Replaces the user's roles with the given list (same rules as new-user creation).
     *
     * Avoids set_role( '' ) so the target user is never left roleless mid-import.
     *
     * @param int   $user_id WordPress user ID.
     * @param array $roles   Sanitized role slugs (may be empty).
     * @return string|null Error message, or null on success.
     */
    private static function assign_roles_to_wp_user( int $user_id, array $roles ) : ?string {
        $roles = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
        if ( in_array( 'administrator', $roles, true ) && ! current_user_can( 'promote_users' ) ) {
            return __( 'You do not have permission to assign the administrator role.', 'disciple-tools-migration' );
        }

        $wp_roles = wp_roles();
        foreach ( $roles as $slug ) {
            if ( ! $wp_roles->is_role( $slug ) ) {
                return sprintf(
                    /* translators: %s: role slug from export */
                    __( 'Unknown or invalid role in export: %s', 'disciple-tools-migration' ),
                    $slug
                );
            }
        }

        $user = new WP_User( $user_id );
        if ( ! empty( $roles ) ) {
            $primary = array_shift( $roles );
            $user->set_role( $primary );
            foreach ( $roles as $extra_role ) {
                $user->add_role( $extra_role );
            }
        } else {
            $default_role = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );
            if ( ! $wp_roles->is_role( $default_role ) ) {
                $default_role = 'subscriber';
            }
            $user->set_role( $default_role );
        }

        return null;
    }

    /**
     * Adds an existing network user to the current subsite using their source roles.
     *
     * Multisite-only. Skips when user is already a member of the current blog.
     * Uses add_user_to_blog() for the primary role so the standard hooks fire,
     * then adds any extra roles via WP_User::add_role(). Falls back to the site's
     * default role when the export carries no usable role.
     *
     * @param int      $user_id Target site user ID.
     * @param string[] $roles   Sanitized role slugs from the export row.
     * @return string|null Error message, or null on success / no-op.
     */
    private static function add_existing_user_to_current_blog( int $user_id, array $roles ) : ?string {
        $roles = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );

        if ( in_array( 'administrator', $roles, true ) && ! current_user_can( 'promote_users' ) ) {
            return __( 'You do not have permission to assign the administrator role.', 'disciple-tools-migration' );
        }

        $wp_roles = wp_roles();
        foreach ( $roles as $slug ) {
            if ( ! $wp_roles->is_role( $slug ) ) {
                return sprintf(
                    /* translators: %s: role slug from export */
                    __( 'Unknown or invalid role in export: %s', 'disciple-tools-migration' ),
                    $slug
                );
            }
        }

        if ( empty( $roles ) ) {
            $default_role = sanitize_key( (string) get_option( 'default_role', 'subscriber' ) );
            if ( ! $wp_roles->is_role( $default_role ) ) {
                $default_role = 'subscriber';
            }
            $primary = $default_role;
            $extras  = [];
        } else {
            $primary = array_shift( $roles );
            $extras  = $roles;
        }

        $result = add_user_to_blog( get_current_blog_id(), $user_id, $primary );
        if ( is_wp_error( $result ) ) {
            return $result->get_error_message();
        }

        if ( ! empty( $extras ) ) {
            $user = new WP_User( $user_id );
            foreach ( $extras as $extra_role ) {
                $user->add_role( $extra_role );
            }
        }

        return null;
    }

    /**
     * @param array $row
     * @return int|WP_Error
     */
    private static function create_user_from_row( array $row ) {
        $login = isset( $row['user_login'] ) ? sanitize_user( (string) $row['user_login'], true ) : '';
        $email = isset( $row['user_email'] ) ? sanitize_email( (string) $row['user_email'] ) : '';

        if ( $login === '' || $email === '' ) {
            return new WP_Error( 'missing_login_email', __( 'User login and email are required.', 'disciple-tools-migration' ) );
        }

        if ( username_exists( $login ) || email_exists( $email ) ) {
            return new WP_Error( 'exists', __( 'User login or email already exists.', 'disciple-tools-migration' ) );
        }

        $norm_roles = self::normalized_roles_from_row( $row );
        if ( in_array( 'administrator', $norm_roles, true ) && ! current_user_can( 'promote_users' ) ) {
            return new WP_Error(
                'no_promote',
                __( 'You do not have permission to assign the administrator role when creating users.', 'disciple-tools-migration' )
            );
        }

        $password = wp_generate_password( 24, true, true );
        $user_id  = wp_insert_user(
            [
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => $password,
                'user_nicename' => isset( $row['user_nicename'] ) ? sanitize_title( (string) $row['user_nicename'] ) : $login,
                'display_name' => isset( $row['display_name'] ) ? sanitize_text_field( (string) $row['display_name'] ) : $login,
                'role'         => '',
            ]
        );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        $role_err = self::assign_roles_to_wp_user( (int) $user_id, $norm_roles );
        if ( $role_err !== null ) {
            return new WP_Error( 'role_assign_failed', $role_err );
        }

        if ( ! empty( $row['meta'] ) && is_array( $row['meta'] ) ) {
            foreach ( self::$allowed_meta_keys as $key ) {
                if ( isset( $row['meta'][ $key ] ) ) {
                    update_user_meta( $user_id, $key, wp_unslash( $row['meta'][ $key ] ) );
                }
            }
        }

        return (int) $user_id;
    }
}
