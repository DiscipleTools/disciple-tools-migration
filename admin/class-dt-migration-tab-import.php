<?php
if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly

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

    /**
     * Holds the latest settings export preview for display.
     *
     * @var array|null
     */
    private $settings_preview = null;

    /**
     * Holds the latest records preview (record counts) from Server A.
     *
     * @var array|null
     */
    private $records_preview = null;

    /**
     * Holds allowed_items from the export response (authoritative for preview tables).
     *
     * @var array|null
     */
    private $export_allowed_items = null;

    /**
     * WordPress users listed in the last fetched file/API export preview (for table notes).
     *
     * @var int
     */
    private $import_preview_user_count = 0;

    /**
     * Which import UI produced the current preview: 'api' or 'file'.
     *
     * @var string|null
     */
    private $import_preview_channel = null;

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
     * Renders the main Import tab content (API and file flows).
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
                        <p>
                            <?php esc_html_e( 'Use the API block below to pull from a live source site (Server A), or use the file block to upload a JSON export. The preview and import buttons apply to whichever flow you used last (fetch or upload). Run preflight (optional) checks for common issues before you start; Start Import runs immediately without that step.', 'disciple-tools-migration' ); ?>
                        </p>
                        <p>
                            <?php esc_html_e( 'Imports delete existing records for the selected types before recreating them with preserved IDs from the source, so that internal connections remain valid.', 'disciple-tools-migration' ); ?>
                        </p>
                        <hr>
                        <div class="dt-migration-import-section" data-import-channel="api">
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
                                            <button class="button button-secondary" name="dt_migration_action" value="test_connection">
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
                                        <td><?php esc_html_e( 'Migration channels (reported)', 'disciple-tools-migration' ); ?></td>
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
                                            if ( ! empty( $allowed['system_users'] ) ) {
                                                $labels[] = esc_html__( 'WordPress users (system)', 'disciple-tools-migration' );
                                            }
                                            echo esc_html( implode( ', ', $labels ) );
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Allowed Record Types', 'disciple-tools-migration' ); ?></td>
                                        <td>
                                            <?php
                                            $records       = $this->connection_result['allowed_items']['records'] ?? [];
                                            $record_labels = [];
                                            if ( is_array( $records ) ) {
                                                foreach ( $records as $post_type => $enabled ) {
                                                    if ( ! empty( $enabled ) ) {
                                                        $record_labels[] = Disciple_Tools_Migration_Menu::get_post_type_label( (string) $post_type );
                                                    }
                                                }
                                            }
                                            echo esc_html( implode( ', ', $record_labels ) );
                                            ?>
                                        </td>
                                    </tr>
                                    </tbody>
                                </table>

                                <!-- Show settings preview button only after a successful connection -->
                                <form method="post" style="margin-top: 20px;">
                                    <?php wp_nonce_field( 'dt_migration_import_connection_form', 'dt_migration_import_connection_form_nonce' ); ?>
                                    <button class="button" name="dt_migration_action" value="settings_preview">
                                        <?php esc_html_e( 'Fetch Settings Export Preview', 'disciple-tools-migration' ); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ( ! empty( $this->settings_preview ) && $this->import_preview_channel === 'api' ) : ?>
                                <h3><?php esc_html_e( 'Server A Settings Export Preview', 'disciple-tools-migration' ); ?></h3>

                                <?php
                                // Use allowed_items from export response (same request that produced the preview).
                                $allowed          = $this->export_allowed_items ?? $this->connection_result['allowed_items'] ?? [];
                                $dt_settings      = $this->settings_preview;
                                $records_preview  = $this->records_preview ?? [];
                                $post_type_count  = is_array( $records_preview ) ? count( $records_preview ) : ( is_array( $dt_settings ) ? count( $dt_settings ) : 0 );
                                ?>

                                <!-- Table 1: Settings Summary (with import checkboxes) -->
                                <table class="widefat striped dt-migration-settings-table" style="margin-bottom: 20px;">
                                    <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" style="margin: 0;" class="dt-migration-select-all-settings" checked aria-label="<?php esc_attr_e( 'Select all settings', 'disciple-tools-migration' ); ?>">
                                        </th>
                                        <th><?php esc_html_e( 'Setting Type', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Enabled', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Notes', 'disciple-tools-migration' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $settings_rows = [
                                        'system_users'     => [
                                            'label' => __( 'WordPress users (system)', 'disciple-tools-migration' ),
                                            'notes' => $this->import_preview_user_count > 0
                                                ? sprintf( esc_html__( '%d users in this export (passwords never included).', 'disciple-tools-migration' ), $this->import_preview_user_count )
                                                : '',
                                        ],
                                        'general_settings' => [ 'label' => __( 'General Settings', 'disciple-tools-migration' ), 'notes' => '' ],
                                        'custom_lists'     => [ 'label' => __( 'Custom Lists', 'disciple-tools-migration' ), 'notes' => '' ],
                                        'tiles'            => [ 'label' => __( 'Tiles', 'disciple-tools-migration' ), 'notes' => ! empty( $allowed['tiles'] ) ? sprintf( esc_html__( 'Tiles defined for %d post types.', 'disciple-tools-migration' ), $post_type_count ) : '' ],
                                        'fields'           => [ 'label' => __( 'Fields', 'disciple-tools-migration' ), 'notes' => ! empty( $allowed['fields'] ) ? sprintf( esc_html__( 'Fields defined for %d post types.', 'disciple-tools-migration' ), $post_type_count ) : '' ],
                                        'roles'            => [ 'label' => __( 'Roles', 'disciple-tools-migration' ), 'notes' => '' ],
                                        'workflows'        => [ 'label' => __( 'Workflows', 'disciple-tools-migration' ), 'notes' => '' ],
                                    ];
                                    foreach ( $settings_rows as $key => $row ) :
                                        $is_enabled = ! empty( $allowed[ $key ] );
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       class="dt-migration-setting-checkbox"
                                                       name="dt_migration_import_settings[]"
                                                       value="<?php echo esc_attr( $key ); ?>"
                                                       <?php echo $is_enabled ? 'checked' : 'disabled'; ?>
                                                       data-setting-type="<?php echo esc_attr( $key ); ?>">
                                            </td>
                                            <td><?php echo esc_html( $row['label'] ); ?></td>
                                            <td><?php echo $is_enabled ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                            <td><?php echo esc_html( $row['notes'] ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <!-- Table 2: Record Types Preview (with import checkboxes) -->
                                <table class="widefat striped dt-migration-records-table">
                                    <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" style="margin: 0;" class="dt-migration-select-all-records" checked aria-label="<?php esc_attr_e( 'Select all record types', 'disciple-tools-migration' ); ?>">
                                        </th>
                                        <th><?php esc_html_e( 'Post Type', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Tiles', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Fields', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Records', 'disciple-tools-migration' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $records_counts = $this->records_preview ?? [];
                                    $dt_preview     = $this->settings_preview ?? [];
                                    foreach ( $records_counts as $post_type => $record_data ) {
                                        $summary      = $dt_preview[ $post_type ] ?? [ 'tiles' => 0, 'fields' => 0 ];
                                        $record_count = isset( $record_data['count'] ) ? (int) $record_data['count'] : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox"
                                                       class="dt-migration-record-checkbox"
                                                       name="dt_migration_import_records[]"
                                                       value="<?php echo esc_attr( $post_type ); ?>"
                                                       checked
                                                       data-post-type="<?php echo esc_attr( $post_type ); ?>"
                                                       data-record-count="<?php echo (int) $record_count; ?>">
                                            </td>
                                            <td><?php echo esc_html( $post_type ); ?></td>
                                            <td><?php echo isset( $summary['tiles'] ) ? intval( $summary['tiles'] ) : 0; ?></td>
                                            <td><?php echo isset( $summary['fields'] ) ? intval( $summary['fields'] ) : 0; ?></td>
                                            <td><?php echo esc_html( (string) (int) $record_count ); ?></td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
                                    </tbody>
                                </table>

                                <p style="margin-top: 16px;">
                                    <button type="button" class="button dt-migration-run-preflight" data-import-channel="api">
                                        <?php esc_html_e( 'Run preflight', 'disciple-tools-migration' ); ?>
                                    </button>
                                    <button type="button" class="button button-primary dt-migration-start-import" data-import-channel="api" style="margin-left:8px;">
                                        <?php esc_html_e( 'Start Import', 'disciple-tools-migration' ); ?>
                                    </button>
                                </p>
                            <?php endif; ?>
                        </div>
                        <hr style="margin: 28px 0;">
                        <div class="dt-migration-import-section" data-import-channel="file">
                            <h3><?php esc_html_e( 'Upload & preview (JSON file)', 'disciple-tools-migration' ); ?></h3>
                            <p>
                                <?php esc_html_e( 'Upload a migration export JSON file from another Disciple.Tools site, then preview and import.', 'disciple-tools-migration' ); ?>
                            </p>
                            <?php if ( ! empty( $this->connection_error ) ) : ?>
                                <div class="notice notice-error" style="margin-top:10px;">
                                    <p><?php echo esc_html( $this->connection_error ); ?></p>
                                </div>
                            <?php endif; ?>
                            <form method="post" enctype="multipart/form-data" style="margin-top: 16px;">
                                <?php wp_nonce_field( 'dt_migration_file_upload', 'dt_migration_file_upload_nonce' ); ?>
                                <table class="widefat striped">
                                    <tbody>
                                    <tr>
                                        <td style="width:30%;"><?php esc_html_e( 'Migration JSON File', 'disciple-tools-migration' ); ?></td>
                                        <td>
                                            <input type="file" name="dt_migration_import_file" accept=".json">
                                            <p class="description">
                                                <?php esc_html_e( 'Select a migration export JSON file from another Disciple.Tools site.', 'disciple-tools-migration' ); ?>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <button type="submit" class="button" name="dt_migration_action" value="file_upload">
                                                <?php esc_html_e( 'Upload & Preview', 'disciple-tools-migration' ); ?>
                                            </button>
                                        </td>
                                        <td></td>
                                    </tr>
                                    </tbody>
                                </table>
                            </form>
                            <?php if ( ! empty( $this->settings_preview ) && $this->import_preview_channel === 'file' ) : ?>
                                <h3 style="margin-top: 24px;"><?php esc_html_e( 'File Contents Preview', 'disciple-tools-migration' ); ?></h3>
                                <?php
                                $allowed          = $this->export_allowed_items ?? [];
                                $dt_settings      = $this->settings_preview;
                                $records_preview  = $this->records_preview ?? [];
                                $post_type_count  = is_array( $records_preview ) ? count( $records_preview ) : 0;
                                ?>
                                <table class="widefat striped dt-migration-settings-table" style="margin-bottom: 20px;">
                                    <thead>
                                    <tr>
                                        <th><input type="checkbox" style="margin: 0 0 0 0px;" class="dt-migration-select-all-settings" checked aria-label="<?php esc_attr_e( 'Select all settings', 'disciple-tools-migration' ); ?>"></th>
                                        <th><?php esc_html_e( 'Setting Type', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Enabled', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Notes', 'disciple-tools-migration' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $settings_rows = [
                                        'system_users'     => [
                                            'label' => __( 'WordPress users (system)', 'disciple-tools-migration' ),
                                            'notes' => $this->import_preview_user_count > 0
                                                ? sprintf( esc_html__( '%d users in this export (passwords never included).', 'disciple-tools-migration' ), $this->import_preview_user_count )
                                                : '',
                                        ],
                                        'general_settings' => [ 'label' => __( 'General Settings', 'disciple-tools-migration' ), 'notes' => '' ],
                                        'custom_lists'     => [ 'label' => __( 'Custom Lists', 'disciple-tools-migration' ), 'notes' => '' ],
                                        'tiles'            => [ 'label' => __( 'Tiles', 'disciple-tools-migration' ), 'notes' => ! empty( $allowed['tiles'] ) ? sprintf( esc_html__( 'Tiles defined for %d post types.', 'disciple-tools-migration' ), $post_type_count ) : '' ],
                                        'fields'           => [ 'label' => __( 'Fields', 'disciple-tools-migration' ), 'notes' => ! empty( $allowed['fields'] ) ? sprintf( esc_html__( 'Fields defined for %d post types.', 'disciple-tools-migration' ), $post_type_count ) : '' ],
                                        'roles'            => [ 'label' => __( 'Roles', 'disciple-tools-migration' ), 'notes' => '' ],
                                        'workflows'        => [ 'label' => __( 'Workflows', 'disciple-tools-migration' ), 'notes' => '' ],
                                    ];
                                    foreach ( $settings_rows as $key => $row ) :
                                        $is_enabled = ! empty( $allowed[ $key ] );
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="dt-migration-setting-checkbox" name="dt_migration_import_settings[]" value="<?php echo esc_attr( $key ); ?>" <?php echo $is_enabled ? 'checked' : 'disabled'; ?> data-setting-type="<?php echo esc_attr( $key ); ?>">
                                            </td>
                                            <td><?php echo esc_html( $row['label'] ); ?></td>
                                            <td><?php echo $is_enabled ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                            <td><?php echo esc_html( $row['notes'] ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <table class="widefat striped dt-migration-records-table">
                                    <thead>
                                    <tr>
                                        <th><input type="checkbox" style="margin: 0 0 0 0px;" class="dt-migration-select-all-records" checked aria-label="<?php esc_attr_e( 'Select all record types', 'disciple-tools-migration' ); ?>"></th>
                                        <th><?php esc_html_e( 'Post Type', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Tiles', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Fields', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Records', 'disciple-tools-migration' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $records_counts = $this->records_preview ?? [];
                                    $dt_preview     = $this->settings_preview ?? [];
                                    foreach ( $records_counts as $post_type => $record_data ) {
                                        $summary      = $dt_preview[ $post_type ] ?? [ 'tiles' => 0, 'fields' => 0 ];
                                        $record_count = isset( $record_data['count'] ) ? (int) $record_data['count'] : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="dt-migration-record-checkbox" name="dt_migration_import_records[]" value="<?php echo esc_attr( $post_type ); ?>" checked data-post-type="<?php echo esc_attr( $post_type ); ?>" data-record-count="<?php echo (int) $record_count; ?>">
                                            </td>
                                            <td><?php echo esc_html( $post_type ); ?></td>
                                            <td><?php echo isset( $summary['tiles'] ) ? intval( $summary['tiles'] ) : 0; ?></td>
                                            <td><?php echo isset( $summary['fields'] ) ? intval( $summary['fields'] ) : 0; ?></td>
                                            <td><?php echo esc_html( (string) (int) $record_count ); ?></td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                                <p style="margin-top: 16px;">
                                    <button type="button" class="button dt-migration-run-preflight" data-import-channel="file">
                                        <?php esc_html_e( 'Run preflight', 'disciple-tools-migration' ); ?>
                                    </button>
                                    <button type="button" class="button button-primary dt-migration-start-import" data-import-channel="file" style="margin-left:8px;">
                                        <?php esc_html_e( 'Start Import', 'disciple-tools-migration' ); ?>
                                    </button>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php if ( ! empty( $this->settings_preview ) ) : ?>
                            <?php $this->render_import_modal_and_progress(); ?>
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
                        <?php esc_html_e( 'Use the API or file path on this tab, then select which settings and record types to include before starting an import.', 'disciple-tools-migration' ); ?>
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
     * Renders the confirmation modal and progress UI for the import flow.
     *
     * @return void
     */
    private function render_import_modal_and_progress() : void {
        if ( empty( $this->settings_preview ) ) {
            return;
        }
        ?>
        <div id="dt-migration-preflight-modal" class="dt-migration-modal dt-migration-preflight-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dt-migration-preflight-title">
            <div class="dt-migration-modal-overlay dt-migration-preflight-overlay"></div>
            <div class="dt-migration-modal-content dt-migration-modal-content--wide">
                <h2 id="dt-migration-preflight-title"><?php esc_html_e( 'Preflight results', 'disciple-tools-migration' ); ?></h2>
                <div class="dt-migration-modal-body">
                    <p class="dt-migration-preflight-intro"><?php esc_html_e( 'These checks are advisory. You can proceed; the import may still log per-record issues.', 'disciple-tools-migration' ); ?></p>
                    <div class="dt-migration-preflight-info-wrap" hidden>
                        <p class="dt-migration-preflight-field-label">
                            <strong><?php esc_html_e( 'Notes', 'disciple-tools-migration' ); ?></strong>
                        </p>
                        <label class="screen-reader-text" for="dt-migration-preflight-info-text"><?php esc_html_e( 'Preflight notes', 'disciple-tools-migration' ); ?></label>
                        <textarea id="dt-migration-preflight-info-text"
                                  class="dt-migration-preflight-textarea dt-migration-preflight-textarea--notes"
                                  readonly
                                  rows="4"
                                  cols="40"></textarea>
                    </div>
                    <div class="dt-migration-preflight-warnings-wrap" hidden>
                        <p class="dt-migration-preflight-field-label">
                            <strong><?php esc_html_e( 'Warnings', 'disciple-tools-migration' ); ?></strong>
                        </p>
                        <label class="screen-reader-text" for="dt-migration-preflight-warnings-text"><?php esc_html_e( 'Preflight warnings', 'disciple-tools-migration' ); ?></label>
                        <textarea id="dt-migration-preflight-warnings-text"
                                  class="dt-migration-preflight-textarea dt-migration-preflight-textarea--warnings"
                                  readonly
                                  rows="10"
                                  cols="40"></textarea>
                    </div>
                    <p class="dt-migration-preflight-status" hidden></p>
                    <div class="dt-migration-modal-actions" style="margin-top:16px;">
                        <button type="button" class="button dt-migration-preflight-close"><?php esc_html_e( 'Close', 'disciple-tools-migration' ); ?></button>
                        <button type="button" class="button button-primary dt-migration-preflight-proceed"><?php esc_html_e( 'Proceed with import', 'disciple-tools-migration' ); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <div id="dt-migration-import-modal" class="dt-migration-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="dt-migration-modal-title">
            <div class="dt-migration-modal-overlay"></div>
            <div class="dt-migration-modal-content">
                <h2 id="dt-migration-modal-title"><?php esc_html_e( 'Confirm Import', 'disciple-tools-migration' ); ?></h2>
                <div class="dt-migration-modal-body">
                    <p class="dt-migration-modal-warning">
                        <?php esc_html_e( 'This action will overwrite existing settings and records on this site. It cannot be undone.', 'disciple-tools-migration' ); ?>
                    </p>
                    <div class="dt-migration-modal-summary"></div>
                    <div class="dt-migration-modal-confirm-gate">
                        <p>
                            <label for="dt-migration-confirm-input">
                                <?php esc_html_e( 'Type IMPORT to continue:', 'disciple-tools-migration' ); ?>
                            </label>
                        </p>
                        <input type="text"
                               id="dt-migration-confirm-input"
                               class="dt-migration-confirm-input"
                               autocomplete="off"
                               placeholder="<?php esc_attr_e( 'IMPORT', 'disciple-tools-migration' ); ?>">
                    </div>
                    <div class="dt-migration-modal-actions">
                        <button type="button" class="button dt-migration-modal-cancel">
                            <?php esc_html_e( 'Cancel', 'disciple-tools-migration' ); ?>
                        </button>
                        <button type="button" class="button button-primary dt-migration-modal-confirm" disabled>
                            <?php esc_html_e( 'Confirm', 'disciple-tools-migration' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div id="dt-migration-progress-panel" style="display:none;" class="dt-migration-progress-panel">
            <h3><?php esc_html_e( 'Import Progress', 'disciple-tools-migration' ); ?></h3>
            <div class="dt-migration-progress-bar-wrap">
                <span class="dt-migration-import-spinner" hidden aria-hidden="true"></span>
                <div class="dt-migration-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                    <span class="dt-migration-progress-fill"></span>
                </div>
                <span class="dt-migration-progress-text">0%</span>
            </div>
            <ol class="dt-migration-step-list"></ol>
            <p class="dt-migration-current-phase"></p>
            <div id="dt-migration-error-details" class="dt-migration-error-details" style="display:none;" role="alert">
                <strong><?php esc_html_e( 'Error details:', 'disciple-tools-migration' ); ?></strong>
                <div class="dt-migration-error-scroll"></div>
            </div>
            <button type="button" class="button dt-migration-cancel-import" style="margin-top:10px;">
                <?php esc_html_e( 'Cancel Import', 'disciple-tools-migration' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Processes Import tab forms (connection / preview and file upload).
     *
     * Handles:
     * - "Test Connection & Fetch Capabilities"
     * - "Fetch Settings Export Preview"
     * - File upload & preview
     *
     * @param array $settings
     */
    private function process_form_fields( array $settings ) : void {
        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( isset( $_POST['dt_migration_file_upload_nonce'] ) ) {
            $this->process_file_upload( $settings );
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

        $action = isset( $post_vars['dt_migration_action'] ) ? (string) $post_vars['dt_migration_action'] : 'test_connection';

        // If user clicked "Fetch Settings Export Preview", use stored URL + JWT.
        if ( $action === 'settings_preview' ) {
            $remote_base_url = $settings['api']['remote_base_url'] ?? '';
            $jwt_token       = $settings['api']['jwt_token'] ?? '';
            $token_set_at    = (int) ( $settings['api']['jwt_token_set_at'] ?? 0 );

            if ( empty( $remote_base_url ) || empty( $jwt_token ) ) {
                $this->connection_error = esc_html__( 'JWT token not available. Please run "Test Connection & Fetch Capabilities" first.', 'disciple-tools-migration' );
                return;
            }

            if ( $token_set_at < ( time() - HOUR_IN_SECONDS ) ) {
                $this->connection_error = esc_html__( 'JWT token appears to be expired. Please re-run "Test Connection & Fetch Capabilities".', 'disciple-tools-migration' );
                return;
            }

            $base       = rtrim( $remote_base_url, '/' );
            $export_url = $base . '/wp-json/dt-migration/v1/export';
            $export_res = wp_remote_post(
                $export_url,
                [
                    'timeout' => 30,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $jwt_token,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode( [ 'settings_only' => true ] ),
                ]
            );

            if ( is_wp_error( $export_res ) ) {
                $this->connection_error = sprintf(
                    esc_html__( 'Unable to fetch settings export from Server A: %s', 'disciple-tools-migration' ),
                    $export_res->get_error_message()
                );
                return;
            }

            $export_code = wp_remote_retrieve_response_code( $export_res );
            $export_body = json_decode( (string) wp_remote_retrieve_body( $export_res ), true );

            if ( $export_code < 200 || $export_code >= 300 || ! is_array( $export_body ) ) {
                $this->connection_error = esc_html__( 'Unexpected response when fetching settings export from Server A.', 'disciple-tools-migration' );
                return;
            }

            $dt_settings = $export_body['export']['dt_settings'] ?? [];
            $post_types  = $dt_settings['dt_post_types_settings']['values'] ?? [];
            $tiles_all   = $dt_settings['dt_tiles_settings']['values'] ?? [];
            $fields_all  = $dt_settings['dt_fields_settings']['values'] ?? [];
            $sys_users   = $export_body['export']['system_users']['users'] ?? [];
            $this->import_preview_user_count = is_array( $sys_users ) ? count( $sys_users ) : 0;

            // Store allowed_items from export response for use in preview tables.
            $this->export_allowed_items = $export_body['settings']['allowed_items'] ?? [];

            $preview = [];
            foreach ( $post_types as $post_type => $config ) {
                $preview[ $post_type ] = [
                    'tiles'  => isset( $tiles_all[ $post_type ] ) ? count( (array) $tiles_all[ $post_type ] ) : 0,
                    'fields' => isset( $fields_all[ $post_type ] ) ? count( (array) $fields_all[ $post_type ] ) : 0,
                ];
            }

            $this->settings_preview = $preview;

            // Also fetch non-destructive record counts (only returns allowed record types).
            $preview_records = [];

            $records_url = $base . '/wp-json/dt-migration/v1/records-preview';
            $records_res = wp_remote_get(
                $records_url,
                [
                    'timeout' => 20,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $jwt_token,
                    ],
                ]
            );

            if ( ! is_wp_error( $records_res ) ) {
                $records_code = wp_remote_retrieve_response_code( $records_res );
                $records_body = json_decode( (string) wp_remote_retrieve_body( $records_res ), true );
                if ( $records_code >= 200 && $records_code < 300 && is_array( $records_body ) ) {
                    $preview_records = $records_body['records'] ?? [];
                }
            }

            $this->records_preview = $preview_records;

            $this->import_preview_channel = 'api';

            return;
        }

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

        // Persist connection details and JWT for later preview use.
        $settings['api']['remote_base_url']  = $remote_base_url;
        $settings['api']['jwt_token']        = $token;
        $settings['api']['jwt_token_set_at'] = time();
        Disciple_Tools_Migration_Menu::update_settings( $settings );

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

        // Always clear previous preview when re-submitting the form.
        $this->settings_preview           = null;
        $this->import_preview_user_count = 0;
        $this->import_preview_channel     = null;
        $this->records_preview            = null;
        $this->export_allowed_items       = null;

        // Default action: fetch capabilities only.
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

    /**
     * Processes file upload for file mode import.
     *
     * @param array $settings
     */
    private function process_file_upload( array $settings ) : void {
        if ( ! isset( $_POST['dt_migration_file_upload_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dt_migration_file_upload_nonce'] ) ), 'dt_migration_file_upload' ) ) {
            $this->connection_error = esc_html__( 'Security check failed.', 'disciple-tools-migration' );
            return;
        }

        if ( empty( $_FILES['dt_migration_import_file']['tmp_name'] ) ) {
            $this->connection_error = esc_html__( 'Please select a JSON file to upload.', 'disciple-tools-migration' );
            return;
        }

        $content = file_get_contents( sanitize_text_field( wp_unslash( $_FILES['dt_migration_import_file']['tmp_name'] ) ) );
        if ( $content === false ) {
            $this->connection_error = esc_html__( 'Could not read the uploaded file.', 'disciple-tools-migration' );
            return;
        }

        $payload = json_decode( $content, true );
        if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $payload ) ) {
            $this->connection_error = esc_html__( 'Invalid JSON file.', 'disciple-tools-migration' );
            return;
        }

        $export_block = $payload['export'] ?? [];
        if ( ! is_array( $export_block ) ) {
            $this->connection_error = esc_html__( 'The file does not contain a valid migration export.', 'disciple-tools-migration' );
            return;
        }
        $has_dt_settings = ! empty( $export_block['dt_settings'] );
        $has_users_block = array_key_exists( 'system_users', $export_block ) && is_array( $export_block['system_users'] );
        if ( ! $has_dt_settings && ! $has_users_block ) {
            $this->connection_error = esc_html__( 'The file does not contain a valid migration export (needs settings and/or system user data).', 'disciple-tools-migration' );
            return;
        }

        $transient_key = 'dt_migration_file_payload_' . get_current_user_id();
        set_transient( $transient_key, $payload, 15 * MINUTE_IN_SECONDS );

        $dt_settings   = $payload['export']['dt_settings'] ?? [];
        $post_types    = $dt_settings['dt_post_types_settings']['values'] ?? [];
        $tiles_all     = $dt_settings['dt_tiles_settings']['values'] ?? [];
        $fields_all    = $dt_settings['dt_fields_settings']['values'] ?? [];
        $this->export_allowed_items = $payload['settings']['allowed_items'] ?? [];
        $sys_users     = $payload['export']['system_users']['users'] ?? [];
        $this->import_preview_user_count = is_array( $sys_users ) ? count( $sys_users ) : 0;

        $preview = [];
        foreach ( $post_types as $post_type => $config ) {
            $preview[ $post_type ] = [
                'tiles'  => isset( $tiles_all[ $post_type ] ) ? count( (array) $tiles_all[ $post_type ] ) : 0,
                'fields' => isset( $fields_all[ $post_type ] ) ? count( (array) $fields_all[ $post_type ] ) : 0,
            ];
        }
        $this->settings_preview = $preview;

        $records_raw = $payload['records'] ?? [];
        $this->records_preview = [];
        foreach ( $records_raw as $post_type => $recs ) {
            $this->records_preview[ $post_type ] = [ 'count' => is_array( $recs ) ? count( $recs ) : 0 ];
        }

        $this->import_preview_channel = 'file';
    }
}