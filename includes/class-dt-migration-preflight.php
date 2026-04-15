<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Non-destructive preflight checks before migration import (warnings only).
 *
 * Used by API and file flows; record-level checks may be based on a sample when
 * the source only provides paginated record batches.
 */
class Disciple_Tools_Migration_Preflight {

    /**
     * Keys on exported records that are not custom fields.
     *
     * @var string[]
     */
    private static $record_key_exclude = [
        'ID',
        'post_type',
        'post_date',
        'post_date_gmt',
        'permalink',
        'post_date_formatted',
        'post_author',
        'post_author_display_name',
        'dt_migration_comments',
        'name',
        'title',
        'post_status',
    ];

    /**
     * Batch size for ID IN (...) queries in post ID collision preflight (avoids huge single queries).
     *
     * @var int
     */
    private const POST_ID_COLLISION_QUERY_CHUNK = 500;

    /**
     * Runs preflight analysis and returns warning lines (translatable strings already resolved).
     *
     * @param array $args {
     *     @type array      $export            Export block (payload['export']).
     *     @type array      $records           Map of post_type => list of record arrays.
     *     @type bool       $records_sampled   True when $records do not cover the full export.
     *     @type array      $settings_selected Keys selected for import (e.g. [ 'system_users' => true ]).
     *     @type string[]   $records_selected  Post types selected for record import.
     * }
     * @return array{ warnings: string[], info: string[] }
     */
    public static function analyze( array $args ) : array {
        $export            = isset( $args['export'] ) && is_array( $args['export'] ) ? $args['export'] : [];
        $records           = isset( $args['records'] ) && is_array( $args['records'] ) ? $args['records'] : [];
        $records_sampled   = ! empty( $args['records_sampled'] );
        $settings_selected = isset( $args['settings_selected'] ) && is_array( $args['settings_selected'] ) ? $args['settings_selected'] : [];
        $records_selected  = isset( $args['records_selected'] ) && is_array( $args['records_selected'] ) ? $args['records_selected'] : [];

        $warnings = [];
        $info     = [];

        if ( is_multisite() && ! empty( $settings_selected['system_users'] ) ) {
            $info[] = __(
                'Multisite: user roles are stored per subsite. System user import matches existing accounts by email; a network Super Admin may not appear under Users on this site as an Administrator. If role updates fail, use Users → Add User to add them to this site as an Administrator first, or run the import as someone with promote_users capability.',
                'disciple-tools-migration'
            );
        }

        if ( $records_sampled ) {
            $info[] = __( 'Record checks are based on a sample from the source (first batches). Other records may differ.', 'disciple-tools-migration' );
        }

        if ( ! empty( $settings_selected['system_users'] ) ) {
            $warnings = array_merge( $warnings, self::warnings_system_users( $export ) );
        }

        $will_import_field_defs = ! empty( $settings_selected['fields'] );
        $add_post_type_sep      = false;
        foreach ( $records_selected as $post_type ) {
            $post_type = sanitize_key( (string) $post_type );
            if ( $post_type === '' ) {
                continue;
            }
            $batch = isset( $records[ $post_type ] ) && is_array( $records[ $post_type ] ) ? $records[ $post_type ] : [];

            $chunk = array_merge(
                self::warnings_unknown_fields( $export, $post_type, $batch, $will_import_field_defs ),
                self::warnings_post_id_collisions( $post_type, $batch )
            );

            if ( ! empty( $chunk ) ) {
                if ( $add_post_type_sep ) {
                    $warnings[] = '----------';
                }
                $warnings = array_merge( $warnings, $chunk );
                $add_post_type_sep = true;
            }
        }

        return [
            'warnings' => self::dedupe_preflight_lines( $warnings ),
            'info'     => array_values( array_unique( array_filter( $info ) ) ),
        ];
    }

    /**
     * @param string[] $lines
     * @return string[]
     */
    private static function dedupe_preflight_lines( array $lines ) : array {
        $out  = [];
        $prev = null;
        foreach ( $lines as $line ) {
            if ( ! is_string( $line ) ) {
                continue;
            }
            if ( $line === '----------' && $prev === '----------' ) {
                continue;
            }
            $out[] = $line;
            $prev  = $line;
        }
        return $out;
    }

