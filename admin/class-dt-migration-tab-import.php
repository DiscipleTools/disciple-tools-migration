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

                                <!-- Show settings preview button only after a successful connection -->
                                <form method="post" style="margin-top: 20px;">
                                    <?php wp_nonce_field( 'dt_migration_import_connection_form', 'dt_migration_import_connection_form_nonce' ); ?>
                                    <button class="button" name="dt_migration_action" value="settings_preview">
                                        <?php esc_html_e( 'Fetch Settings Export Preview', 'disciple-tools-migration' ); ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <?php if ( ! empty( $this->settings_preview ) ) : ?>
                                <h3><?php esc_html_e( 'Server A Settings Export Preview', 'disciple-tools-migration' ); ?></h3>

                                <?php
                                // Use allowed_items from export response (same request that produced the preview).
                                $allowed          = $this->export_allowed_items ?? $this->connection_result['allowed_items'] ?? [];
                                $dt_settings      = $this->settings_preview;
                                $records_preview  = $this->records_preview ?? [];
                                $post_type_count  = is_array( $records_preview ) ? count( $records_preview ) : ( is_array( $dt_settings ) ? count( $dt_settings ) : 0 );
                                ?>

                                <!-- Table 1: Settings Summary -->
                                <table class="widefat striped" style="margin-bottom: 20px;">
                                    <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Setting Type', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Enabled', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Notes', 'disciple-tools-migration' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <tr>
                                        <td><?php esc_html_e( 'General Settings', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo ! empty( $allowed['general_settings'] ) ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Custom Lists', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo ! empty( $allowed['custom_lists'] ) ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Tiles', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo ! empty( $allowed['tiles'] ) ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                        <td>
                                            <?php
                                            if ( ! empty( $allowed['tiles'] ) ) {
                                                printf(
                                                    esc_html__( 'Tiles defined for %d post types.', 'disciple-tools-migration' ),
                                                    $post_type_count
                                                );
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Fields', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo ! empty( $allowed['fields'] ) ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                        <td>
                                            <?php
                                            if ( ! empty( $allowed['fields'] ) ) {
                                                printf(
                                                    esc_html__( 'Fields defined for %d post types.', 'disciple-tools-migration' ),
                                                    $post_type_count
                                                );
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Roles', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo ! empty( $allowed['roles'] ) ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                        <td></td>
                                    </tr>
                                    <tr>
                                        <td><?php esc_html_e( 'Workflows', 'disciple-tools-migration' ); ?></td>
                                        <td><?php echo ! empty( $allowed['workflows'] ) ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                        <td></td>
                                    </tr>
                                    </tbody>
                                </table>

                                <!-- Table 2: Record Types Preview (driven by records_preview payload only) -->
                                <table class="widefat striped">
                                    <thead>
                                    <tr>
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
                                        $summary     = $dt_preview[ $post_type ] ?? [ 'tiles' => 0, 'fields' => 0 ];
                                        $record_count = isset( $record_data['count'] ) ? (int) $record_data['count'] : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo esc_html( $post_type ); ?></td>
                                            <td><?php echo isset( $summary['tiles'] ) ? intval( $summary['tiles'] ) : 0; ?></td>
                                            <td><?php echo isset( $summary['fields'] ) ? intval( $summary['fields'] ) : 0; ?></td>
                                            <td><?php echo $record_count; ?></td>
                                        </tr>
                                        <?php
                                    }
                                    ?>
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
     * Processes the Import tab forms for API mode.
     *
     * Handles both:
     * - "Test Connection & Fetch Capabilities"
     * - "Fetch Settings Export Preview"
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
        $this->settings_preview = null;

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
}