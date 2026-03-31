<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Disciple_Tools_Migration_Import_Ajax
 *
 * AJAX handlers for chunked import flow (settings + records).
 */
class Disciple_Tools_Migration_Import_Ajax {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'wp_ajax_dt_migration_import_batch', [ $this, 'handle_import_batch' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }

    /**
     * Enqueues import JS and CSS on the Migration Import tab.
     *
     * @param string $hook
     */
    public function enqueue_scripts( string $hook ) : void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $is_import_page = ( $page === 'disciple_tools_migration_import' );
        $is_migration_page = ( strpos( $hook, 'disciple_tools_migration' ) !== false );
        if ( ! $is_import_page && ! $is_migration_page ) {
            return;
        }

        $plugin_url = plugin_dir_url( dirname( __FILE__ ) );
        wp_enqueue_script(
            'dt-migration-import',
            $plugin_url . 'admin/js/import.js',
            [ 'jquery' ],
            '0.3.2',
            true
        );
        wp_localize_script(
            'dt-migration-import',
            'dtMigrationImport',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'dt_migration_import' ),
                'strings' => [
                    'continue'               => __( 'Continue', 'disciple-tools-migration' ),
                    'confirm'                => __( 'Confirm', 'disciple-tools-migration' ),
                    'completedLabel'         => __( 'Completed:', 'disciple-tools-migration' ),
                    'nextLabel'              => __( 'Next:', 'disciple-tools-migration' ),
                    'continueImport'         => __( 'Continue import', 'disciple-tools-migration' ),
                    'confirmImport'          => __( 'Confirm Import', 'disciple-tools-migration' ),
                    'importCompleteWithLog'  => __( 'Import complete. Review logged issues below.', 'disciple-tools-migration' ),
                ],
            ]
        );
        wp_add_inline_style( 'wp-admin', $this->get_modal_css() );
    }

    /**
     * Returns inline CSS for the modal and progress UI.
     *
     * @return string
     */
    private function get_modal_css() : string {
        return '
            .dt-migration-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; }
            .dt-migration-modal-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.5); }
            .dt-migration-modal-content { position: relative; background: #fff; padding: 24px; max-width: 500px; width: 90%; box-shadow: 0 4px 20px rgba(0,0,0,0.2); border-radius: 4px; }
            .dt-migration-modal-body { margin-top: 16px; }
            .dt-migration-modal-warning { color: #b32d2e; font-weight: 600; }
            .dt-migration-modal-summary { margin: 12px 0; padding: 12px; background: #f0f0f1; border-radius: 4px; font-size: 13px; }
            .dt-migration-modal--slim .dt-migration-modal-summary p { margin: 0 0 10px; }
            .dt-migration-modal--slim .dt-migration-modal-summary p:last-child { margin-bottom: 0; }
            .dt-migration-confirm-input { width: 100%; margin: 8px 0 16px; padding: 8px; }
            .dt-migration-modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
            .dt-migration-progress-panel { margin-top: 20px; padding: 20px; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 4px; }
            .dt-migration-progress-bar-wrap { display: flex; align-items: center; gap: 12px; }
            .dt-migration-import-spinner { display: inline-block; width: 22px; height: 22px; flex-shrink: 0; box-sizing: border-box; border: 2px solid rgba(34, 113, 177, 0.2); border-top-color: #2271b1; border-radius: 50%; animation: dt-migration-spin 0.65s linear infinite; vertical-align: middle; }
            .dt-migration-import-spinner[hidden] { display: none !important; }
            @keyframes dt-migration-spin { to { transform: rotate(360deg); } }
            .dt-migration-progress-bar { flex: 1; height: 24px; background: #ddd; border-radius: 4px; overflow: hidden; }
            .dt-migration-progress-fill { display: block; height: 100%; background: #2271b1; width: 0%; transition: width 0.2s; }
            .dt-migration-step-list { margin: 16px 0; padding-left: 24px; }
            .dt-migration-step-list .done { color: #00a32a; }
            .dt-migration-step-list .active { font-weight: 600; }
            .dt-migration-current-phase { margin-top: 8px; font-style: italic; color: #50575e; }
            .dt-migration-error-details { margin-top: 16px; padding: 12px; background: #fcf0f1; border: 1px solid #d63638; border-radius: 4px; }
            .dt-migration-error-details strong { display: block; margin-bottom: 8px; color: #b32d2e; }
            .dt-migration-error-scroll { max-height: 200px; overflow-y: auto; padding: 8px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; white-space: pre-wrap; font-size: 12px; line-height: 1.4; }
        ';
    }

    /**
     * Handles the import batch AJAX request.
     *
     * Supports both API mode (fetch from remote) and file mode (use transient payload).
     */
    public function handle_import_batch() : void {
        check_ajax_referer( 'dt_migration_import', 'nonce' );

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'disciple-tools-migration' ) ] );
        }

        $settings = Disciple_Tools_Migration_Menu::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Migration is not enabled.', 'disciple-tools-migration' ) ] );
        }

        $step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : '';

        if ( $step === 'apply_deferred_connections' ) {
            $conn_result = Disciple_Tools_Migration_Import_Engine::apply_all_deferred_connections();
            wp_send_json_success( [
                'done'                => true,
                'phase'               => 'connections',
                'applied'             => $conn_result['applied'] ?? 0,
                'connection_errors'   => $conn_result['errors'] ?? [],
            ] );
        }

        $channel = isset( $_POST['import_channel'] ) ? sanitize_key( wp_unslash( $_POST['import_channel'] ) ) : '';
        if ( $channel !== 'file' && $channel !== 'api' ) {
            $channel = 'api';
        }

        if ( $channel === 'file' ) {
            $this->handle_file_mode_batch( $step, $settings );
            return;
        }

        $remote_url = $settings['api']['remote_base_url'] ?? '';
        $jwt       = $settings['api']['jwt_token'] ?? '';
        $token_at  = (int) ( $settings['api']['jwt_token_set_at'] ?? 0 );

        if ( empty( $remote_url ) || empty( $jwt ) ) {
            wp_send_json_error( [ 'message' => __( 'Not connected to Server A. Run Test Connection first.', 'disciple-tools-migration' ) ] );
        }

        if ( $token_at < ( time() - HOUR_IN_SECONDS ) ) {
            wp_send_json_error( [ 'message' => __( 'JWT token expired. Please re-run Test Connection.', 'disciple-tools-migration' ) ] );
        }

        $base = rtrim( $remote_url, '/' );

        if ( $step === 'settings' ) {
            $selected = isset( $_POST['settings_selected'] ) && is_array( $_POST['settings_selected'] )
                ? array_map( 'sanitize_key', wp_unslash( $_POST['settings_selected'] ) )
                : [];

            $export_res = wp_remote_post(
                $base . '/wp-json/dt-migration/v1/export',
                [
                    'timeout' => 60,
                    'headers' => [
                        'Authorization' => 'Bearer ' . $jwt,
                        'Content-Type'  => 'application/json',
                    ],
                    'body'    => wp_json_encode( [ 'settings_only' => true ] ),
                ]
            );

            if ( is_wp_error( $export_res ) ) {
                wp_send_json_error( [ 'message' => $export_res->get_error_message() ] );
            }

            $code = wp_remote_retrieve_response_code( $export_res );
            $body = json_decode( (string) wp_remote_retrieve_body( $export_res ), true );
            if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
                wp_send_json_error( [ 'message' => __( 'Failed to fetch export from Server A.', 'disciple-tools-migration' ) ] );
            }

            $selected_map = array_fill_keys( $selected, true );
            $result       = Disciple_Tools_Migration_Import_Engine::import_settings( $body, $selected_map );

            if ( ! empty( $result['errors'] ) ) {
                wp_send_json_error( [
                    'message' => implode( "\n", $result['errors'] ),
                    'applied' => $result['applied'] ?? [],
                ] );
            }

            Disciple_Tools_Migration_Import_Engine::clear_deferred_connection_queue();

            wp_send_json_success( [
                'done'    => true,
                'phase'   => 'settings',
                'applied' => $result['applied'] ?? [],
            ] );
        }

        if ( $step === 'records' ) {
            $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
            $offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
            $limit     = 50;
            $init_q    = ! empty( $_POST['init_records_import'] );

            if ( empty( $post_type ) ) {
                wp_send_json_error( [ 'message' => __( 'Post type required.', 'disciple-tools-migration' ) ] );
            }

            $records_url = add_query_arg(
                [ 'offset' => $offset, 'limit' => $limit ],
                $base . '/wp-json/dt-migration/v1/records/' . $post_type
            );

            $records_res = wp_remote_get(
                $records_url,
                [
                    'timeout' => 60,
                    'headers' => [ 'Authorization' => 'Bearer ' . $jwt ],
                ]
            );

            if ( is_wp_error( $records_res ) ) {
                wp_send_json_error( [ 'message' => $records_res->get_error_message() ] );
            }

            $code   = wp_remote_retrieve_response_code( $records_res );
            $rbody  = json_decode( (string) wp_remote_retrieve_body( $records_res ), true );
            $recs   = $rbody['records'] ?? [];
            $total  = (int) ( $rbody['total'] ?? 0 );
            $has_more = ! empty( $rbody['has_more'] );

            $batch_result = Disciple_Tools_Migration_Import_Engine::import_records_batch( $post_type, $recs, $offset, $init_q );

            if ( ! empty( $batch_result['fatal'] ) ) {
                wp_send_json_error( [
                    'message'  => implode( "\n", $batch_result['errors'] ),
                    'imported' => $batch_result['imported'] ?? 0,
                ] );
            }

            wp_send_json_success( [
                'done'          => ! $has_more,
                'phase'         => 'records',
                'post_type'     => $post_type,
                'imported'      => $batch_result['imported'],
                'offset'        => $offset,
                'total'         => $total,
                'has_more'      => $has_more,
                'next_offset'   => $offset + count( $recs ),
                'record_errors' => $batch_result['errors'] ?? [],
            ] );
        }

        wp_send_json_error( [ 'message' => __( 'Invalid step.', 'disciple-tools-migration' ) ] );
    }

    /**
     * Handles import batch requests for file mode (payload in transient).
     *
     * @param string $step   'settings' or 'records'
     * @param array  $settings Migration settings.
     */
    private function handle_file_mode_batch( string $step, array $settings ) : void {
        $transient_key = 'dt_migration_file_payload_' . get_current_user_id();
        $payload       = get_transient( $transient_key );

        $export_block = ( is_array( $payload ) && isset( $payload['export'] ) && is_array( $payload['export'] ) ) ? $payload['export'] : [];
        $has_dt       = ! empty( $export_block['dt_settings'] );
        $has_users    = array_key_exists( 'system_users', $export_block ) && is_array( $export_block['system_users'] );
        if ( ! is_array( $payload ) || ( ! $has_dt && ! $has_users ) ) {
            wp_send_json_error( [
                'message' => __( 'No migration file loaded or payload expired. Please upload the file again.', 'disciple-tools-migration' ),
            ] );
        }

        if ( $step === 'settings' ) {
            $selected = isset( $_POST['settings_selected'] ) && is_array( $_POST['settings_selected'] )
                ? array_map( 'sanitize_key', wp_unslash( $_POST['settings_selected'] ) )
                : [];

            $selected_map = array_fill_keys( $selected, true );
            $result       = Disciple_Tools_Migration_Import_Engine::import_settings( $payload, $selected_map );

            if ( ! empty( $result['errors'] ) ) {
                wp_send_json_error( [
                    'message' => implode( "\n", $result['errors'] ),
                    'applied' => $result['applied'] ?? [],
                ] );
            }

            Disciple_Tools_Migration_Import_Engine::clear_deferred_connection_queue();

            wp_send_json_success( [
                'done'    => true,
                'phase'   => 'settings',
                'applied' => $result['applied'] ?? [],
            ] );
        }

        if ( $step === 'records' ) {
            $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
            $offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
            $limit     = 50;
            $init_q    = ! empty( $_POST['init_records_import'] );

            if ( empty( $post_type ) ) {
                wp_send_json_error( [ 'message' => __( 'Post type required.', 'disciple-tools-migration' ) ] );
            }

            $records_all = $payload['records'][ $post_type ] ?? [];
            if ( ! is_array( $records_all ) ) {
                $records_all = [];
            }

            $total     = count( $records_all );
            $slice     = array_slice( $records_all, $offset, $limit );
            $has_more  = ( $offset + count( $slice ) ) < $total;

            try {
                $batch_result = Disciple_Tools_Migration_Import_Engine::import_records_batch( $post_type, $slice, $offset, $init_q );
            } catch ( Throwable $e ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: 1: error message, 2: file and line */
                        __( 'Import failed: %1$s (%2$s)', 'disciple-tools-migration' ),
                        $e->getMessage(),
                        $e->getFile() . ':' . $e->getLine()
                    ),
                ] );
            }

            if ( ! empty( $batch_result['fatal'] ) ) {
                wp_send_json_error( [
                    'message'  => implode( "\n", $batch_result['errors'] ),
                    'imported' => $batch_result['imported'] ?? 0,
                ] );
            }

            wp_send_json_success( [
                'done'            => ! $has_more,
                'phase'           => 'records',
                'post_type'       => $post_type,
                'imported'        => $batch_result['imported'],
                'offset'          => $offset,
                'total'           => $total,
                'has_more'        => $has_more,
                'next_offset'     => $offset + count( $slice ),
                'record_errors'   => $batch_result['errors'] ?? [],
            ] );
        }

        wp_send_json_error( [ 'message' => __( 'Invalid step.', 'disciple-tools-migration' ) ] );
    }
}
