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
                        <br>
                        <label>
                            <input type="checkbox" name="dt_migration_allowed_items[system_users]" value="1" <?php checked( ! empty( $settings['allowed_items']['system_users'] ) ); ?> />
                            <?php esc_html_e( 'WordPress users (system)', 'disciple-tools-migration' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Select which Disciple.Tools configuration areas are eligible for migration. User export includes safe profile data only (no passwords). Matching users are found by email, then login; missing users are created with roles from the export (generated passwords). Importing administrator accounts requires promote_users.', 'disciple-tools-migration' ); ?>
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
                    <?php esc_html_e( 'Downloadable export (JSON) memory guard', 'disciple-tools-migration' ); ?>
                </td>
                <td>
                    <?php
                    $mem = $settings['file_export_memory'] ?? [];
                    if ( ! is_array( $mem ) ) {
                        $mem = [];
                    }
                    $def_ratio     = Disciple_Tools_Migration_Export_File::DEFAULT_FILE_EXPORT_MEMORY_BUDGET_RATIO;
                    $def_bpr       = Disciple_Tools_Migration_Export_File::DEFAULT_FILE_EXPORT_BYTES_PER_RECORD;
                    $def_bpu       = Disciple_Tools_Migration_Export_File::DEFAULT_FILE_EXPORT_BYTES_PER_USER;
                    $def_overhead  = Disciple_Tools_Migration_Export_File::DEFAULT_FILE_EXPORT_SETTINGS_OVERHEAD_BYTES;
                    $ratio_val     = isset( $mem['budget_ratio'] ) && $mem['budget_ratio'] !== '' && null !== $mem['budget_ratio'] ? (string) $mem['budget_ratio'] : '';
                    $bpr_val       = isset( $mem['bytes_per_record'] ) && $mem['bytes_per_record'] !== '' && null !== $mem['bytes_per_record'] ? (string) (int) $mem['bytes_per_record'] : '';
                    $bpu_val       = isset( $mem['bytes_per_user'] ) && $mem['bytes_per_user'] !== '' && null !== $mem['bytes_per_user'] ? (string) (int) $mem['bytes_per_user'] : '';
                    $overhead_val  = isset( $mem['settings_overhead_bytes'] ) && $mem['settings_overhead_bytes'] !== '' && null !== $mem['settings_overhead_bytes'] ? (string) (int) $mem['settings_overhead_bytes'] : '';
                    ?>
                    <p class="description" style="margin-top:0;">
                        <?php esc_html_e( 'When you download a JSON export, the plugin estimates whether the payload may exceed PHP’s memory limit. Leave a field blank to use the built-in default shown as the placeholder. Lower the budget ratio or raise per-record estimates to block large exports earlier (or the opposite to allow larger packages on well-provisioned servers).', 'disciple-tools-migration' ); ?>
                    </p>
                    <p>
                        <label for="dt_migration_export_memory_budget_ratio">
                            <?php esc_html_e( 'Memory budget ratio', 'disciple-tools-migration' ); ?>
                        </label><br>
                        <input type="number" name="dt_migration_export_memory_budget_ratio" id="dt_migration_export_memory_budget_ratio" value="<?php echo esc_attr( $ratio_val ); ?>" min="0.05" max="0.95" step="0.01" style="max-width:7em;" placeholder="<?php echo esc_attr( (string) $def_ratio ); ?>" />
                        <span class="description"><?php esc_html_e( 'Fraction of PHP memory_limit used as the safe budget (heuristic).', 'disciple-tools-migration' ); ?></span>
                    </p>
                    <p>
                        <label for="dt_migration_export_memory_bytes_per_record">
                            <?php esc_html_e( 'Estimated bytes per record', 'disciple-tools-migration' ); ?>
                        </label><br>
                        <input type="number" name="dt_migration_export_memory_bytes_per_record" id="dt_migration_export_memory_bytes_per_record" value="<?php echo esc_attr( $bpr_val ); ?>" min="1024" step="1" style="max-width:10em;" placeholder="<?php echo esc_attr( (string) $def_bpr ); ?>" />
                    </p>
                    <p>
                        <label for="dt_migration_export_memory_bytes_per_user">
                            <?php esc_html_e( 'Estimated bytes per WordPress user', 'disciple-tools-migration' ); ?>
                        </label><br>
                        <input type="number" name="dt_migration_export_memory_bytes_per_user" id="dt_migration_export_memory_bytes_per_user" value="<?php echo esc_attr( $bpu_val ); ?>" min="128" step="1" style="max-width:10em;" placeholder="<?php echo esc_attr( (string) $def_bpu ); ?>" />
                    </p>
                    <p>
                        <label for="dt_migration_export_memory_settings_overhead_bytes">
                            <?php esc_html_e( 'Settings / metadata overhead (bytes)', 'disciple-tools-migration' ); ?>
                        </label><br>
                        <input type="number" name="dt_migration_export_memory_settings_overhead_bytes" id="dt_migration_export_memory_settings_overhead_bytes" value="<?php echo esc_attr( $overhead_val ); ?>" min="0" step="1" style="max-width:10em;" placeholder="<?php echo esc_attr( (string) $def_overhead ); ?>" />
                    </p>
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

        $allowed = $post_vars['dt_migration_allowed_items'] ?? [];

        $settings['allowed_items']['general_settings'] = ! empty( $allowed['general_settings'] );
        $settings['allowed_items']['custom_lists']     = ! empty( $allowed['custom_lists'] );
        $settings['allowed_items']['tiles']            = ! empty( $allowed['tiles'] );
        $settings['allowed_items']['fields']           = ! empty( $allowed['fields'] );
        $settings['allowed_items']['roles']            = ! empty( $allowed['roles'] );
        $settings['allowed_items']['workflows']        = ! empty( $allowed['workflows'] );
        $settings['allowed_items']['system_users']     = ! empty( $allowed['system_users'] );

        if ( ! isset( $settings['allowed_items']['records'] ) || ! is_array( $settings['allowed_items']['records'] ) ) {
            $settings['allowed_items']['records'] = [
                'contacts' => true,
                'groups'   => true,
            ];
        }

        $settings['allowed_items']['records']['contacts'] = ! empty( $allowed['records']['contacts'] );
        $settings['allowed_items']['records']['groups']   = ! empty( $allowed['records']['groups'] );

        if ( ! isset( $settings['file_export_memory'] ) || ! is_array( $settings['file_export_memory'] ) ) {
            $settings['file_export_memory'] = [];
        }

        $ratio_raw = isset( $post_vars['dt_migration_export_memory_budget_ratio'] ) ? trim( (string) $post_vars['dt_migration_export_memory_budget_ratio'] ) : '';
        if ( '' === $ratio_raw ) {
            $settings['file_export_memory']['budget_ratio'] = null;
        } else {
            $r = (float) str_replace( ',', '.', $ratio_raw );
            $settings['file_export_memory']['budget_ratio'] = max( 0.05, min( 0.95, $r ) );
        }

        $bpr_raw = isset( $post_vars['dt_migration_export_memory_bytes_per_record'] ) ? trim( (string) $post_vars['dt_migration_export_memory_bytes_per_record'] ) : '';
        if ( '' === $bpr_raw ) {
            $settings['file_export_memory']['bytes_per_record'] = null;
        } else {
            $settings['file_export_memory']['bytes_per_record'] = max( 1024, absint( $bpr_raw ) );
        }

        $bpu_raw = isset( $post_vars['dt_migration_export_memory_bytes_per_user'] ) ? trim( (string) $post_vars['dt_migration_export_memory_bytes_per_user'] ) : '';
        if ( '' === $bpu_raw ) {
            $settings['file_export_memory']['bytes_per_user'] = null;
        } else {
            $settings['file_export_memory']['bytes_per_user'] = max( 128, absint( $bpu_raw ) );
        }

        $ov_raw = isset( $post_vars['dt_migration_export_memory_settings_overhead_bytes'] ) ? trim( (string) $post_vars['dt_migration_export_memory_settings_overhead_bytes'] ) : '';
        if ( '' === $ov_raw ) {
            $settings['file_export_memory']['settings_overhead_bytes'] = null;
        } else {
            $settings['file_export_memory']['settings_overhead_bytes'] = absint( $ov_raw );
        }

        // Do not persist file_export_memory when every field means "use defaults". update_option() can
        // drop null leaf values, which used to leave stale keys (e.g. a large bytes_per_record alone).
        if (
            null === $settings['file_export_memory']['budget_ratio']
            && null === $settings['file_export_memory']['bytes_per_record']
            && null === $settings['file_export_memory']['bytes_per_user']
            && null === $settings['file_export_memory']['settings_overhead_bytes']
        ) {
            unset( $settings['file_export_memory'] );
        }

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