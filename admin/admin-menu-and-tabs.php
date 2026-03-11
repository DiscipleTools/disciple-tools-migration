<?php
if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly

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
            'mode'          => 'api',
            'allowed_items' => [
                'general_settings' => true,
                'custom_lists'     => true,
                'tiles'            => true,
                'fields'           => true,
                'roles'            => true,
                'workflows'        => true,
                'records'          => [
                    'contacts' => true,
                    'groups'   => true,
                ],
            ],
            'api'           => [
                'connection_type' => 'site_link',
                'site_link_id'    => 0,
                'remote_base_url' => '',
                'auth_token'      => '',
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

/**
 * Class Disciple_Tools_Migration_Tab_Settings
 *
 * Placeholder for the Migration Settings tab. Phase 2 will expand this.
 */
class Disciple_Tools_Migration_Tab_Settings {
    public function content() {
        $this->process_form_fields();
        $settings = Disciple_Tools_Migration_Menu::get_settings();

        ?>
        <div class="wrap">
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <?php $this->main_column( $settings ); ?>
                    </div><!-- end post-body-content -->
                    <div id="postbox-container-1" class="postbox-container">
                        <?php $this->right_column( $settings ); ?>
                    </div><!-- postbox-container 1 -->
                    <div id="postbox-container-2" class="postbox-container">
                    </div><!-- postbox-container 2 -->
                </div><!-- post-body meta box container -->
            </div><!--poststuff end -->
        </div><!-- wrap end -->
        <?php
    }

    /**
     * Renders the main settings form column.
     *
     * @param array $settings
     */
    public function main_column( array $settings ) {
        ?>
        <form method="post">
            <?php wp_nonce_field( 'dt_migration_settings_form', 'dt_migration_settings_form_nonce' ); ?>
            <table class="widefat striped">
                <thead>
                <tr>
                    <th>Settings</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <tr>
                    <td>
                        <?php esc_html_e( 'Enable Migration', 'disciple-tools-migration' ); ?>
                    </td>
                    <td>
                        <label>
                            <input type="checkbox" name="dt_migration_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
                            <?php esc_html_e( 'Allow this site to perform Disciple.Tools migrations (export and import).', 'disciple-tools-migration' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'If disabled, both Export and Import functionality will be unavailable.', 'disciple-tools-migration' ); ?>
                        </p>
                    </td>
                </tr>
            <tr>
                <td>
                    <?php esc_html_e( 'Migration Type', 'disciple-tools-migration' ); ?>
                </td>
                <td>
                    <fieldset>
                        <label>
                            <input type="radio" name="dt_migration_mode" value="api" <?php checked( $settings['mode'], 'api' ); ?> />
                            <?php esc_html_e( 'API Endpoints (server-to-server)', 'disciple-tools-migration' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="radio" name="dt_migration_mode" value="file" <?php checked( $settings['mode'], 'file' ); ?> />
                            <?php esc_html_e( 'Downloadable File (export/import via package)', 'disciple-tools-migration' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Choose how this site will exchange migration data with other Disciple.Tools sites.', 'disciple-tools-migration' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <td>
                    <?php esc_html_e( 'Settings & Admin Data', 'disciple-tools-migration' ); ?>
                </td>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[general_settings]" value="1" <?php checked( ! empty( $settings['allowed_items']['general_settings'] ) ); ?> />
                            <?php esc_html_e( 'General Settings', 'disciple-tools-migration' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[custom_lists]" value="1" <?php checked( ! empty( $settings['allowed_items']['custom_lists'] ) ); ?> />
                            <?php esc_html_e( 'Custom Lists', 'disciple-tools-migration' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[tiles]" value="1" <?php checked( ! empty( $settings['allowed_items']['tiles'] ) ); ?> />
                            <?php esc_html_e( 'Tiles', 'disciple-tools-migration' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[fields]" value="1" <?php checked( ! empty( $settings['allowed_items']['fields'] ) ); ?> />
                            <?php esc_html_e( 'Fields', 'disciple-tools-migration' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[roles]" value="1" <?php checked( ! empty( $settings['allowed_items']['roles'] ) ); ?> />
                            <?php esc_html_e( 'Roles', 'disciple-tools-migration' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[workflows]" value="1" <?php checked( ! empty( $settings['allowed_items']['workflows'] ) ); ?> />
                            <?php esc_html_e( 'Workflows', 'disciple-tools-migration' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Select which Disciple.Tools configuration areas are eligible for migration.', 'disciple-tools-migration' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <td>
                    <?php esc_html_e( 'Record Types', 'disciple-tools-migration' ); ?>
                </td>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[records][contacts]" value="1" <?php checked( ! empty( $settings['allowed_items']['records']['contacts'] ) ); ?> />
                            <?php esc_html_e( 'Contacts', 'disciple-tools-migration' ); ?>
                        </label>
                        <br>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[records][groups]" value="1" <?php checked( ! empty( $settings['allowed_items']['records']['groups'] ) ); ?> />
                            <?php esc_html_e( 'Groups', 'disciple-tools-migration' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Additional record types can be added in future phases. For selected types, imports will delete existing records on the target before re-creating them with preserved IDs.', 'disciple-tools-migration' ); ?>
                        </p>
                    </fieldset>
                </td>
            </tr>
                <tr>
                    <td>
                        <button class="button button-primary">
                            <?php esc_html_e( 'Save Settings', 'disciple-tools-migration' ); ?>
                        </button>
                    </td>
                    <td></td>
                </tr>
                </tbody>
            </table>
        </form>
        <br>
        <?php
    }

    /**
     * Processes and saves settings when the form is submitted.
     */
    public function process_form_fields(): void {
        if ( ! isset( $_POST['dt_migration_settings_form_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dt_migration_settings_form_nonce'] ) ), 'dt_migration_settings_form' ) ) {
            return;
        }

        $post_vars = dt_recursive_sanitize_array( $_POST );

        $settings = Disciple_Tools_Migration_Menu::get_settings();

        $settings['enabled'] = isset( $post_vars['dt_migration_enabled'] ) && '1' === (string) $post_vars['dt_migration_enabled'];

        if ( isset( $post_vars['dt_migration_mode'] ) && in_array( $post_vars['dt_migration_mode'], [ 'api', 'file' ], true ) ) {
            $settings['mode'] = $post_vars['dt_migration_mode'];
        }

        $allowed = $post_vars['dt_migration_allowed_items'] ?? [];

        $settings['allowed_items']['general_settings'] = ! empty( $allowed['general_settings'] );
        $settings['allowed_items']['custom_lists']     = ! empty( $allowed['custom_lists'] );
        $settings['allowed_items']['tiles']            = ! empty( $allowed['tiles'] );
        $settings['allowed_items']['fields']           = ! empty( $allowed['fields'] );
        $settings['allowed_items']['roles']            = ! empty( $allowed['roles'] );
        $settings['allowed_items']['workflows']        = ! empty( $allowed['workflows'] );

        if ( ! isset( $settings['allowed_items']['records'] ) || ! is_array( $settings['allowed_items']['records'] ) ) {
            $settings['allowed_items']['records'] = [
                'contacts' => true,
                'groups'   => true,
            ];
        }

        $settings['allowed_items']['records']['contacts'] = ! empty( $allowed['records']['contacts'] );
        $settings['allowed_items']['records']['groups']   = ! empty( $allowed['records']['groups'] );

        Disciple_Tools_Migration_Menu::update_settings( $settings );
    }

    /**
     * Renders the right-hand information column.
     *
     * @param array $settings
     */
    public function right_column( array $settings ) {
        ?>
        <!-- Box -->
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Information</th>
                </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <p>
                        <?php esc_html_e( 'Use this area to configure how Disciple.Tools sites can migrate settings and records between each other.', 'disciple-tools-migration' ); ?>
                    </p>
                    <p>
                        <?php esc_html_e( 'When running an import on a target site, selected record types will be deleted first and then re-created with preserved IDs from the source site, in order to keep internal connections intact.', 'disciple-tools-migration' ); ?>
                    </p>
                    <p>
                        <?php esc_html_e( 'Additional controls for API connections and file-based workflows will be added to this screen and to the Export/Import tabs in subsequent phases.', 'disciple-tools-migration' ); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
        <br>
        <!-- End Box -->
        <?php
    }
}


/**
 * Class Disciple_Tools_Migration_Tab_Export
 *
 * Placeholder for the Migration Export tab. Will be wired to settings in later phases.
 */
class Disciple_Tools_Migration_Tab_Export {
    public function content() {
        $settings = Disciple_Tools_Migration_Menu::get_settings();
        ?>
        <div class="wrap">
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <?php $this->main_column( $settings ); ?>
                    </div><!-- end post-body-content -->
                    <div id="postbox-container-1" class="postbox-container">
                        <?php $this->right_column( $settings ); ?>
                    </div><!-- postbox-container 1 -->
                    <div id="postbox-container-2" class="postbox-container">
                    </div><!-- postbox-container 2 -->
                </div><!-- post-body meta box container -->
            </div><!--poststuff end -->
        </div><!-- wrap end -->
        <?php
    }

    /**
     * Renders the main Export tab content, depending on settings and mode.
     *
     * @param array $settings
     */
    public function main_column( array $settings ) {
        ?>
        <!-- Box -->
        <table class="widefat striped">
            <thead>
            <tr>
                <th><?php esc_html_e( 'Export', 'disciple-tools-migration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <?php if ( empty( $settings['enabled'] ) ) : ?>
                        <p>
                            <?php esc_html_e( 'Migration is currently disabled. Enable it on the Settings tab in order to generate exports.', 'disciple-tools-migration' ); ?>
                        </p>
                    <?php else : ?>
                        <?php if ( $settings['mode'] === 'api' ) : ?>
                            <p>
                                <?php esc_html_e( 'This site is configured to serve migration exports via API endpoints.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'In a future phase, a remote Disciple.Tools site (Server B) will be able to call this site (Server A) to fetch settings and records selected on the Settings tab.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'For now, use this tab to confirm that API mode is enabled and review which areas are eligible for export.', 'disciple-tools-migration' ); ?>
                            </p>
                        <?php else : ?>
                            <p>
                                <?php esc_html_e( 'This site is configured to export migration packages as downloadable files.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'In a future phase, this tab will provide controls to generate a migration package (JSON/zip) that can be downloaded and imported into another Disciple.Tools site.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'The contents of the export will respect the settings and record types you have enabled on the Settings tab.', 'disciple-tools-migration' ); ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            </tbody>
        </table>
        <br>
        <!-- End Box -->
        <?php
    }

    /**
     * Renders the Export information column.
     *
     * @param array $settings
     */
    public function right_column( array $settings ) {
        $site_url  = get_site_url();
        $wp_theme  = wp_get_theme();
        $dt_version = $wp_theme->version;
        ?>
        <!-- Box -->
        <table class="widefat striped">
            <thead>
                <tr>
                <th><?php esc_html_e( 'Information', 'disciple-tools-migration' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <p>
                        <?php
                        printf(
                            /* translators: 1: site url, 2: DT theme version */
                            esc_html__( 'Current site: %1$s (Disciple.Tools version %2$s)', 'disciple-tools-migration' ),
                            esc_html( $site_url ),
                            esc_html( $dt_version )
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: migration mode label */
                            esc_html__( 'Migration mode: %s', 'disciple-tools-migration' ),
                            esc_html( $settings['mode'] === 'api' ? __( 'API Endpoints', 'disciple-tools-migration' ) : __( 'Downloadable File', 'disciple-tools-migration' ) )
                        );
                        ?>
                    </p>
                    <p>
                        <?php esc_html_e( 'Export will eventually build on this configuration to produce either API responses or downloadable packages containing the selected settings and records.', 'disciple-tools-migration' ); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
        <br>
        <!-- End Box -->
        <?php
    }
}

/**
 * Class Disciple_Tools_Migration_Tab_Import
 *
 * Placeholder for the Migration Import tab. Will be wired to settings and engines in later phases.
 */
class Disciple_Tools_Migration_Tab_Import {
    /**
     * Holds the latest connection test result for display.
     *
     * @var array|null
     */
    private $connection_result = null;

    /**
     * Holds the latest connection test error message for display.
     *
     * @var string
     */
    private $connection_error = '';

    public function content() {
        $settings = Disciple_Tools_Migration_Menu::get_settings();

        // Process any submitted connection test form before rendering.
        $this->process_form_fields( $settings );
        $settings = Disciple_Tools_Migration_Menu::get_settings();

        ?>
        <div class="wrap">
            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">
                    <div id="post-body-content">
                        <?php $this->main_column( $settings ); ?>
                    </div><!-- end post-body-content -->
                    <div id="postbox-container-1" class="postbox-container">
                        <?php $this->right_column( $settings ); ?>
                    </div><!-- postbox-container 1 -->
                    <div id="postbox-container-2" class="postbox-container">
                    </div><!-- postbox-container 2 -->
                </div><!-- post-body meta box container -->
            </div><!--poststuff end -->
        </div><!-- wrap end -->
        <?php
    }

    /**
     * Renders the main Import tab content, depending on settings and mode.
     *
     * @param array $settings
     */
    public function main_column( array $settings ) {
        ?>
        <!-- Box -->
        <table class="widefat striped">
            <thead>
            <tr>
                <th><?php esc_html_e( 'Import', 'disciple-tools-migration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <?php if ( empty( $settings['enabled'] ) ) : ?>
                        <p>
                            <?php esc_html_e( 'Migration is currently disabled. Enable it on the Settings tab before running imports.', 'disciple-tools-migration' ); ?>
                        </p>
                    <?php else : ?>
                        <?php if ( $settings['mode'] === 'api' ) : ?>
                            <p>
                                <?php esc_html_e( 'This site is configured to receive migration data from another Disciple.Tools site via API.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'In a future phase, this tab will provide controls to connect to a source site, preview the incoming payload, and then run a destructive import of the selected settings and records.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'Imports will delete existing records for the selected types before recreating them with preserved IDs from the source, so that internal connections remain valid.', 'disciple-tools-migration' ); ?>
                            </p>
                            <hr>
                            <h3><?php esc_html_e( 'API Connection to Source Site (Server A)', 'disciple-tools-migration' ); ?></h3>
                            <p>
                                <?php esc_html_e( 'Use this form to test a connection to the source Disciple.Tools site using JWT authentication and fetch its migration capabilities. This operation is non-destructive.', 'disciple-tools-migration' ); ?>
                            </p>
                            <form method="post">
                                <?php wp_nonce_field( 'dt_migration_import_connection_form', 'dt_migration_import_connection_form_nonce' ); ?>
                                <table class="widefat striped">
                                    <tbody>
                                    <tr>
                                        <td style="width:30%;">
                                            <?php esc_html_e( 'Server A Base URL', 'disciple-tools-migration' ); ?>
                                        </td>
                                        <td>
                                            <input type="url"
                                                   name="dt_migration_api_remote_base_url"
                                                   style="width:100%;"
                                                   placeholder="https://example.com"
                                                   value="<?php echo isset( $settings['api']['remote_base_url'] ) ? esc_attr( $settings['api']['remote_base_url'] ) : ''; ?>">
                                            <p class="description">
                                                <?php esc_html_e( 'Enter the base URL of the source Disciple.Tools site (Server A).', 'disciple-tools-migration' ); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <?php esc_html_e( 'Username', 'disciple-tools-migration' ); ?>
                                        </td>
                                        <td>
                                            <input type="text"
                                                   name="dt_migration_api_username"
                                                   style="width:100%;"
                                                   autocomplete="off">
                                            <p class="description">
                                                <?php esc_html_e( 'Disciple.Tools user on Server A that will be used to obtain a JWT token. This value is not stored.', 'disciple-tools-migration' ); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <?php esc_html_e( 'Password', 'disciple-tools-migration' ); ?>
                                        </td>
                                        <td>
                                            <input type="password"
                                                   name="dt_migration_api_password"
                                                   style="width:100%;"
                                                   autocomplete="off">
                                            <p class="description">
                                                <?php esc_html_e( 'Password for the Disciple.Tools user on Server A. This value is not stored.', 'disciple-tools-migration' ); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <button class="button button-secondary">
                                                <?php esc_html_e( 'Test Connection & Fetch Capabilities', 'disciple-tools-migration' ); ?>
                                            </button>
                                        </td>
                                        <td></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </form>
                            <?php if ( ! empty( $this->connection_error ) ) : ?>
                                <div class="notice notice-error" style="margin-top:10px;">
                                    <p><?php echo esc_html( $this->connection_error ); ?></p>
                                </div>
                            <?php elseif ( ! empty( $this->connection_result ) ) : ?>
                                <h3><?php esc_html_e( 'Server A Capabilities Summary', 'disciple-tools-migration' ); ?></h3>
                                <table class="widefat striped">
                                    <tbody>
                                    <tr>
                                        <td style="width:30%;"><?php esc_html_e( 'Remote Site URL', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo esc_html( $this->connection_result['site_meta']['site_url'] ?? '' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Disciple.Tools Version', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo esc_html( $this->connection_result['site_meta']['dt_version'] ?? '' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Migration Enabled', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo ! empty( $this->connection_result['enabled'] ) ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Migration Mode', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo esc_html( $this->connection_result['mode'] ?? '' ); ?></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Allowed Settings', 'disciple-tools-migration' ); ?></td>
                                        <td>
                                            <?php
                                            $allowed = $this->connection_result['allowed_items'] ?? [];
                                            $labels  = [];
                                            if ( ! empty( $allowed['general_settings'] ) ) {
                                                $labels[] = esc_html__( 'General Settings', 'disciple-tools-migration' );
                                            }
                                            if ( ! empty( $allowed['custom_lists'] ) ) {
                                                $labels[] = esc_html__( 'Custom Lists', 'disciple-tools-migration' );
                                            }
                                            if ( ! empty( $allowed['tiles'] ) ) {
                                                $labels[] = esc_html__( 'Tiles', 'disciple-tools-migration' );
                                            }
                                            if ( ! empty( $allowed['fields'] ) ) {
                                                $labels[] = esc_html__( 'Fields', 'disciple-tools-migration' );
                                            }
                                            if ( ! empty( $allowed['roles'] ) ) {
                                                $labels[] = esc_html__( 'Roles', 'disciple-tools-migration' );
                                            }
                                            if ( ! empty( $allowed['workflows'] ) ) {
                                                $labels[] = esc_html__( 'Workflows', 'disciple-tools-migration' );
                                            }
                                            echo esc_html( implode( ', ', $labels ) );
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Allowed Record Types', 'disciple-tools-migration' ); ?></td>
                                        <td>
                                            <?php
                                            $records      = $this->connection_result['allowed_items']['records'] ?? [];
                                            $recordLabels = [];
                                            if ( ! empty( $records['contacts'] ) ) {
                                                $recordLabels[] = esc_html__( 'Contacts', 'disciple-tools-migration' );
                                            }
                                            if ( ! empty( $records['groups'] ) ) {
                                                $recordLabels[] = esc_html__( 'Groups', 'disciple-tools-migration' );
                                            }
                                            echo esc_html( implode( ', ', $recordLabels ) );
                                            ?>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        <?php else : ?>
                            <p>
                                <?php esc_html_e( 'This site is configured to import migration packages from a downloadable file.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'In a future phase, this tab will provide a file upload prompt, a preview of the package contents, and controls to apply the import destructively to this site.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'Only the settings and record types you have enabled on the Settings tab will be considered for import.', 'disciple-tools-migration' ); ?>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            </tbody>
        </table>
        <br>
        <!-- End Box -->
        <?php
    }

    /**
     * Renders the Import information column.
     *
     * @param array $settings
     */
    public function right_column( array $settings ) {
        ?>
        <!-- Box -->
        <table class="widefat striped">
            <thead>
            <tr>
                <th><?php esc_html_e( 'Information', 'disciple-tools-migration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <p>
                        <?php esc_html_e( 'Imports will be destructive for the selected entities on this site. Confirmation flows and safety checks will be added alongside the actual import engine in later phases.', 'disciple-tools-migration' ); ?>
                    </p>
                    <p>
                        <?php esc_html_e( 'For now, use this tab to confirm the intended mode (API vs file) and to reason about which settings and record types should be included when we wire up the actual import steps.', 'disciple-tools-migration' ); ?>
                    </p>
                </td>
            </tr>
            </tbody>
        </table>
        <br>
        <!-- End Box -->
        <?php
    }

    /**
     * Processes the "Test Connection & Fetch Capabilities" form for API mode.
     *
     * @param array $settings
     */
    private function process_form_fields( array $settings ) : void {
        if ( empty( $settings['enabled'] ) || $settings['mode'] !== 'api' ) {
            return;
        }

        if ( ! isset( $_POST['dt_migration_import_connection_form_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dt_migration_import_connection_form_nonce'] ) ), 'dt_migration_import_connection_form' ) ) {
            return;
        }

        $post_vars = dt_recursive_sanitize_array( $_POST );

        $remote_base_url = isset( $post_vars['dt_migration_api_remote_base_url'] ) ? trim( (string) $post_vars['dt_migration_api_remote_base_url'] ) : '';
        $username        = isset( $post_vars['dt_migration_api_username'] ) ? (string) $post_vars['dt_migration_api_username'] : '';
        $password        = isset( $post_vars['dt_migration_api_password'] ) ? (string) $post_vars['dt_migration_api_password'] : '';

        if ( empty( $remote_base_url ) || empty( $username ) || empty( $password ) ) {
            $this->connection_error = esc_html__( 'Please provide the Server A base URL, username and password.', 'disciple-tools-migration' );
            return;
        }

        // Persist the remote base URL in settings for convenience.
        $settings['api']['remote_base_url'] = $remote_base_url;
        Disciple_Tools_Migration_Menu::update_settings( $settings );

        $base = rtrim( $remote_base_url, '/' );

        // Step 1: obtain JWT token from Server A.
        $token_url = $base . '/wp-json/jwt-auth/v1/token';
        $response  = wp_remote_post(
            $token_url,
            [
                'timeout' => 15,
                'body'    => [
                    'username' => $username,
                    'password' => $password,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            $this->connection_error = sprintf(
                /* translators: %s: WP error message */
                esc_html__( 'Unable to contact Server A token endpoint: %s', 'disciple-tools-migration' ),
                $response->get_error_message()
            );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 || empty( $body['token'] ) ) {
            $this->connection_error = esc_html__( 'Server A did not return a valid JWT token. Please check credentials and URL.', 'disciple-tools-migration' );
            return;
        }

        $token = (string) $body['token'];

        // Optional: validate the token.
        $validate_url = $base . '/wp-json/jwt-auth/v1/token/validate';
        $validate     = wp_remote_post(
            $validate_url,
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        if ( is_wp_error( $validate ) ) {
            $this->connection_error = sprintf(
                /* translators: %s: WP error message */
                esc_html__( 'Unable to validate JWT token on Server A: %s', 'disciple-tools-migration' ),
                $validate->get_error_message()
            );
            return;
        }

        $capabilities_url = $base . '/wp-json/dt-migration/v1/capabilities';
        $caps_response    = wp_remote_get(
            $capabilities_url,
            [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
            ]
        );

        if ( is_wp_error( $caps_response ) ) {
            $this->connection_error = sprintf(
                /* translators: %s: WP error message */
                esc_html__( 'Unable to fetch migration capabilities from Server A: %s', 'disciple-tools-migration' ),
                $caps_response->get_error_message()
            );
            return;
        }

        $caps_code = wp_remote_retrieve_response_code( $caps_response );
        $caps_body = json_decode( (string) wp_remote_retrieve_body( $caps_response ), true );

        if ( $caps_code < 200 || $caps_code >= 300 || ! is_array( $caps_body ) ) {
            $this->connection_error = esc_html__( 'Unexpected response when fetching migration capabilities from Server A.', 'disciple-tools-migration' );
            return;
        }

        $this->connection_result = $caps_body;
    }
}


