<?php
if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly

require_once __DIR__ . '/class-dt-migration-tab-settings.php';
require_once __DIR__ . '/class-dt-migration-tab-export.php';
require_once __DIR__ . '/class-dt-migration-tab-import.php';

/**
 * Class Disciple_Tools_Migration_Menu
 *
 * Top-level admin menu for the Migration plugin with Settings, Export and Import tabs.
 */
class Disciple_Tools_Migration_Menu {

    /**
     * Slug used for the admin page and option storage prefix.
     *
     * @var string
     */
    public $token = 'disciple_tools_migration';

    /**
     * Human readable page title.
     *
     * @var string
     */
    public $page_title = 'Migration';

    /**
     * Singleton instance.
     *
     * @var Disciple_Tools_Migration_Menu|null
     */
    private static $_instance = null;

    /**
     * Disciple_Tools_Migration_Menu Instance.
     *
     * Ensures only one instance of Disciple_Tools_Migration_Menu is loaded or can be loaded.
     *
     * @since 0.1.0
     * @static
     * @return Disciple_Tools_Migration_Menu
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    /**
     * Constructor.
     *
     * @since 0.1.0
     */
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        $this->page_title = __( 'Migration', 'disciple-tools-migration' );
    } // End __construct()

    /**
     * Returns the current migration settings from the options table.
     *
     * @return array
     */
    public static function get_settings(): array {
        $defaults = [
            'enabled'       => false,
            // Legacy option; UI no longer exposes a single "mode". Capabilities API reports both channels.
            'mode'          => 'api',
            'allowed_items' => [
                'general_settings' => true,
                'custom_lists'     => true,
                'tiles'            => true,
                'fields'           => true,
                'roles'            => true,
                'workflows'        => true,
                'system_users'     => true,
                'records'          => [
                    'contacts' => true,
                    'groups'   => true,
                ],
            ],
            'api'           => [
                'connection_type'  => 'site_link',
                'site_link_id'     => 0,
                'remote_base_url'  => '',
                'auth_token'       => '',
                'jwt_token'        => '',
                'jwt_token_set_at' => 0,
            ],
            'file'          => [
                'compression' => 'zip',
            ],
        ];

        $current = get_option( 'dt_migration_settings', [] );
        if ( ! is_array( $current ) ) {
            $current = [];
        }

        return wp_parse_args( $current, $defaults );
    }

    /**
     * Persists migration settings to the options table.
     *
     * @param array $settings
     *
     * @return void
     */
    public static function update_settings( array $settings ): void {
        update_option( 'dt_migration_settings', $settings );
    }

    /**
     * Registers the top-level Migration admin menu.
     *
     * @since 0.1.0
     */
    public function register_menu() {
        $this->page_title = __( 'Migration', 'disciple-tools-migration' );

        $parent_slug = $this->token;

        // Top-level menu.
        add_menu_page(
            $this->page_title,
            $this->page_title,
            'manage_dt',
            $parent_slug,
            [ $this, 'content' ],
            'dashicons-migrate',
            57
        );

        // Submenu entries so the left-hand menu shows a fly-out similar to Site Links.
        add_submenu_page(
            $parent_slug,
            __( 'Settings', 'disciple-tools-migration' ),
            __( 'Settings', 'disciple-tools-migration' ),
            'manage_dt',
            $parent_slug,
            [ $this, 'content' ]
        );

        add_submenu_page(
            $parent_slug,
            __( 'Export', 'disciple-tools-migration' ),
            __( 'Export', 'disciple-tools-migration' ),
            'manage_dt',
            $parent_slug . '_export',
            [ $this, 'content' ]
        );

        add_submenu_page(
            $parent_slug,
            __( 'Import', 'disciple-tools-migration' ),
            __( 'Import', 'disciple-tools-migration' ),
            'manage_dt',
            $parent_slug . '_import',
            [ $this, 'content' ]
        );
    }

    /**
     * Builds the Migration page contents and tab navigation.
     *
     * @since 0.1.0
     */
    public function content() {
        if ( ! current_user_can( 'manage_dt' ) ) { // manage_dt is Disciple.Tools specific permission.
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'disciple-tools-migration' ) );
        }

        $tab  = 'settings';
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : $this->token;

        // Allow explicit tab override via query parameter for flexibility.
        if ( isset( $_GET['tab'] ) ) {
            $tab = sanitize_key( wp_unslash( $_GET['tab'] ) );
        } else {
            // Map submenu page slugs back to logical tabs.
            if ( $page === $this->token . '_export' ) {
                $tab = 'export';
            } elseif ( $page === $this->token . '_import' ) {
                $tab = 'import';
            } else {
                $tab = 'settings';
            }
        }

        $link = 'admin.php?page=' . $this->token . '&tab=';

        ?>
        <div class="wrap">
            <h2><?php echo esc_html( $this->page_title ); ?></h2>
            <h2 class="nav-tab-wrapper">
                <a href="<?php echo esc_url( $link . 'settings' ); ?>"
                   class="nav-tab <?php echo esc_attr( ( $tab === 'settings' || ! isset( $tab ) ) ? 'nav-tab-active' : '' ); ?>">
                    <?php esc_html_e( 'Settings', 'disciple-tools-migration' ); ?>
                </a>
                <a href="<?php echo esc_url( $link . 'export' ); ?>"
                   class="nav-tab <?php echo esc_attr( ( $tab === 'export' ) ? 'nav-tab-active' : '' ); ?>">
                    <?php esc_html_e( 'Export', 'disciple-tools-migration' ); ?>
                </a>
                <a href="<?php echo esc_url( $link . 'import' ); ?>"
                   class="nav-tab <?php echo esc_attr( ( $tab === 'import' ) ? 'nav-tab-active' : '' ); ?>">
                    <?php esc_html_e( 'Import', 'disciple-tools-migration' ); ?>
                </a>
            </h2>

            <?php
            switch ( $tab ) {
                case 'settings':
                    $object = new Disciple_Tools_Migration_Tab_Settings();
                    $object->content();
                    break;
                case 'export':
                    $object = new Disciple_Tools_Migration_Tab_Export();
                    $object->content();
                    break;
                case 'import':
                    $object = new Disciple_Tools_Migration_Tab_Import();
                    $object->content();
                    break;
                default:
                    /**
                     * Allow extensions to hook custom tabs.
                     *
                     * @param string $tab Current tab slug.
                     */
                    do_action( 'dt_migration_tab_content', $tab );
                    break;
            }
            ?>

        </div><!-- End wrap -->

        <?php
    }
}
Disciple_Tools_Migration_Menu::instance();