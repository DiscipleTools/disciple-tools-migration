<?php
/**
 * Plugin Name: Disciple.Tools - Migrations
 * Plugin URI: https://github.com/DiscipleTools/disciple-tools-migration
 * Description: Export and import Disciple.Tools settings and records via REST API or downloadable JSON.
 * Text Domain: disciple-tools-migration
 * Domain Path: /languages
 * Version: 1.0.0
 * Author URI: https://github.com/DiscipleTools
 * GitHub Plugin URI: https://github.com/DiscipleTools/disciple-tools-migration
 * Requires at least: 4.7.0
 * Tested up to: 6.7
 *
 * @package Disciple_Tools
 * @link    https://github.com/DiscipleTools
 * @license GPL-2.0 or later
 *          https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Gets the instance of the Disciple_Tools_Migration_Plugin class.
 *
 * @since 1.0.0
 * @return object|bool
 */
function disciple_tools_migration() {
    global $disciple_tools_migration_required_dt_theme_version;

    $disciple_tools_migration_required_dt_theme_version = '1.20';
    $wp_theme = wp_get_theme();
    $version  = $wp_theme->version;

    $is_theme_dt = class_exists( 'Disciple_Tools' );
    if ( $is_theme_dt && version_compare( $version, $disciple_tools_migration_required_dt_theme_version, '<' ) ) {
        add_action( 'admin_notices', 'disciple_tools_migration_hook_admin_notice' );
        add_action( 'wp_ajax_dismissed_notice_handler', 'dt_hook_ajax_notice_handler' );
        return false;
    }
    if ( ! $is_theme_dt ) {
        return false;
    }

    if ( ! defined( 'DT_FUNCTIONS_READY' ) ) {
        require_once get_template_directory() . '/dt-core/global-functions.php';
    }

    return Disciple_Tools_Migration_Plugin::instance();
}
add_action( 'after_setup_theme', 'disciple_tools_migration', 20 );

add_filter(
    'dt_plugins',
    function ( $plugins ) {
        $plugin_data = get_file_data( __FILE__, [ 'Version' => 'Version', 'Plugin Name' => 'Plugin Name' ], false );
        $plugins['disciple-tools-migration'] = [
            'plugin_url' => trailingslashit( plugin_dir_url( __FILE__ ) ),
            'version'    => $plugin_data['Version'] ?? null,
            'name'       => $plugin_data['Plugin Name'] ?? null,
        ];
        return $plugins;
    }
);

/**
 * Singleton class for setting up the plugin.
 *
 * @since 1.0.0
 */
class Disciple_Tools_Migration_Plugin {

    /**
     * Instance.
     *
     * @var Disciple_Tools_Migration_Plugin|null
     */
    private static $_instance = null;

    /**
     * @return Disciple_Tools_Migration_Plugin
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    private function __construct() {
        $is_rest = dt_is_rest();

        if ( $is_rest ) {
            require_once 'rest-api/rest-api.php';
        }

        if ( is_admin() || $is_rest ) {
            require_once 'admin/admin-menu-and-tabs.php';
        }

        if ( is_admin() || $is_rest ) {
            require_once 'includes/class-dt-migration-system-users.php';
            require_once 'includes/class-dt-migration-import-engine.php';
            require_once 'includes/class-dt-migration-preflight.php';
        }

        if ( is_admin() ) {
            require_once 'includes/class-dt-migration-export-file.php';
            require_once 'admin/class-dt-migration-export-download.php';
            new Disciple_Tools_Migration_Export_Download();
        }

        if ( is_admin() ) {
            require_once 'admin/class-dt-migration-import-ajax.php';
            new Disciple_Tools_Migration_Import_Ajax();
        }

        $this->i18n();

        if ( is_admin() ) {
            add_filter( 'plugin_row_meta', [ $this, 'plugin_description_links' ], 10, 4 );
        }
    }

    /**
     * Appends links below the plugin on the Plugins list table.
     *
     * @param string[] $links_array    Row meta.
     * @param string   $plugin_file_name Plugin file.
     * @param array    $plugin_data    Plugin data.
     * @param string   $status         Status.
     * @return string[]
     */
    public function plugin_description_links( $links_array, $plugin_file_name, $plugin_data, $status ) {
        if ( strpos( $plugin_file_name, basename( __FILE__ ) ) !== false ) {
            $links_array[] = '<a href="https://disciple.tools">' . esc_html__( 'Disciple.Tools Community', 'disciple-tools-migration' ) . '</a>';
        }
        return $links_array;
    }

    /**
     * Runs when the plugin is activated.
     */
    public static function activation() {
    }

