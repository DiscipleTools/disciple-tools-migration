<?php
if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly

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