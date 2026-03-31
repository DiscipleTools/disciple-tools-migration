<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Disciple_Tools_Migration_Export_File
 *
 * Generates self-contained JSON export packages for Downloadable File mode.
 * Supports record limit and ID range for batched exports.
 */
class Disciple_Tools_Migration_Export_File {

    const EXPORT_VERSION = '1.0';

    /**
     * Builds a full export payload (settings + records) for file download.
     *
     * @param array $record_options Per-post-type options: [ 'contacts' => [ 'limit' => 50, 'min_id' => 1, 'max_id' => 500 ], ... ].
     * @return array Export structure for JSON encoding.
     */
    public static function build_export( array $record_options = [] ) : array {
        if ( ! class_exists( 'Disciple_Tools_Migration_Menu' ) || ! class_exists( 'DT_Posts' ) ) {
            return [ 'error' => __( 'Migration or DT_Posts not available.', 'disciple-tools-migration' ) ];
        }

        $settings = Disciple_Tools_Migration_Menu::get_settings();
        $allowed  = $settings['allowed_items'] ?? [];
        $site_meta = self::get_site_meta();

        $settings_export = self::build_settings_export( $allowed );

        $export_block = [
            'dt_settings' => $settings_export,
        ];
        if ( ! empty( $allowed['system_users'] ) && class_exists( 'Disciple_Tools_Migration_System_Users' ) ) {
            $export_block['system_users'] = Disciple_Tools_Migration_System_Users::build_export_payload();
        }

        $records = [];
        $allowed_records = $allowed['records'] ?? [];
        if ( ! empty( $allowed_records ) && is_array( $allowed_records ) ) {
            foreach ( $allowed_records as $post_type => $enabled ) {
                if ( ! $enabled ) {
                    continue;
                }
                $opts   = $record_options[ $post_type ] ?? [];
                $limit  = isset( $opts['limit'] ) ? absint( $opts['limit'] ) : 0;
                $min_id = isset( $opts['min_id'] ) ? absint( $opts['min_id'] ) : 0;
                $max_id = isset( $opts['max_id'] ) ? absint( $opts['max_id'] ) : 0;

                $records[ $post_type ] = self::fetch_records( $post_type, $limit, $min_id, $max_id );
            }
        }

        return [
            'version'  => self::EXPORT_VERSION,
            'type'     => 'file',
            'site_meta' => $site_meta,
            'settings' => [
                'enabled'       => ! empty( $settings['enabled'] ),
                'mode'          => 'file',
                'allowed_items' => $allowed,
            ],
            'export'   => $export_block,
            'records'  => $records,
        ];
    }

    /**
     * Fetches records for a post type with optional limit and ID range.
     * Always orders by ID ASC for deterministic batching.
     *
     * @param string $post_type
     * @param int    $limit  0 = no limit.
     * @param int    $min_id 0 = no minimum.
     * @param int    $max_id 0 = no maximum.
     * @return array
     */
    public static function fetch_records( string $post_type, int $limit = 0, int $min_id = 0, int $max_id = 0 ) : array {
        $ids = self::get_record_ids( $post_type, $limit, $min_id, $max_id );
        $records = [];
        foreach ( $ids as $post_id ) {
            $post = DT_Posts::get_post( $post_type, $post_id, true, false );
            if ( ! is_wp_error( $post ) && is_array( $post ) ) {
                if ( class_exists( 'Disciple_Tools_Migration_Import_Engine' ) ) {
                    $post = Disciple_Tools_Migration_Import_Engine::attach_migration_comments_to_record( $post_type, $post );
                }
                $records[] = $post;
            }
        }
        return $records;
    }