    /**
     * Runs when the plugin is deactivated.
     */
    public static function deactivation() {
        delete_option( 'dismissed-disciple-tools-migration' );
    }

    /**
     * Loads translation files.
     */
    public function i18n() {
        load_plugin_textdomain( 'disciple-tools-migration', false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) . 'languages' );
    }

    /**
     * @return string
     */
    public function __toString() {
        return 'disciple-tools-migration';
    }

    public function __clone() {
        _doing_it_wrong( __FUNCTION__, 'Cloning is forbidden.', '1.0.0' );
    }

    public function __wakeup() {
        _doing_it_wrong( __FUNCTION__, 'Unserializing instances is forbidden.', '1.0.0' );
    }

    /**
     * @param string $method Method name.
     * @param array  $args   Arguments.
     * @return null
     */
    public function __call( $method = '', $args = array() ) {
        _doing_it_wrong( 'disciple_tools_migration::' . esc_html( $method ), 'Method does not exist.', '1.0.0' );
        unset( $method, $args );
        return null;
    }
}

register_activation_hook( __FILE__, [ 'Disciple_Tools_Migration_Plugin', 'activation' ] );
register_deactivation_hook( __FILE__, [ 'Disciple_Tools_Migration_Plugin', 'deactivation' ] );


if ( ! function_exists( 'disciple_tools_migration_hook_admin_notice' ) ) {
    /**
     * Admin notice when theme is missing or outdated.
     */
    function disciple_tools_migration_hook_admin_notice() {
        global $disciple_tools_migration_required_dt_theme_version;

        $wp_theme        = wp_get_theme();
        $current_version = $wp_theme->version;
        $message         = __( '\'Disciple.Tools - Migrations\' requires the Disciple.Tools theme. Please activate Disciple.Tools or update it to a supported version.', 'disciple-tools-migration' );
        if ( $wp_theme->get_template() === 'disciple-tools-theme' ) {
            $message .= ' ' . sprintf(
                /* translators: 1: current theme version, 2: required version */
                __( 'Current Disciple.Tools version: %1$s, required: %2$s', 'disciple-tools-migration' ),
                esc_html( $current_version ),
                esc_html( $disciple_tools_migration_required_dt_theme_version )
            );
        }
        if ( ! get_option( 'dismissed-disciple-tools-migration', false ) ) {
            ?>
            <div class="notice notice-error notice-disciple-tools-migration is-dismissible" data-notice="disciple-tools-migration">
                <p><?php echo esc_html( $message ); ?></p>
            </div>
            <script>
                jQuery(function($) {
                    $( document ).on( 'click', '.notice-disciple-tools-migration .notice-dismiss', function () {
                        $.ajax( ajaxurl, {
                            type: 'POST',
                            data: {
                                action: 'dismissed_notice_handler',
                                type: 'disciple-tools-migration',
                                security: '<?php echo esc_html( wp_create_nonce( 'wp_rest_dismiss' ) ); ?>'
                            }
                        });
                    });
                });
            </script>
            <?php
        }
    }
}

if ( ! function_exists( 'dt_hook_ajax_notice_handler' ) ) {
    /**
     * AJAX: persist dismissed admin notices.
     */
    function dt_hook_ajax_notice_handler() {
        check_ajax_referer( 'wp_rest_dismiss', 'security' );
        if ( isset( $_POST['type'] ) ) {
            $type = sanitize_text_field( wp_unslash( $_POST['type'] ) );
            update_option( 'dismissed-' . $type, true );
        }
    }
}

/**
 * Remote updates via Plugin Update Checker (bundled with Disciple.Tools theme).
 *
 * @see https://github.com/DiscipleTools/disciple-tools-version-control/wiki/How-to-Update-the-Starter-Plugin
 */
add_action(
    'plugins_loaded',
    function () {
        if ( ( is_admin() && ! ( is_multisite() && class_exists( 'DT_Multisite' ) ) ) || wp_doing_cron() ) {
            if ( ! class_exists( 'Puc_v4_Factory' ) ) {
                $puc = get_template_directory() . '/dt-core/libraries/plugin-update-checker/plugin-update-checker.php';
                if ( file_exists( $puc ) ) {
                    require $puc;
                }
            }
            if ( class_exists( 'Puc_v4_Factory' ) ) {
                Puc_v4_Factory::buildUpdateChecker(
                    'https://raw.githubusercontent.com/DiscipleTools/disciple-tools-migration/master/version-control.json',
                    __FILE__,
                    'disciple-tools-migration'
                );
            }
        }
    }
);
