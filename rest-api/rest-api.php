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
            'mode'               => $settings['mode'] ?? 'api',
            'allowed_items'      => $settings['allowed_items'] ?? [],
            'site_meta'          => $this->get_site_meta(),
            'plugin_capabilities' => [
                'supports_api_mode'  => true,
                'supports_file_mode' => true,
                'supports_records'   => [
                    'contacts' => ! empty( $settings['allowed_items']['records']['contacts'] ),
                    'groups'   => ! empty( $settings['allowed_items']['records']['groups'] ),
                ],
            ],
        ];

        return new WP_REST_Response( $response, 200 );
    }

    /**
     * Placeholder export endpoint.
     *
     * Accepts a JSON body describing requested sections and echoes back a preview payload,
     * without touching or exporting any real data yet.
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

        $settings = Disciple_Tools_Migration_Menu::get_settings();

        $response = [
            'site_meta' => $this->get_site_meta(),
            'settings'  => [
                'enabled'       => ! empty( $settings['enabled'] ),
                'mode'          => $settings['mode'] ?? 'api',
                'allowed_items' => $settings['allowed_items'] ?? [],
            ],
            'request'   => $body,
            'note'      => __( 'This is a non-destructive preview response. Actual export payloads will be implemented in a later phase.', 'disciple-tools-migration' ),
        ];

        return new WP_REST_Response( $response, 200 );
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