    /**
     * @param array $export Export block.
     * @return string[]
     */
    private static function warnings_system_users( array $export ) : array {
        $out  = [];
        $sys  = $export['system_users'] ?? null;
        $rows = ( is_array( $sys ) && isset( $sys['users'] ) && is_array( $sys['users'] ) ) ? $sys['users'] : [];
        if ( empty( $rows ) ) {
            return $out;
        }

        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $old_id = isset( $row['id'] ) ? (int) $row['id'] : 0;
            if ( $old_id <= 0 ) {
                continue;
            }
            if ( ! Disciple_Tools_Migration_System_Users::is_migration_admin_user( $row ) ) {
                continue;
            }

            $email = isset( $row['user_email'] ) ? sanitize_email( (string) $row['user_email'] ) : '';
            $login = isset( $row['user_login'] ) ? sanitize_user( (string) $row['user_login'], true ) : '';

            $existing = Disciple_Tools_Migration_System_Users::find_existing_user( $email, $login );
            if ( ! $existing instanceof WP_User ) {
                continue;
            }

            if ( self::user_is_effectively_site_admin( (int) $existing->ID ) ) {
                continue;
            }

            $out[] = sprintf(
                /* translators: 1: source user ID, 2: target user ID, 3: user login */
                __( 'Source administrator (exported user ID %1$d) matches existing user ID %2$d (%3$s), who is not an Administrator on this site. User import will try to apply exported roles; ensure your account can promote users, or assign Administrator on this site before importing.', 'disciple-tools-migration' ),
                $old_id,
                (int) $existing->ID,
                $existing->user_login
            );
        }

