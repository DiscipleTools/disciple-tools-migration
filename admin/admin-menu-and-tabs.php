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
     * Post type slugs returned by Disciple.Tools for migratable records, or a minimal fallback.
     *
     * @return string[]
     */
    public static function get_migratable_post_types(): array {
        if ( class_exists( 'DT_Posts' ) ) {
            $types = DT_Posts::get_post_types();
            return is_array( $types ) ? array_values( array_unique( $types ) ) : [];
        }
        return [ 'contacts', 'groups' ];
    }

    /**
     * Default "allowed" flag for record migration for a post type (new installs / newly registered types).
     *
     * @param string $post_type Sanitized post type slug.
     */
    public static function get_default_record_allowed_for_type( string $post_type ): bool {
        return in_array( $post_type, [ 'contacts', 'groups' ], true );
    }

    /**
     * Merges stored record toggles with all current DT post types.
     *
     * @param array<string, mixed> $stored Values from options (may omit new types or contain stale keys).
     *
     * @return array<string, bool>
     */
    public static function normalize_records_allowed( array $stored ): array {
        $types = self::get_migratable_post_types();
        $out   = [];

        foreach ( $types as $post_type ) {
            if ( array_key_exists( $post_type, $stored ) ) {
                $out[ $post_type ] = ! empty( $stored[ $post_type ] );
            } else {
                $out[ $post_type ] = self::get_default_record_allowed_for_type( $post_type );
            }
        }

        return $out;
    }

    /**
     * Human-readable label for a DT post type (plural preferred).
     *
     * @param string $post_type Post type slug.
     */
    public static function get_post_type_label( string $post_type ): string {
        if ( ! class_exists( 'DT_Posts' ) ) {
            return $post_type;
        }
        try {
            $settings = DT_Posts::get_post_settings( $post_type, false );
            if ( is_array( $settings ) ) {
                if ( ! empty( $settings['label_plural'] ) ) {
                    return (string) $settings['label_plural'];
                }
                if ( ! empty( $settings['label'] ) ) {
                    return (string) $settings['label'];
                }
            }
        } catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
        }
        return $post_type;
    }

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

        $parsed = wp_parse_args( $current, $defaults );
        if ( ! isset( $parsed['allowed_items'] ) || ! is_array( $parsed['allowed_items'] ) ) {
            $parsed['allowed_items'] = $defaults['allowed_items'];
        }
        $rec = $parsed['allowed_items']['records'] ?? [];
        $parsed['allowed_items']['records'] = self::normalize_records_allowed( is_array( $rec ) ? $rec : [] );

        return $parsed;
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