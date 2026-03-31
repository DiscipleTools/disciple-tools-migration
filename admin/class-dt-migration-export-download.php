<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Disciple_Tools_Migration_Export_Download
 *
 * Handles the download of migration export JSON files.
 */
class Disciple_Tools_Migration_Export_Download {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_post_dt_migration_download_export', [ $this, 'handle_download' ] );
    }

    /**
     * Handles the export file download request.
     */
    public function handle_download() : void {
        if ( ! isset( $_POST['dt_migration_download_export_nonce'] ) ) {
            wp_die( esc_html__( 'Invalid request.', 'disciple-tools-migration' ) );
        }

        if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['dt_migration_download_export_nonce'] ) ), 'dt_migration_download_export' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'disciple-tools-migration' ) );
        }

        if ( ! current_user_can( 'manage_dt' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'disciple-tools-migration' ) );
        }

        $settings = Disciple_Tools_Migration_Menu::get_settings();
        if ( empty( $settings['enabled'] ) ) {
            wp_die( esc_html__( 'Migration is not enabled.', 'disciple-tools-migration' ) );
        }

        $record_options = [];
        $export_by  = isset( $_POST['dt_migration_export_by'] ) && is_array( $_POST['dt_migration_export_by'] )
            ? wp_unslash( $_POST['dt_migration_export_by'] )
            : [];
        $limits  = isset( $_POST['dt_migration_export_limit'] ) && is_array( $_POST['dt_migration_export_limit'] )
            ? wp_unslash( $_POST['dt_migration_export_limit'] )
            : [];
        $min_ids = isset( $_POST['dt_migration_export_min_id'] ) && is_array( $_POST['dt_migration_export_min_id'] )
            ? wp_unslash( $_POST['dt_migration_export_min_id'] )
            : [];
        $max_ids = isset( $_POST['dt_migration_export_max_id'] ) && is_array( $_POST['dt_migration_export_max_id'] )
            ? wp_unslash( $_POST['dt_migration_export_max_id'] )
            : [];

        $allowed_records = $settings['allowed_items']['records'] ?? [];
        foreach ( $allowed_records as $post_type => $enabled ) {
            if ( ! $enabled ) {
                continue;
            }
            $raw_mode = isset( $export_by[ $post_type ] ) ? sanitize_key( (string) $export_by[ $post_type ] ) : 'all';
            if ( $raw_mode === 'limit' ) {
                $mode = 'limit';
            } elseif ( $raw_mode === 'range' ) {
                $mode = 'range';
            } else {
                $mode = 'all';
            }
            if ( $mode === 'all' ) {
                continue;
            }
            $limit  = $mode === 'limit' ? absint( $limits[ $post_type ] ?? 0 ) : 0;
            $min_id = $mode === 'range' ? absint( $min_ids[ $post_type ] ?? 0 ) : 0;
            $max_id = $mode === 'range' ? absint( $max_ids[ $post_type ] ?? 0 ) : 0;
            if ( $limit > 0 || $min_id > 0 || $max_id > 0 ) {
                $record_options[ $post_type ] = [
                    'limit'  => $limit,
                    'min_id' => $min_id,
                    'max_id' => $max_id,
                ];
            }
        }

        $payload = Disciple_Tools_Migration_Export_File::build_export( $record_options );

        if ( isset( $payload['error'] ) ) {
            wp_die( esc_html( $payload['error'] ) );
        }

        $filename = 'dt-migration-export-' . gmdate( 'Y-m-d-His' ) . '.json';
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );

        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }
}
