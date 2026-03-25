<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * System user export/import for migrations (Phase 1).
 *
 * Exports safe user fields (no passwords). On import, maps source user IDs to target IDs.
 * Administrator users from the source are never created; they must already exist on the
 * target (matched by email, then login) and must be administrators there.
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
     * @return array{ error?: string, map: array<string, int>, created: int, mapped_existing: int, admin_mapped: int }
     */
    public static function apply_import( array $system_users ) : array {
        $out = [
            'map'             => [],
            'created'         => 0,
            'mapped_existing' => 0,
            'admin_mapped'    => 0,
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

            if ( self::is_migration_admin_user( $row ) ) {
                $existing = self::find_existing_user( $email, $login );
                if ( ! $existing instanceof WP_User ) {
                    return array_merge( $out, [
                        'error' => sprintf(
                            /* translators: 1: old user ID, 2: email or login */
                            __( 'Administrator user from source (old ID %1$d, %2$s) must already exist on this site. Create the account first or fix email/login match.', 'disciple-tools-migration' ),
                            $old_id,
                            $email !== '' ? $email : $login
                        ),
                    ] );
                }
                if ( ! in_array( 'administrator', (array) $existing->roles, true ) ) {
                    return array_merge( $out, [
                        'error' => sprintf(
                            /* translators: 1: existing user ID */
                            __( 'Matched user ID %1$d for a source administrator must be an administrator on this site.', 'disciple-tools-migration' ),
                            (int) $existing->ID
                        ),
                    ] );
                }
                $out['map'][ (string) $old_id ] = (int) $existing->ID;
                ++$out['admin_mapped'];
                continue;
            }

            $existing = self::find_existing_user( $email, $login );
            if ( $existing instanceof WP_User ) {
                $out['map'][ (string) $old_id ] = (int) $existing->ID;
                ++$out['mapped_existing'];
                self::maybe_update_user_profile( $existing->ID, $row );
                continue;
            }

            if ( ! current_user_can( 'create_users' ) ) {
                return array_merge( $out, [
                    'error' => sprintf(
                        /* translators: 1: old user ID */
                        __( 'Cannot create missing non-admin user (source ID %1$d): create_users capability required.', 'disciple-tools-migration' ),
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

        $roles = isset( $row['roles'] ) && is_array( $row['roles'] ) ? $row['roles'] : [];
        $roles = array_values( array_filter( array_map( 'sanitize_key', $roles ) ) );
        $user  = new WP_User( $user_id );
        $user->set_role( '' );
        if ( ! empty( $roles ) ) {
            $primary = array_shift( $roles );
            $user->set_role( $primary );
            foreach ( $roles as $extra_role ) {
                $user->add_role( $extra_role );
            }
        } else {
            $user->set_role( get_option( 'default_role', 'subscriber' ) );
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