    /**
     * Gets post IDs for a post type with optional limit and ID range.
     * Always ORDER BY ID ASC.
     *
     * @param string $post_type
     * @param int    $limit
     * @param int    $min_id
     * @param int    $max_id
     * @return int[]
     */
    private static function get_record_ids( string $post_type, int $limit, int $min_id, int $max_id ) : array {
        global $wpdb;
        $sql  = "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash'";
        $args = [ $post_type ];
        if ( $min_id > 0 ) {
            $sql .= ' AND ID >= %d';
            $args[] = $min_id;
        }
        if ( $max_id > 0 ) {
            $sql .= ' AND ID <= %d';
            $args[] = $max_id;
        }
        $sql .= ' ORDER BY ID ASC';
        if ( $limit > 0 ) {
            $sql .= ' LIMIT %d';
            $args[] = $limit;
        }
        $prepared = call_user_func_array( [ $wpdb, 'prepare' ], array_merge( [ $sql ], $args ) );
        $ids      = $wpdb->get_col( $prepared );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Returns record stats (count, min_id, max_id) per post type.
     *
     * @return array<string, array{ count: int, min_id: int, max_id: int }>
     */
    public static function get_record_stats() : array {
        if ( ! class_exists( 'Disciple_Tools_Migration_Menu' ) ) {
            return [];
        }

        $settings = Disciple_Tools_Migration_Menu::get_settings();
        $allowed  = $settings['allowed_items']['records'] ?? [];
        if ( empty( $allowed ) || ! is_array( $allowed ) ) {
            return [];
        }

        global $wpdb;
        $stats = [];
        foreach ( $allowed as $post_type => $enabled ) {
            if ( ! $enabled ) {
                continue;
            }
            $pt = esc_sql( $post_type );
            $row = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT COUNT(*) as cnt, MIN(ID) as min_id, MAX(ID) as max_id FROM $wpdb->posts WHERE post_type = %s AND post_status != 'trash'",
                    $post_type
                ),
                ARRAY_A
            );
            $stats[ $post_type ] = [
                'count'  => (int) ( $row['cnt'] ?? 0 ),
                'min_id' => (int) ( $row['min_id'] ?? 0 ),
                'max_id' => (int) ( $row['max_id'] ?? 0 ),
            ];
        }
        return $stats;
    }

    /**
     * Builds settings export (mirrors REST API export logic).
     *
     * @param array $allowed
     * @return array
     */
    private static function build_settings_export( array $allowed ) : array {
        $settings_export = [
            'type'                          => 'file',
            'dt_tiles_settings'             => [ 'values' => [] ],
            'dt_tiles_custom_settings'      => [ 'values' => [] ],
            'dt_fields_settings'            => [ 'values' => [] ],
            'dt_fields_custom_settings'     => [ 'values' => [] ],
            'dt_post_types_settings'        => [ 'values' => [] ],
            'dt_post_types_custom_settings' => [ 'values' => [] ],
            'dt_workflows_post_types'       => [ 'values' => [] ],
            'dt_workflows_defaults'         => [ 'values' => [] ],
        ];

        if ( ! empty( $allowed['tiles'] ) || ! empty( $allowed['fields'] ) || ! empty( $allowed['records'] ) ) {
            $post_types = [];
            foreach ( DT_Posts::get_post_types() as $post_type ) {
                if ( ! isset( $post_types[ $post_type ] ) ) {
                    $post_type_settings = DT_Posts::get_post_settings( $post_type, false );
                    unset( $post_type_settings['tiles'], $post_type_settings['fields'] );
                    $post_types[ $post_type ] = $post_type_settings;
                }
            }
            $settings_export['dt_post_types_settings']['values']        = $post_types;
            $settings_export['dt_post_types_custom_settings']['values'] = get_option( 'dt_custom_post_types', [] );
        }

        if ( ! empty( $allowed['tiles'] ) ) {
            $tiles = [];
            foreach ( DT_Posts::get_post_types() as $post_type ) {
                if ( ! isset( $tiles[ $post_type ] ) ) {
                    $tiles[ $post_type ] = DT_Posts::get_post_tiles( $post_type, false );
                }
            }
            $settings_export['dt_tiles_settings']['values']        = $tiles;
            $settings_export['dt_tiles_custom_settings']['values'] = dt_get_option( 'dt_custom_tiles' );
        }

        if ( ! empty( $allowed['fields'] ) ) {
            $fields = [];
            foreach ( DT_Posts::get_post_types() as $post_type ) {
                if ( ! isset( $fields[ $post_type ] ) ) {
                    $fields[ $post_type ] = DT_Posts::get_post_field_settings( $post_type, false, true );
                }
            }
            $settings_export['dt_fields_settings']['values']        = $fields;
            $settings_export['dt_fields_custom_settings']['values'] = dt_get_option( 'dt_field_customizations' );
        }

        if ( ! empty( $allowed['workflows'] ) ) {
            $post_types_raw = get_option( 'dt_workflows_post_types', '' );
            $defaults_raw  = get_option( 'dt_workflows_defaults', '' );
            $settings_export['dt_workflows_post_types']['values'] = ! empty( $post_types_raw )
                ? json_decode( $post_types_raw, true )
                : [];
            $settings_export['dt_workflows_defaults']['values']   = ! empty( $defaults_raw )
                ? json_decode( $defaults_raw, true )
                : [];
        }

        return $settings_export;
    }

    /**
     * @return array
     */
    private static function get_site_meta() : array {
        $wp_theme = wp_get_theme();
        return [
            'timestamp'   => time(),
            'site_url'    => get_site_url(),
            'wp_version'  => get_bloginfo( 'version' ),
            'php_version' => phpversion(),
            'server'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
            'dt_version'  => $wp_theme->version,
            'multisite'   => is_multisite(),
        ];
    }
}