        return $out;
    }

    /**
     * @param int $user_id WordPress user ID.
     */
    private static function user_is_effectively_site_admin( int $user_id ) : bool {
        if ( $user_id <= 0 ) {
            return false;
        }
        if ( is_multisite() && is_super_admin( $user_id ) ) {
            return true;
        }
        $user = new WP_User( $user_id );
        return in_array( 'administrator', (array) $user->roles, true );
    }

    /**
     * @param array  $export             Export block.
     * @param string $post_type         Post type.
     * @param array  $records           Records to scan.
     * @param bool   $will_import_fields Whether field definitions from export will be imported.
     * @return string[]
     */
    private static function warnings_unknown_fields( array $export, string $post_type, array $records, bool $will_import_fields ) : array {
        if ( empty( $records ) || ! class_exists( 'DT_Posts' ) ) {
            return [];
        }

        try {
            $post_settings = DT_Posts::get_post_settings( $post_type, false );
            $target_fields = isset( $post_settings['fields'] ) && is_array( $post_settings['fields'] ) ? $post_settings['fields'] : [];
        } catch ( Throwable $e ) {
            return [];
        }

        $target_keys = array_keys( $target_fields );

        if ( $will_import_fields ) {
            $dt         = $export['dt_settings'] ?? [];
            $exported   = $dt['dt_fields_settings']['values'][ $post_type ] ?? [];
            $exported   = is_array( $exported ) ? $exported : [];
            $merge_keys = array_keys( $exported );
            $target_keys = array_values( array_unique( array_merge( $target_keys, $merge_keys ) ) );
        }

        $target_lookup = array_fill_keys( $target_keys, true );

        $unknown_with_count = [];
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            foreach ( array_keys( $record ) as $key ) {
                if ( ! is_string( $key ) ) {
                    continue;
                }
                if ( in_array( $key, self::$record_key_exclude, true ) ) {
                    continue;
                }
                if ( isset( $target_lookup[ $key ] ) ) {
                    continue;
                }
                if ( ! isset( $unknown_with_count[ $key ] ) ) {
                    $unknown_with_count[ $key ] = 0;
                }
                ++$unknown_with_count[ $key ];
            }
        }

        if ( empty( $unknown_with_count ) ) {
            return [];
        }

        ksort( $unknown_with_count );

        $out   = [];
        $out[] = sprintf(
            /* translators: %s: post type slug */
            __( '%s: unknown field keys in sampled records (not defined on this site for your selected import options). One field per line:', 'disciple-tools-migration' ),
            $post_type
        );
        foreach ( $unknown_with_count as $field_key => $count ) {
            $out[] = sprintf(
                /* translators: 1: field key, 2: number of sampled records. Leading spaces align under the header. */
                __( '  - %1$s (in %2$d sampled record(s))', 'disciple-tools-migration' ),
                $field_key,
                $count
            );
        }
        $out[] = sprintf(
            /* translators: %s: post type slug */
            __( '%s: values for those keys may fail validation or be skipped until field definitions match the source.', 'disciple-tools-migration' ),
            $post_type
        );

        return $out;
    }

    /**
     * Load post_type from wp_posts for the given IDs (chunked queries).
     *
     * @param int[] $post_ids Positive integers (typically unique).
     * @return array<int, string> Post ID => post_type for rows that exist.
     */
    private static function lookup_post_types_by_id( array $post_ids ) : array {
        global $wpdb;

        $post_ids = array_values(
            array_filter(
                array_map( 'intval', $post_ids ),
                static function ( $id ) {
                    return $id > 0;
                }
            )
        );
        if ( empty( $post_ids ) ) {
            return [];
        }

        $out   = [];
        $chunk = self::POST_ID_COLLISION_QUERY_CHUNK;
        $total = count( $post_ids );
        for ( $offset = 0; $offset < $total; $offset += $chunk ) {
            $slice = array_slice( $post_ids, $offset, $chunk );
            if ( empty( $slice ) ) {
                continue;
            }
            $placeholders = implode( ',', array_fill( 0, count( $slice ), '%d' ) );
            $sql            = $wpdb->prepare(
                'SELECT ID, post_type FROM ' . $wpdb->posts . ' WHERE ID IN (' . $placeholders . ')',
                ...$slice
            );
            $rows = $wpdb->get_results( $sql, ARRAY_A );
            if ( ! is_array( $rows ) ) {
                continue;
            }
            foreach ( $rows as $row ) {
                if ( ! isset( $row['ID'], $row['post_type'] ) ) {
                    continue;
                }
                $out[ (int) $row['ID'] ] = (string) $row['post_type'];
            }
        }

        return $out;
    }

    /**
     * @param string $post_type Post type.
     * @param array  $records   Records to scan.
     * @return string[]
     */
    private static function warnings_post_id_collisions( string $post_type, array $records ) : array {
        if ( empty( $records ) ) {
            return [];
        }

        $seen          = [];
        $candidate_ids = [];
        foreach ( $records as $record ) {
            if ( ! is_array( $record ) || empty( $record['ID'] ) ) {
                continue;
            }
            $pid = (int) $record['ID'];
            if ( $pid <= 0 ) {
                continue;
            }
            if ( isset( $seen[ $pid ] ) ) {
                continue;
            }
            $seen[ $pid ] = true;
            $candidate_ids[] = $pid;
        }

        if ( empty( $candidate_ids ) ) {
            return [];
        }

        $type_by_id = self::lookup_post_types_by_id( $candidate_ids );

        $collisions = [];
        foreach ( $candidate_ids as $pid ) {
            if ( ! isset( $type_by_id[ $pid ] ) ) {
                continue;
            }
            if ( $type_by_id[ $pid ] === $post_type ) {
                continue;
            }
            $collisions[] = $pid;
        }

        $collisions = array_values( array_unique( $collisions ) );
        if ( empty( $collisions ) ) {
            return [];
        }

        $slice = array_slice( $collisions, 0, 15 );
        $list  = implode( ', ', $slice );
        if ( count( $collisions ) > 15 ) {
            $list .= sprintf(
                /* translators: %d: number of additional IDs */
                __( ' …and %d more', 'disciple-tools-migration' ),
                count( $collisions ) - 15
            );
        }

        return [
            sprintf(
                /* translators: 1: post type, 2: list of post IDs */
                __( '%1$s: these post IDs already exist on this site with a different post type (import may not preserve ID): %2$s', 'disciple-tools-migration' ),
                $post_type,
                $list
            ),
        ];
    }
}
