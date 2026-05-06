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
        add_action( 'wp_ajax_dt_migration_preflight', [ $this, 'handle_preflight' ] );
        add_action( 'wp_ajax_dt_migration_file_job_delete', [ $this, 'handle_file_job_delete' ] );
        add_action( 'wp_ajax_dt_migration_file_job_complete', [ $this, 'handle_file_job_complete' ] );
        add_action( 'wp_ajax_dt_migration_file_job_failed', [ $this, 'handle_file_job_failed' ] );
        add_action( 'wp_ajax_dt_migration_file_job_cancelled', [ $this, 'handle_file_job_cancelled' ] );
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
            '0.4.0',
            true
        );
        $record_order = class_exists( 'Disciple_Tools_Migration_Import_Engine' )
            ? Disciple_Tools_Migration_Import_Engine::get_record_import_order()
            : [ 'peoplegroups', 'groups', 'contacts', 'trainings' ];
        wp_localize_script(
            'dt-migration-import',
            'dtMigrationImport',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'dt_migration_import' ),
                'recordImportOrder' => array_values( $record_order ),
                'fileJobId' => '',
                'strings' => [
                    'continue'               => __( 'Continue', 'disciple-tools-migration' ),
                    'confirm'                => __( 'Confirm', 'disciple-tools-migration' ),
                    'completedLabel'         => __( 'Completed:', 'disciple-tools-migration' ),
                    'nextLabel'              => __( 'Next:', 'disciple-tools-migration' ),
                    'continueImport'         => __( 'Continue import', 'disciple-tools-migration' ),
                    'confirmImport'          => __( 'Confirm Import', 'disciple-tools-migration' ),
                    'importCompleteWithLog'  => __( 'Import complete. Review logged issues below.', 'disciple-tools-migration' ),
                    'preflightTitle'         => __( 'Preflight results', 'disciple-tools-migration' ),
                    'preflightIntro'         => __( 'These checks are advisory. You can proceed; the import may still log per-record issues.', 'disciple-tools-migration' ),
                    'preflightNoIssues'      => __( 'No preflight warnings for the current selection and sample data.', 'disciple-tools-migration' ),
                    'preflightProceed'       => __( 'Proceed with import', 'disciple-tools-migration' ),
                    'preflightClose'         => __( 'Close', 'disciple-tools-migration' ),
                    'preflightRunning'       => __( 'Running preflight…', 'disciple-tools-migration' ),
                    'preflightFailed'        => __( 'Preflight request failed.', 'disciple-tools-migration' ),
                    'runPreflight'           => __( 'Run preflight', 'disciple-tools-migration' ),
                    'deleteFileJobConfirm'   => __( 'Delete this file migration job and its stored data?', 'disciple-tools-migration' ),
                    'deleteFileJobFailed'    => __( 'Could not delete the job.', 'disciple-tools-migration' ),
                    'preflightFileJobMissing' => __( 'No file migration job is active. Use Upload & Preview or Retry from the job list first.', 'disciple-tools-migration' ),
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
            .dt-migration-modal-content--wide { max-width: 640px; }
            .dt-migration-preflight-field-label { margin: 12px 0 6px; color: #1d2327; font-size: 13px; }
            .dt-migration-preflight-field-label:first-of-type { margin-top: 0; }
            .dt-migration-preflight-textarea { display: block; width: 100%; max-width: 100%; box-sizing: border-box; margin: 0 0 4px; padding: 8px 10px; font-size: 13px; line-height: 1.5; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; color: #1d2327; background: #fff; border: 1px solid #8c8f94; border-radius: 4px; resize: vertical; }
            .dt-migration-preflight-textarea--notes { min-height: 72px; height: 100px; max-height: 200px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; }
            .dt-migration-preflight-textarea--warnings { min-height: 160px; height: 260px; max-height: 420px; overflow: auto; white-space: pre; overflow-wrap: normal; word-break: normal; }
            .dt-migration-preflight-status { font-style: italic; color: #50575e; }
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
            .dt-migration-past-jobs { margin-top: 8px; }
            .dt-migration-past-jobs .dt-migration-job-pill { display: inline-block; padding: 2px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
            .dt-migration-past-jobs .dt-migration-job-pill--success { background: #00a32a; color: #fff; }
            .dt-migration-past-jobs .dt-migration-job-pill--failed { background: #d63638; color: #fff; }
            .dt-migration-past-jobs .dt-migration-job-pill--neutral { background: #dcdcde; color: #1d2327; }
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

            if ( $init_q ) {
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
                if ( ! is_wp_error( $export_res ) ) {
                    $ex_code = wp_remote_retrieve_response_code( $export_res );
                    $ex_body = json_decode( (string) wp_remote_retrieve_body( $export_res ), true );
                    if ( $ex_code >= 200 && $ex_code < 300 && is_array( $ex_body ) ) {
                        Disciple_Tools_Migration_Import_Engine::bootstrap_post_types_from_export( $ex_body );
                    }
                }
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
     * Resolves the file-mode job payload for the current user, or returns an error structure.
     * Callers must verify the AJAX nonce (handle_import_batch, handle_preflight) before this runs.
     *
     * @param int $user_id Current user.
     * @return array{ payload: array, job_id: string }|array{ error: string }
     */
    private function resolve_file_job_payload( int $user_id ) {
        $posted = filter_input( INPUT_POST, 'file_job_id' );
        $raw    = ( is_string( $posted ) && $posted !== '' ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';
        $job_id = Disciple_Tools_Migration_File_Job_Store::sanitize_job_id( $raw );
        if ( $job_id === '' ) {
            return [
                'error' => __( 'No file migration job was specified. Use Upload & Preview or Retry from the job list.', 'disciple-tools-migration' ),
            ];
        }
        $payload = Disciple_Tools_Migration_File_Job_Store::get_payload( $user_id, $job_id );
        if ( $payload === null || ! Disciple_Tools_Migration_File_Job_Store::is_valid_migration_payload( $payload ) ) {
            return [
                'error' => __( 'That migration file is not available. Upload the JSON again or use Retry on a job that still has a stored file.', 'disciple-tools-migration' ),
            ];
        }
        return [ 'payload' => $payload, 'job_id' => $job_id ];
    }

    /**
     * Marks a job as running on the first import step when appropriate.
     *
     * @param int    $user_id
     * @param string $job_id
     * @param string $step
     * @param int    $offset
     * @param bool   $init_records
     * @return void
     */
    private function maybe_mark_file_job_running( int $user_id, string $job_id, string $step, int $offset, bool $init_records ) : void {
        $meta = Disciple_Tools_Migration_File_Job_Store::get_job_meta( $user_id, $job_id );
        if ( $meta === null ) {
            return;
        }
        $st = (string) ( $meta['status'] ?? '' );
        if ( $st === Disciple_Tools_Migration_File_Job_Store::STATUS_RUNNING ) {
            return;
        }
        if ( ! in_array( $st, [ Disciple_Tools_Migration_File_Job_Store::STATUS_READY, Disciple_Tools_Migration_File_Job_Store::STATUS_FAILED, Disciple_Tools_Migration_File_Job_Store::STATUS_CANCELLED ], true ) ) {
            return;
        }
        $is_first = ( $step === 'settings' ) || ( $step === 'records' && $offset === 0 && $init_records );
        if ( ! $is_first ) {
            return;
        }
        Disciple_Tools_Migration_File_Job_Store::set_status( $user_id, $job_id, Disciple_Tools_Migration_File_Job_Store::STATUS_RUNNING );
    }

    /**
     * Handles import batch requests for file mode (payload stored in options per job).
     *
     * @param string $step   'settings' or 'records'
     * @param array  $settings Migration settings.
     */
    private function handle_file_mode_batch( string $step, array $settings ) : void {
        // Nonce verified in handle_import_batch() via check_ajax_referer( 'dt_migration_import', 'nonce' ).
        // phpcs:disable WordPress.Security.NonceVerification.Missing

        $user_id  = get_current_user_id();
        $resolved = $this->resolve_file_job_payload( $user_id );
        if ( isset( $resolved['error'] ) ) {
            wp_send_json_error( [ 'message' => $resolved['error'] ] );
        }
        $payload = $resolved['payload'];
        $job_id  = $resolved['job_id'];

        $post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
        $offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
        $init_q    = ! empty( $_POST['init_records_import'] );
        $this->maybe_mark_file_job_running( $user_id, $job_id, $step, 'records' === $step ? $offset : 0, 'records' === $step && $init_q );

        $export_block = ( is_array( $payload ) && isset( $payload['export'] ) && is_array( $payload['export'] ) ) ? $payload['export'] : [];
        $has_dt       = ! empty( $export_block['dt_settings'] );
        $has_users    = array_key_exists( 'system_users', $export_block ) && is_array( $export_block['system_users'] );
        if ( ! is_array( $payload ) || ( ! $has_dt && ! $has_users ) ) {
            wp_send_json_error( [
                'message' => __( 'That migration file is not available. Upload the JSON again or use Retry on a job that still has a stored file.', 'disciple-tools-migration' ),
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
            $limit = 50;

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

            if ( $init_q ) {
                Disciple_Tools_Migration_Import_Engine::bootstrap_post_types_from_export( $payload );
            }

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

            // Apply per-user private meta (dt_post_user_meta) for the post IDs in this slice.
            $slice_post_ids = [];
            foreach ( $slice as $rec ) {
                if ( isset( $rec['ID'] ) ) {
                    $slice_post_ids[] = (int) $rec['ID'];
                }
            }
            $pum_rows   = $payload['post_user_meta'][ $post_type ] ?? [];
            $pum_result = Disciple_Tools_Migration_Import_Engine::import_post_user_meta_for_posts(
                is_array( $pum_rows ) ? $pum_rows : [],
                $slice_post_ids
            );
            if ( ! empty( $pum_result['errors'] ) ) {
                $batch_result['errors'] = array_merge( $batch_result['errors'] ?? [], $pum_result['errors'] );
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

        // phpcs:enable WordPress.Security.NonceVerification.Missing

        wp_send_json_error( [ 'message' => __( 'Invalid step.', 'disciple-tools-migration' ) ] );
    }

    /**
     * AJAX: non-destructive preflight warnings for the current import selection.
     */
    public function handle_preflight() : void {
        check_ajax_referer( 'dt_migration_import', 'nonce' );

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'disciple-tools-migration' ) ] );
        }

        $settings = Disciple_Tools_Migration_Menu::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Migration is not enabled.', 'disciple-tools-migration' ) ] );
        }

        $channel = isset( $_POST['import_channel'] ) ? sanitize_key( wp_unslash( $_POST['import_channel'] ) ) : '';
        if ( $channel !== 'file' && $channel !== 'api' ) {
            $channel = 'api';
        }

        $selected_settings = isset( $_POST['settings_selected'] ) && is_array( $_POST['settings_selected'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['settings_selected'] ) )
            : [];
        $settings_map        = array_fill_keys( $selected_settings, true );
        $records_selected_in = isset( $_POST['records_selected'] ) && is_array( $_POST['records_selected'] )
            ? array_map( 'sanitize_key', wp_unslash( $_POST['records_selected'] ) )
            : [];

        if ( empty( $selected_settings ) && empty( $records_selected_in ) ) {
            wp_send_json_error( [ 'message' => __( 'Select at least one setting or record type for preflight.', 'disciple-tools-migration' ) ] );
        }

        if ( $channel === 'file' ) {
            $result = $this->preflight_file_payload( $settings_map, $records_selected_in );
        } else {
            $result = $this->preflight_api_payload( $settings, $settings_map, $records_selected_in );
        }

        if ( isset( $result['error'] ) ) {
            wp_send_json_error( [ 'message' => $result['error'] ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Preflight using uploaded JSON transient.
     *
     * @param array<string, bool> $settings_map       Selected settings.
     * @param string[]           $records_selected_in Post types.
     * @return array|array{ error: string }
     */
    private function preflight_file_payload( array $settings_map, array $records_selected_in ) : array {
        $user_id  = get_current_user_id();
        $resolved = $this->resolve_file_job_payload( $user_id );
        if ( isset( $resolved['error'] ) ) {
            return [ 'error' => $resolved['error'] ];
        }
        $payload = $resolved['payload'];

        $export_block = ( is_array( $payload ) && isset( $payload['export'] ) && is_array( $payload['export'] ) ) ? $payload['export'] : [];
        $has_dt       = ! empty( $export_block['dt_settings'] );
        $has_users    = array_key_exists( 'system_users', $export_block ) && is_array( $export_block['system_users'] );
        if ( ! is_array( $payload ) || ( ! $has_dt && ! $has_users ) ) {
            return [ 'error' => __( 'That migration file is not available. Upload the JSON again or use Retry on a job that still has a stored file.', 'disciple-tools-migration' ) ];
        }

        $records_all = isset( $payload['records'] ) && is_array( $payload['records'] ) ? $payload['records'] : [];
        $records     = [];
        foreach ( $records_selected_in as $pt ) {
            if ( isset( $records_all[ $pt ] ) && is_array( $records_all[ $pt ] ) ) {
                $records[ $pt ] = $records_all[ $pt ];
            }
        }

        $analysis = Disciple_Tools_Migration_Preflight::analyze(
            [
                'export'            => $export_block,
                'records'           => $records,
                'records_sampled'   => false,
                'settings_selected' => $settings_map,
                'records_selected'  => $records_selected_in,
            ]
        );

        return [
            'warnings' => $analysis['warnings'],
            'info'     => $analysis['info'],
        ];
    }

    /**
     * Preflight using Server A export + sampled record batches.
     *
     * @param array               $settings           Plugin settings.
     * @param array<string, bool> $settings_map       Selected settings.
     * @param string[]           $records_selected_in Post types.
     * @return array|array{ error: string }
     */
    private function preflight_api_payload( array $settings, array $settings_map, array $records_selected_in ) : array {
        $remote_url = $settings['api']['remote_base_url'] ?? '';
        $jwt        = $settings['api']['jwt_token'] ?? '';
        $token_at   = (int) ( $settings['api']['jwt_token_set_at'] ?? 0 );

        if ( empty( $remote_url ) || empty( $jwt ) ) {
            return [ 'error' => __( 'Not connected to Server A. Run Test Connection first.', 'disciple-tools-migration' ) ];
        }

        if ( $token_at < ( time() - HOUR_IN_SECONDS ) ) {
            return [ 'error' => __( 'JWT token expired. Please re-run Test Connection.', 'disciple-tools-migration' ) ];
        }

        $base = rtrim( $remote_url, '/' );

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
            return [ 'error' => $export_res->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $export_res );
        $body = json_decode( (string) wp_remote_retrieve_body( $export_res ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
            return [ 'error' => __( 'Failed to fetch export from Server A.', 'disciple-tools-migration' ) ];
        }

        $export_block = isset( $body['export'] ) && is_array( $body['export'] ) ? $body['export'] : [];

        $records        = [];
        $records_sample = false;
        $fetch_notes    = [];
        foreach ( $records_selected_in as $pt ) {
            $records_res = wp_remote_get(
                add_query_arg(
                    [ 'offset' => 0, 'limit' => 100 ],
                    $base . '/wp-json/dt-migration/v1/records/' . rawurlencode( $pt )
                ),
                [
                    'timeout' => 60,
                    'headers' => [ 'Authorization' => 'Bearer ' . $jwt ],
                ]
            );
            if ( is_wp_error( $records_res ) ) {
                $fetch_notes[] = sprintf(
                    /* translators: %s: post type slug */
                    __( 'Could not fetch sample records for "%s" from Server A.', 'disciple-tools-migration' ),
                    $pt
                );
                continue;
            }
            $rc = wp_remote_retrieve_response_code( $records_res );
            $rb = json_decode( (string) wp_remote_retrieve_body( $records_res ), true );
            if ( $rc < 200 || $rc >= 300 || ! is_array( $rb ) ) {
                $fetch_notes[] = sprintf(
                    /* translators: %s: post type slug */
                    __( 'Server A returned an error when fetching sample records for "%s".', 'disciple-tools-migration' ),
                    $pt
                );
                continue;
            }
            $rec = isset( $rb['records'] ) && is_array( $rb['records'] ) ? $rb['records'] : [];
            if ( ! empty( $rec ) ) {
                $records[ $pt ] = $rec;
            }
            if ( ! empty( $rb['has_more'] ) ) {
                $records_sample = true;
            }
        }

        $analysis = Disciple_Tools_Migration_Preflight::analyze(
            [
                'export'            => $export_block,
                'records'           => $records,
                'records_sampled'   => $records_sample,
                'settings_selected' => $settings_map,
                'records_selected'  => $records_selected_in,
            ]
        );

        $info_out = $analysis['info'];
        if ( ! empty( $fetch_notes ) ) {
            $info_out = array_merge( $info_out, $fetch_notes );
        }

        return [
            'warnings' => $analysis['warnings'],
            'info'     => $info_out,
        ];
    }

    /**
     * AJAX: remove a file migration job and its stored payload.
     *
     * @return void
     */
    public function handle_file_job_delete() : void {
        check_ajax_referer( 'dt_migration_import', 'nonce' );

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'disciple-tools-migration' ) ] );
        }

        $posted = filter_input( INPUT_POST, 'job_id' );
        $raw    = ( is_string( $posted ) && $posted !== '' ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';
        $job_id = Disciple_Tools_Migration_File_Job_Store::sanitize_job_id( $raw );
        if ( $job_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job.', 'disciple-tools-migration' ) ] );
        }

        Disciple_Tools_Migration_File_Job_Store::delete_job( get_current_user_id(), $job_id );
        wp_send_json_success( [ 'deleted' => true ] );
    }

    /**
     * AJAX: mark a file job successful and clear stored JSON.
     *
     * @return void
     */
    public function handle_file_job_complete() : void {
        check_ajax_referer( 'dt_migration_import', 'nonce' );

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'disciple-tools-migration' ) ] );
        }

        $posted = filter_input( INPUT_POST, 'file_job_id' );
        $raw    = ( is_string( $posted ) && $posted !== '' ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';
        $job_id = Disciple_Tools_Migration_File_Job_Store::sanitize_job_id( $raw );
        if ( $job_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job.', 'disciple-tools-migration' ) ] );
        }

        Disciple_Tools_Migration_File_Job_Store::mark_success_and_clear_payload( get_current_user_id(), $job_id );
        wp_send_json_success( [ 'ok' => true ] );
    }

    /**
     * AJAX: mark a file job as failed (payload kept for retry).
     *
     * @return void
     */
    public function handle_file_job_failed() : void {
        check_ajax_referer( 'dt_migration_import', 'nonce' );

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'disciple-tools-migration' ) ] );
        }

        $posted = filter_input( INPUT_POST, 'file_job_id' );
        $raw    = ( is_string( $posted ) && $posted !== '' ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';
        $job_id = Disciple_Tools_Migration_File_Job_Store::sanitize_job_id( $raw );
        if ( $job_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job.', 'disciple-tools-migration' ) ] );
        }

        Disciple_Tools_Migration_File_Job_Store::set_status( get_current_user_id(), $job_id, Disciple_Tools_Migration_File_Job_Store::STATUS_FAILED );
        wp_send_json_success( [ 'ok' => true ] );
    }

    /**
     * AJAX: mark a file job as user-cancelled.
     *
     * @return void
     */
    public function handle_file_job_cancelled() : void {
        check_ajax_referer( 'dt_migration_import', 'nonce' );

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'disciple-tools-migration' ) ] );
        }

        $posted = filter_input( INPUT_POST, 'file_job_id' );
        $raw    = ( is_string( $posted ) && $posted !== '' ) ? sanitize_text_field( wp_unslash( $posted ) ) : '';
        $job_id = Disciple_Tools_Migration_File_Job_Store::sanitize_job_id( $raw );
        if ( $job_id === '' ) {
            wp_send_json_error( [ 'message' => __( 'Invalid job.', 'disciple-tools-migration' ) ] );
        }

        Disciple_Tools_Migration_File_Job_Store::set_status( get_current_user_id(), $job_id, Disciple_Tools_Migration_File_Job_Store::STATUS_CANCELLED );
        wp_send_json_success( [ 'ok' => true ] );
    }
}
