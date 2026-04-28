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

        $export_by = $this->sanitize_post_type_assoc_array( 'dt_migration_export_by', 'sanitize_key' );
        $limits    = $this->sanitize_post_type_assoc_array( 'dt_migration_export_limit', 'absint' );
        $min_ids   = $this->sanitize_post_type_assoc_array( 'dt_migration_export_min_id', 'absint' );
        $max_ids   = $this->sanitize_post_type_assoc_array( 'dt_migration_export_max_id', 'absint' );

        $allowed_records = $settings['allowed_items']['records'] ?? [];

        $record_options = Disciple_Tools_Migration_Export_File::parse_download_record_options(
            is_array( $allowed_records ) ? $allowed_records : [],
            $export_by,
            $limits,
            $min_ids,
            $max_ids
        );

        $memory_check = Disciple_Tools_Migration_Export_File::evaluate_file_export_memory( $record_options );
        if ( empty( $memory_check['allowed'] ) ) {
            set_transient( 'dt_migration_export_flash_notice_' . get_current_user_id(), 'file_export_memory', MINUTE_IN_SECONDS );
            wp_safe_redirect( admin_url( 'admin.php?page=disciple_tools_migration&tab=export' ) );
            exit;
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

    /**
     * Reads a POST array keyed by post type with sanitized values.
     *
     * @param string $post_key        Key in $_POST.
     * @param string $value_sanitizer 'sanitize_key' or 'absint'.
     * @return array<string, int|string>
     */
    private function sanitize_post_type_assoc_array( string $post_key, string $value_sanitizer ) : array {
        // Nonce verified in handle_download(); values sanitized per key below.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
        $source = ( isset( $_POST[ $post_key ] ) && is_array( $_POST[ $post_key ] ) ) ? wp_unslash( $_POST[ $post_key ] ) : [];
        $out    = [];
        foreach ( $source as $raw_key => $raw_val ) {
            $key = sanitize_key( (string) $raw_key );
            if ( $key === '' ) {
                continue;
            }
            if ( 'absint' === $value_sanitizer ) {
                $out[ $key ] = absint( $raw_val );
            } else {
                $out[ $key ] = sanitize_key( (string) $raw_val );
            }
        }
        return $out;
    }
}
