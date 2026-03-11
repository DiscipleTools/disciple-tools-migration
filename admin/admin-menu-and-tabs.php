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
}


