<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_Migration_Endpoints
 *
 * Initial REST API surface for the migration plugin.
 * This phase focuses on non-destructive "capabilities" and "export" previews only.
 */
class Disciple_Tools_Migration_Endpoints {
    /**
     * Required capabilities for accessing migration REST endpoints from within WordPress.
     *
     * @var string[]
     */
    public $permissions = [ 'manage_dt' ];

    /**
     * Registers REST routes for the migration API.
     *
     * @return void
     */
    public function add_api_routes() {
        $namespace = 'dt-migration/v1';

        register_rest_route(
            $namespace,
            '/capabilities',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'capabilities' ],
                'permission_callback' => function ( WP_REST_Request $request ) {
                    return $this->has_permission( $request );
                },
            ]
        );

        register_rest_route(
            $namespace,
            '/export',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'export' ],
                'permission_callback' => function ( WP_REST_Request $request ) {
                    return $this->has_permission( $request );
                },
            ]
        );

        register_rest_route(
            $namespace,
            '/records-preview',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'records_preview' ],
                'permission_callback' => function ( WP_REST_Request $request ) {
                    return $this->has_permission( $request );
                },
            ]
        );

        register_rest_route(
            $namespace,
            '/records/(?P<post_type>[a-zA-Z0-9_-]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'records_batch' ],
                'permission_callback' => function ( WP_REST_Request $request ) {
                    return $this->has_permission( $request );
                },
                'args'                => [
                    'post_type' => [
                        'required'          => true,
                        'type'              => 'string',
                        'validate_callback' => function ( $param ) {
                            return is_string( $param ) && in_array( $param, array_keys( $this->get_allowed_record_types() ), true );
                        },
                    ],
                    'offset'    => [
                        'default'           => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'limit'    => [
                        'default'           => 50,
                        'sanitize_callback' => function ( $param ) {
                            $val = absint( $param );
                            return min( max( 1, $val ), 100 );
                        },
                    ],
                ],
            ]
        );
    }

    /**
     * Returns a summary of what this site is configured to export.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function capabilities( WP_REST_Request $request ) : WP_REST_Response {
        if ( ! class_exists( 'Disciple_Tools_Migration_Menu' ) ) {
            return new WP_REST_Response(
                [
                    'enabled'   => false,
                    'message'   => __( 'Migration admin menu is not available on this site.', 'disciple-tools-migration' ),
                    'site_meta' => $this->get_site_meta(),
                ],
                200
            );
        }

        $settings = Disciple_Tools_Migration_Menu::get_settings();

        $response = [
            'enabled'            => ! empty( $settings['enabled'] ),
            // Both API and file export/import are always available; legacy stored settings may still contain mode.
            'mode'               => 'both',
            'include_activity_log' => ! empty( $settings['include_activity_log'] ),
            'allowed_items'      => $settings['allowed_items'] ?? [],
            'site_meta'          => $this->get_site_meta(),
            'plugin_capabilities' => [
                'supports_api'          => true,
                'supports_file_download' => true,
                'supports_api_mode'     => true,
                'supports_file_mode'    => true,
                'supports_records'      => $settings['allowed_items']['records'] ?? [],
            ],
        ];

        return new WP_REST_Response( $response, 200 );
    }

    /**
     * Settings export endpoint (non-destructive).
     *
     * Builds a settings-only export payload similar to the downloadable exports,
     * respecting the migration settings flags. Records are not included yet.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function export( WP_REST_Request $request ) : WP_REST_Response {
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            $body = [];
        }

        if ( ! class_exists( 'Disciple_Tools_Migration_Menu' ) ) {
            return new WP_REST_Response(
                [
                    'message'   => __( 'Migration admin menu is not available on this site.', 'disciple-tools-migration' ),
                    'site_meta' => $this->get_site_meta(),
                    'request'   => $body,
                ],
                200
            );
        }

        $settings        = Disciple_Tools_Migration_Menu::get_settings();
        $allowed         = $settings['allowed_items'] ?? [];
        $site_meta       = $this->get_site_meta();
        $settings_export = [
            'type'                          => 'api',
            'dt_tiles_settings'             => [ 'values' => [] ],
            'dt_tiles_custom_settings'      => [ 'values' => [] ],
            'dt_fields_settings'            => [ 'values' => [] ],
            'dt_fields_custom_settings'     => [ 'values' => [] ],
            'dt_post_types_settings'        => [ 'values' => [] ],
            'dt_post_types_custom_settings' => [ 'values' => [] ],
            'dt_workflows_post_types'       => [ 'values' => [] ],
            'dt_workflows_defaults'         => [ 'values' => [] ],
        ];

        // Base post types configuration (without tiles/fields) when any structure-related export is enabled.
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

        $export_out = [
            'dt_settings' => $settings_export,
        ];
        if ( ! empty( $allowed['system_users'] ) && class_exists( 'Disciple_Tools_Migration_System_Users' ) ) {
            $export_out['system_users'] = Disciple_Tools_Migration_System_Users::build_export_payload();
        }

        $response = [
            'site_meta' => $site_meta,
            'settings'  => [
                'enabled'               => ! empty( $settings['enabled'] ),
                'include_activity_log'  => ! empty( $settings['include_activity_log'] ),
                'mode'                  => 'both',
                'allowed_items'         => $allowed,
            ],
            'export'    => $export_out,
            'request'   => $body,
            'note'      => __( 'This is a non-destructive settings export payload. Records are not included yet and nothing is applied automatically.', 'disciple-tools-migration' ),
        ];

        return new WP_REST_Response( $response, 200 );
    }

    /**
     * Records preview endpoint (non-destructive).
     *
     * Returns record counts for each enabled record post type.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function records_preview( WP_REST_Request $request ) : WP_REST_Response {
        if ( ! class_exists( 'Disciple_Tools_Migration_Menu' ) ) {
            return new WP_REST_Response(
                [
                    'message'   => __( 'Migration admin menu is not available on this site.', 'disciple-tools-migration' ),
                    'site_meta' => $this->get_site_meta(),
                ],
                200
            );
        }

        $settings = Disciple_Tools_Migration_Menu::get_settings();
        $allowed  = $settings['allowed_items']['records'] ?? [];

        $records = [];

        if ( ! empty( $allowed ) && is_array( $allowed ) ) {
            foreach ( $allowed as $post_type => $enabled ) {
                if ( ! $enabled ) {
                    continue;
                }

                // Count posts for this post type (non-destructive).
                $query = new WP_Query(
                    [
                        'post_type'      => $post_type,
                        'post_status'    => 'any',
                        'posts_per_page' => 1,
                        'fields'         => 'ids',
                    ]
                );

                $records[ $post_type ] = [
                    'count' => (int) ( $query->found_posts ?? 0 ),
                ];
            }
        }

        if ( ! empty( $settings['include_activity_log'] ) && ! empty( $records ) ) {
            $activity_totals = $this->get_activity_log_counts_for_post_types( array_keys( $records ) );
            foreach ( $records as $post_type => &$data ) {
                $data['activity_log_count'] = (int) ( $activity_totals[ $post_type ] ?? 0 );
            }
            unset( $data );
        }

        $response = [
            'site_meta' => $this->get_site_meta(),
            'records'   => $records,
            'note'      => __( 'Non-destructive records preview; counts only.', 'disciple-tools-migration' ),
        ];

        return new WP_REST_Response( $response, 200 );
    }

    /**
     * Counts wp_dt_activity_log rows per object_type for the given post types.
     *
     * @param string[] $post_types
     * @return array<string, int>
     */
    protected function get_activity_log_counts_for_post_types( array $post_types ) : array {
        $post_types = array_values(
            array_unique(
                array_filter(
                    array_map(
                        static function ( $pt ) {
                            return is_string( $pt ) ? sanitize_key( $pt ) : '';
                        },
                        $post_types
                    )
                )
            )
        );
        if ( empty( $post_types ) ) {
            return [];
        }

        global $wpdb;
        $table        = isset( $wpdb->dt_activity_log ) ? $wpdb->dt_activity_log : $wpdb->prefix . 'dt_activity_log';
        $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT object_type, COUNT(*) AS cnt FROM {$table} WHERE object_type IN ( $placeholders ) GROUP BY object_type",
                $post_types
            ),
            ARRAY_A
        );
        // phpcs:enable

        if ( ! is_array( $rows ) ) {
            return [];
        }

        $out = [];
        foreach ( $rows as $row ) {
            if ( ! empty( $row['object_type'] ) ) {
                $out[ (string) $row['object_type'] ] = (int) ( $row['cnt'] ?? 0 );
            }
        }

        return $out;
    }

    /**
     * Returns post types allowed for record export based on migration settings.
     *
     * @return array<string, bool>
     */
    protected function get_allowed_record_types() : array {
        if ( ! class_exists( 'Disciple_Tools_Migration_Menu' ) ) {
            return [];
        }
        $settings = Disciple_Tools_Migration_Menu::get_settings();
        $allowed  = $settings['allowed_items']['records'] ?? [];

        return is_array( $allowed ) ? $allowed : [];
    }

    /**
     * Records batch endpoint (Server A).
     *
     * Returns a batch of full record data for the given post type.
     * Used by Server B during import to pull records in chunks.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function records_batch( WP_REST_Request $request ) : WP_REST_Response {
        $post_type = $request->get_param( 'post_type' );
        $offset    = (int) $request->get_param( 'offset' );
        $limit    = (int) $request->get_param( 'limit' );

        $allowed = $this->get_allowed_record_types();
        if ( empty( $allowed[ $post_type ] ) ) {
            return new WP_REST_Response(
                [
                    'message'  => __( 'Post type not allowed for migration.', 'disciple-tools-migration' ),
                    'records'   => [],
                    'total'     => 0,
                    'offset'    => $offset,
                    'limit'     => $limit,
                    'has_more'  => false,
                ],
                403
            );
        }

        if ( ! class_exists( 'DT_Posts' ) ) {
            return new WP_REST_Response(
                [ 'message' => __( 'DT_Posts not available.', 'disciple-tools-migration' ), 'records' => [] ],
                500
            );
        }

        $query = [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ];

        $wp_query = new WP_Query( $query );
        $ids      = $wp_query->posts ?? [];
        $total    = (int) ( $wp_query->found_posts ?? 0 );

        $records         = [];
        $original_user_id = get_current_user_id();
        wp_set_current_user( 0 );
        try {
            foreach ( $ids as $post_id ) {
                $post = DT_Posts::get_post( $post_type, (int) $post_id, false, false );
                if ( ! is_wp_error( $post ) && is_array( $post ) ) {
                    if ( class_exists( 'Disciple_Tools_Migration_Import_Engine' ) ) {
                        $post = Disciple_Tools_Migration_Import_Engine::attach_migration_comments_to_record( $post_type, $post );
                    }
                    $records[] = $post;
                }
            }
        } finally {
            wp_set_current_user( $original_user_id );
        }

        $post_user_meta = class_exists( 'Disciple_Tools_Migration_Export_File' )
            ? Disciple_Tools_Migration_Export_File::fetch_post_user_meta( array_map( 'intval', $ids ) )
            : [];

        $settings = class_exists( 'Disciple_Tools_Migration_Menu' ) ? Disciple_Tools_Migration_Menu::get_settings() : [];
        $activity_log = [];
        if ( ! empty( $settings['include_activity_log'] ) && class_exists( 'Disciple_Tools_Migration_Export_File' ) ) {
            $activity_log = Disciple_Tools_Migration_Export_File::fetch_activity_log_for_posts( $post_type, array_map( 'intval', $ids ) );
        }

        return new WP_REST_Response(
            [
                'records'         => $records,
                'post_user_meta'  => $post_user_meta,
                'activity_log'    => $activity_log,
                'total'           => $total,
                'offset'          => $offset,
                'limit'           => $limit,
                'has_more'        => ( $offset + count( $records ) ) < $total,
            ],
            200
        );
    }

    /**
     * Builds a meta summary of the current site.
     *
     * @return array
     */
    protected function get_site_meta() : array {
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

    /**
     * Permission callback for REST routes.
     *
     * For now this only allows authenticated users with appropriate capabilities.
     * Site-to-site token-based access will be layered on in a later phase.
     *
     * @param WP_REST_Request $request
     *
     * @return bool
     */
    public function has_permission( WP_REST_Request $request ) : bool {
        $pass = false;

        foreach ( $this->permissions as $permission ) {
            if ( current_user_can( $permission ) ) {
                $pass = true;
                break;
            }
        }

        return $pass;
    }

    /**
     * Singleton instance.
     *
     * @var Disciple_Tools_Migration_Endpoints|null
     */
    private static $_instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return Disciple_Tools_Migration_Endpoints
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
    }
}

Disciple_Tools_Migration_Endpoints::instance();
