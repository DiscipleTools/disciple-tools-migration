<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Persists file-mode migration payloads and job metadata (per user).
 *
 * Payloads are stored in non-autoloaded options so large imports are not
 * loaded on every request. A registry option lists jobs and status for the UI.
 */
class Disciple_Tools_Migration_File_Job_Store {

    public const REGISTRY_OPTION = 'dt_migration_file_job_registry';

    public const PAYLOAD_KEY_PREFIX = 'dt_migration_fjp_';

    /**
     * @var string Job states for UI and retention.
     */
    public const STATUS_READY = 'ready';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Default max age in days (overridden by Settings > File import jobs).
     */
    public const MAX_AGE_DAYS_DEFAULT = 7;

    /**
     * Sanitize a job id (UUID).
     *
     * @param string $job_id
     * @return string
     */
    public static function sanitize_job_id( string $job_id ) : string {
        $job_id = strtolower( trim( $job_id ) );
        if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $job_id ) ) {
            return '';
        }
        return $job_id;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function get_registry() : array {
        $raw = get_option( self::REGISTRY_OPTION, [] );
        return is_array( $raw ) ? $raw : [];
    }

    /**
     * @param array<string, array<string, mixed>> $registry
     * @return void
     */
    private static function save_registry( array $registry ) : void {
        if ( $registry === [] ) {
            delete_option( self::REGISTRY_OPTION );
            return;
        }
        if ( get_option( self::REGISTRY_OPTION, null ) === false ) {
            add_option( self::REGISTRY_OPTION, $registry, '', 'no' );
        } else {
            update_option( self::REGISTRY_OPTION, $registry, false );
        }
    }

    /**
     * @param string $job_id
     * @return string
     */
    public static function payload_option_name( string $job_id ) : string {
        return self::PAYLOAD_KEY_PREFIX . $job_id;
    }

    /**
     * @param int   $user_id
     * @param array $payload  Full migration JSON (decoded).
     * @param string $label  Original filename or short label.
     * @return string Job id (UUID) or empty string on failure.
     */
    public static function create_job( int $user_id, array $payload, string $label ) : string {
        $job_id = self::sanitize_job_id( wp_generate_uuid4() );
        if ( $job_id === '' ) {
            return '';
        }

        $key = self::payload_option_name( $job_id );
        $ok  = add_option( $key, $payload, '', 'no' );
        if ( ! $ok ) {
            delete_option( $key );
            if ( ! add_option( $key, $payload, '', 'no' ) ) {
                return '';
            }
        }

        $json = wp_json_encode( $payload );
        $size = is_string( $json ) ? strlen( $json ) : 0;

        $registry = self::get_registry();
        $now      = time();
        $registry[ $job_id ] = [
            'user_id'    => $user_id,
            'stored_at'  => $now,
            'updated_at' => $now,
            'status'     => self::STATUS_READY,
            'label'      => sanitize_file_name( $label ),
            'byte_size'  => $size,
        ];
        self::save_registry( $registry );

        return $job_id;
    }

    /**
     * @param int    $user_id
     * @param string $job_id
     * @return array<string, mixed>|null
     */
    public static function get_job_meta( int $user_id, string $job_id ) : ?array {
        $job_id = self::sanitize_job_id( $job_id );
        if ( $job_id === '' ) {
            return null;
        }
        $registry = self::get_registry();
        $row      = $registry[ $job_id ] ?? null;
        if ( ! is_array( $row ) || (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
            return null;
        }
        return $row;
    }

    /**
     * @param int    $user_id
     * @param string $job_id
     * @return array<string, mixed>|null Full payload or null.
     */
    public static function job_has_stored_payload( string $job_id ) : bool {
        $job_id = self::sanitize_job_id( $job_id );
        if ( $job_id === '' ) {
            return false;
        }
        $name = self::payload_option_name( $job_id );
        $val  = get_option( $name, null );
        return is_array( $val ) && ! empty( $val );
    }

    /**
     * @param int    $user_id
     * @param string $job_id
     * @return array<string, mixed>|null Full payload or null.
     */
    public static function get_payload( int $user_id, string $job_id ) : ?array {
        $job_id = self::sanitize_job_id( $job_id );
        if ( $job_id === '' ) {
            return null;
        }
        if ( self::get_job_meta( $user_id, $job_id ) === null ) {
            return null;
        }
        $name = self::payload_option_name( $job_id );
        $val  = get_option( $name, null );
        if ( ! is_array( $val ) ) {
            return null;
        }
        return $val;
    }

    /**
     * @param int    $user_id
     * @param string $job_id
     * @return bool
     */
    public static function job_exists_for_user( int $user_id, string $job_id ) : bool {
        return self::get_job_meta( $user_id, $job_id ) !== null;
    }

    /**
     * @param int    $user_id
     * @param string $status
     * @return void
     */
    public static function set_status( int $user_id, string $job_id, string $status ) : void {
        $job_id = self::sanitize_job_id( $job_id );
        if ( $job_id === '' ) {
            return;
        }
        $allowed = [
            self::STATUS_READY,
            self::STATUS_RUNNING,
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
        ];
        if ( ! in_array( $status, $allowed, true ) ) {
            return;
        }
        $registry = self::get_registry();
        if ( ! isset( $registry[ $job_id ] ) || (int) ( $registry[ $job_id ]['user_id'] ?? 0 ) !== $user_id ) {
            return;
        }
        $registry[ $job_id ]['status']     = $status;
        $registry[ $job_id ]['updated_at'] = time();
        self::save_registry( $registry );
    }

    /**
     * Marks success and drops the stored payload to save space; keeps registry row.
     *
     * @param int    $user_id
     * @param string $job_id
     * @return void
     */
    public static function mark_success_and_clear_payload( int $user_id, string $job_id ) : void {
        $job_id = self::sanitize_job_id( $job_id );
        if ( $job_id === '' ) {
            return;
        }
        $registry = self::get_registry();
        if ( ! isset( $registry[ $job_id ] ) || (int) ( $registry[ $job_id ]['user_id'] ?? 0 ) !== $user_id ) {
            return;
        }
        delete_option( self::payload_option_name( $job_id ) );
        $registry[ $job_id ]['status']     = self::STATUS_SUCCESS;
        $registry[ $job_id ]['byte_size']  = 0;
        $registry[ $job_id ]['updated_at'] = time();
        self::save_registry( $registry );
    }

    /**
     * @param int    $user_id
     * @param string $job_id
     * @return void
     */
    public static function delete_job( int $user_id, string $job_id ) : void {
        $job_id = self::sanitize_job_id( $job_id );
        if ( $job_id === '' ) {
            return;
        }
        $registry = self::get_registry();
        if ( ! isset( $registry[ $job_id ] ) || (int) ( $registry[ $job_id ]['user_id'] ?? 0 ) !== $user_id ) {
            return;
        }
        unset( $registry[ $job_id ] );
        self::save_registry( $registry );
        delete_option( self::payload_option_name( $job_id ) );
    }

    /**
     * @param int $user_id
     * @return array<int, array<string, mixed>> List of rows with job_id key added.
     */
    public static function list_jobs_for_user( int $user_id ) : array {
        $registry = self::get_registry();
        $out      = [];
        foreach ( $registry as $job_id => $row ) {
            if ( ! is_string( $job_id ) || ! is_array( $row ) ) {
                continue;
            }
            if ( (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
                continue;
            }
            $row['job_id'] = $job_id;
            $out[]         = $row;
        }
        usort(
            $out,
            static function ( $a, $b ) {
                return ( (int) ( $b['stored_at'] ?? 0 ) ) <=> ( (int) ( $a['stored_at'] ?? 0 ) );
            }
        );
        return $out;
    }

    /**
     * Max retention days from plugin settings, clamped 1..365.
     *
     * @return int
     */
    public static function get_max_age_days() : int {
        $raw = 0;
        if ( class_exists( 'Disciple_Tools_Migration_Menu', false ) ) {
            $settings = Disciple_Tools_Migration_Menu::get_settings();
            $file     = isset( $settings['file'] ) && is_array( $settings['file'] ) ? $settings['file'] : [];
            $raw      = (int) ( $file['job_max_age_days'] ?? 0 );
        } else {
            $opt = get_option( 'dt_migration_settings', [] );
            if ( is_array( $opt ) && isset( $opt['file'] ) && is_array( $opt['file'] ) ) {
                $raw = (int) ( $opt['file']['job_max_age_days'] ?? 0 );
            }
        }
        if ( $raw < 1 ) {
            $raw = (int) ( defined( 'DT_MIGRATION_FILE_JOB_MAX_AGE_DAYS' ) ? constant( 'DT_MIGRATION_FILE_JOB_MAX_AGE_DAYS' ) : self::MAX_AGE_DAYS_DEFAULT );
        }
        if ( $raw < 1 ) {
            $raw = self::MAX_AGE_DAYS_DEFAULT;
        }
        $raw = (int) apply_filters( 'dt_migration_file_job_max_age_days', $raw );
        if ( $raw < 1 ) {
            $raw = 1;
        }
        if ( $raw > 365 ) {
            $raw = 365;
        }
        return $raw;
    }

    /**
     * Removes registry rows and payload options past retention.
     *
     * @return int Number of jobs removed.
     */
    public static function prune_expired_jobs() : int {
        $max_days = self::get_max_age_days();
        $cut      = time() - ( $max_days * DAY_IN_SECONDS );
        $registry = self::get_registry();
        $to_remove = [];
        foreach ( $registry as $job_id => $row ) {
            if ( ! is_string( $job_id ) || ! is_array( $row ) ) {
                continue;
            }
            $t = (int) ( $row['stored_at'] ?? 0 );
            if ( $t >= $cut ) {
                continue;
            }
            $to_remove[] = [
                'job_id'  => $job_id,
                'user_id' => (int) ( $row['user_id'] ?? 0 ),
            ];
        }
        $removed = 0;
        foreach ( $to_remove as $item ) {
            self::delete_job( $item['user_id'], $item['job_id'] );
            $removed++;
        }
        return $removed;
    }

    /**
     * Validates export block shape (same as upload handler).
     *
     * @param array $payload
     * @return bool
     */
    public static function is_valid_migration_payload( array $payload ) : bool {
        $export_block    = $payload['export'] ?? [];
        $has_dt_settings = ! empty( $export_block['dt_settings'] );
        $has_users_block = array_key_exists( 'system_users', $export_block ) && is_array( $export_block['system_users'] );
        return is_array( $export_block ) && ( $has_dt_settings || $has_users_block );
    }
}
