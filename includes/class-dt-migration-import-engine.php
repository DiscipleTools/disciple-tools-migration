<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Disciple_Tools_Migration_Import_Engine
 *
 * Shared import logic for applying settings and records.
 * Used by both API mode (Server B pulls from Server A) and file mode (parse zip/JSON).
 */
class Disciple_Tools_Migration_Import_Engine {

    /**
     * Canonical post type order for import (respects dependencies).
     *
     * @var string[]
     */
    const POST_TYPE_ORDER = [ 'peoplegroups', 'groups', 'contacts', 'trainings' ];

    /**
     * Preferred order for importing record types (dependencies and deferred connections).
     *
     * @return string[]
     */
    public static function get_record_import_order(): array {
        return self::POST_TYPE_ORDER;
    }

    /**
     * Whether we are currently in a record import (insert_or_update_post) call.
     * Used by get_post_metadata filter to fix theme bug when *_details meta returns ''.
     *
     * @var bool
     */
    private static $during_record_import = false;

    /**
     * Transient base for per-user deferred connection payloads (pass 2 applies these).
     *
     * @return string
     */
    private static function deferred_connections_transient_key() : string {
        return 'dt_mig_def_conn_' . get_current_user_id();
    }

    /**
     * Clears the deferred connection queue and legacy group-only transient.
     *
     * @return void
     */
    public static function clear_deferred_connection_queue() : void {
        delete_transient( self::deferred_connections_transient_key() );
        delete_transient( 'dt_migration_deferred_group_connections' );
    }

    /**
     * Imports settings from an export payload.
     *
     * @param array $export_payload Export structure from Server A (export endpoint).
     * @param array $selected      Selected setting types: system_users, general_settings, custom_lists, tiles, fields, roles, workflows.
     *
     * @return array{ success: bool, applied: array, errors: array }
     */
    public static function import_settings( array $export_payload, array $selected ) : array {
        $result = [
            'success' => true,
            'applied' => [],
            'errors'  => [],
        ];

        $export      = $export_payload['export'] ?? [];
        $dt_settings = is_array( $export['dt_settings'] ?? null ) ? $export['dt_settings'] : [];

        if ( ! empty( $selected['system_users'] ) ) {
            $system_users = $export['system_users'] ?? null;
            if ( ! is_array( $system_users ) ) {
                $result['errors'][] = __( 'Export payload is missing system user data, but WordPress users were selected for import.', 'disciple-tools-migration' );
                $result['success']  = false;
            } else {
                $user_import = Disciple_Tools_Migration_System_Users::apply_import( $system_users );
                if ( ! empty( $user_import['error'] ) ) {
                    $result['errors'][] = $user_import['error'];
                    $result['success']  = false;
                } else {
                    $result['applied']['system_users'] = true;
                }
            }
        }

        if ( ! empty( $selected['system_users'] ) && ! $result['success'] ) {
            return $result;
        }

        $needs_dt_settings = ! empty( $selected['general_settings'] )
            || ! empty( $selected['custom_lists'] )
            || ! empty( $selected['tiles'] )
            || ! empty( $selected['fields'] )
            || ! empty( $selected['roles'] )
            || ! empty( $selected['workflows'] );

        if ( $needs_dt_settings && empty( $dt_settings ) ) {
            $result['errors'][] = __( 'This export does not include Disciple.Tools settings data, but settings were selected for import.', 'disciple-tools-migration' );
            $result['success']  = false;
            return $result;
        }

        if ( ! $needs_dt_settings && empty( $dt_settings ) ) {
            return $result;
        }

        // Register custom post types (dt_custom_post_types) before tiles/fields so the target site
        // knows about CPTs that exist on the source (required for record import and Customizations UI).
        if ( ! empty( $selected['general_settings'] ) || ! empty( $selected['tiles'] ) || ! empty( $selected['fields'] ) ) {
            if ( self::apply_post_types( $dt_settings ) ) {
                $result['applied']['post_types'] = true;
            }
        }

        if ( ! empty( $selected['general_settings'] ) ) {
            $general = self::apply_general_settings( $export_payload );
            if ( ! empty( $general['error'] ) ) {
                $result['errors'][] = $general['error'];
                $result['success']  = false;
            } else {
                $result['applied']['general_settings'] = true;
            }
        }

        if ( ! empty( $selected['custom_lists'] ) ) {
            $custom_lists = self::apply_custom_lists( $dt_settings );
            if ( ! empty( $custom_lists['error'] ) ) {
                $result['errors'][] = $custom_lists['error'];
                $result['success']  = false;
            } else {
                $result['applied']['custom_lists'] = true;
            }
        }

        if ( ! empty( $selected['tiles'] ) ) {
            $tiles = self::apply_tiles( $dt_settings );
            if ( ! empty( $tiles['error'] ) ) {
                $result['errors'][] = $tiles['error'];
                $result['success']  = false;
            } else {
                $result['applied']['tiles'] = true;
            }
        }

        if ( ! empty( $selected['fields'] ) ) {
            $fields = self::apply_fields( $dt_settings );
            if ( ! empty( $fields['error'] ) ) {
                $result['errors'][] = $fields['error'];
                $result['success']  = false;
            } else {
                $result['applied']['fields'] = true;
            }
        }

        if ( ! empty( $selected['roles'] ) ) {
            $roles = self::apply_roles( $export_payload );
            if ( ! empty( $roles['error'] ) ) {
                $result['errors'][] = $roles['error'];
                $result['success']  = false;
            } else {
                $result['applied']['roles'] = true;
            }
        }

        if ( ! empty( $selected['workflows'] ) ) {
            $workflows = self::apply_workflows( $export_payload );
            if ( ! empty( $workflows['error'] ) ) {
                $result['errors'][] = $workflows['error'];
                $result['success']  = false;
            } else {
                $result['applied']['workflows'] = true;
            }
        }

        return $result;
    }

    /**
     * Merges exported post type definitions into dt_custom_post_types and registers CPTs for the current request.
     *
     * Without this, DT_Posts::update_post fails with "Post type does not exist" for types that only exist on the
     * source (e.g. trainings) and customizations UI will not list them.
     *
     * @param array $dt_settings Export block dt_settings (dt_post_types_settings, dt_post_types_custom_settings).
     *
     * @return bool True if post type data was present and processing ran.
     */
    private static function apply_post_types( array $dt_settings ) : bool {
        $base   = $dt_settings['dt_post_types_settings']['values'] ?? [];
        $custom = $dt_settings['dt_post_types_custom_settings']['values'] ?? [];
        if ( ( empty( $base ) || ! is_array( $base ) ) && ( empty( $custom ) || ! is_array( $custom ) ) ) {
            return false;
        }
        if ( ! class_exists( 'DT_Posts' ) ) {
            return false;
        }

        $registered = DT_Posts::get_post_types();
        $existing   = get_option( 'dt_custom_post_types', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }

        if ( ! empty( $custom ) && is_array( $custom ) ) {
            foreach ( $custom as $slug => $meta ) {
                if ( ! is_string( $slug ) || $slug === '' || ! is_array( $meta ) ) {
                    continue;
                }
                if ( in_array( $slug, $registered, true ) ) {
                    continue;
                }
                if ( ! isset( $existing[ $slug ] ) ) {
                    $existing[ $slug ] = [];
                }
                $existing[ $slug ] = array_merge( $existing[ $slug ], $meta );
                $existing[ $slug ]['is_custom'] = true;
            }
        }

        foreach ( $base as $slug => $settings ) {
            if ( ! is_string( $slug ) || $slug === '' || ! is_array( $settings ) ) {
                continue;
            }
            if ( in_array( $slug, $registered, true ) ) {
                continue;
            }
            if ( ! isset( $existing[ $slug ] ) ) {
                $existing[ $slug ] = [];
            }
            $existing[ $slug ]['label_singular'] = $settings['label_singular'] ?? $existing[ $slug ]['label_singular'] ?? $slug;
            $existing[ $slug ]['label_plural']   = $settings['label_plural'] ?? $existing[ $slug ]['label_plural'] ?? $slug;
            if ( isset( $settings['hidden'] ) ) {
                $existing[ $slug ]['hidden'] = (bool) $settings['hidden'];
            }
            $existing[ $slug ]['is_custom'] = true;
        }

        update_option( 'dt_custom_post_types', $existing, true );

        $original_registered = $registered;
        $to_register         = [];
        foreach ( array_keys( $custom ) as $slug ) {
            if ( is_string( $slug ) && $slug !== '' && ! in_array( $slug, $original_registered, true ) ) {
                $to_register[] = $slug;
            }
        }
        foreach ( array_keys( $base ) as $slug ) {
            if ( is_string( $slug ) && $slug !== '' && ! in_array( $slug, $original_registered, true ) ) {
                $to_register[] = $slug;
            }
        }
        foreach ( array_unique( $to_register ) as $slug ) {
            self::register_post_type_for_current_request( $slug );
        }

        foreach ( array_keys( $existing ) as $slug ) {
            if ( is_string( $slug ) ) {
                wp_cache_delete( $slug . '_post_type_settings' );
                wp_cache_delete( $slug . '_type_settings' );
            }
        }

        flush_rewrite_rules( false );

        return true;
    }

    /**
     * Registers CPTs from an export payload (e.g. before record import when settings step was skipped).
     *
     * @param array $export_payload Same shape as API/file import (export.dt_settings).
     */
    public static function bootstrap_post_types_from_export( array $export_payload ) : void {
        $export = $export_payload['export'] ?? [];
        if ( ! is_array( $export ) ) {
            return;
        }
        $dt_settings = $export['dt_settings'] ?? [];
        if ( ! is_array( $dt_settings ) ) {
            return;
        }
        self::apply_post_types( $dt_settings );
    }

    /**
     * Instantiates Disciple_Tools_Post_Type_Template and registers the post type for this request (after init).
     *
     * @param string $post_type Post type slug.
     */
    private static function register_post_type_for_current_request( string $post_type ) : void {
        if ( ! class_exists( 'DT_Posts' ) || ! class_exists( 'Disciple_Tools_Post_Type_Template' ) ) {
            return;
        }
        if ( post_type_exists( $post_type ) ) {
            return;
        }
        if ( in_array( $post_type, DT_Posts::get_post_types(), true ) ) {
            return;
        }
        $custom = get_option( 'dt_custom_post_types', [] );
        if ( ! is_array( $custom ) || empty( $custom[ $post_type ] ) || empty( $custom[ $post_type ]['is_custom'] ) ) {
            return;
        }
        $c   = $custom[ $post_type ];
        $tpl = new Disciple_Tools_Post_Type_Template(
            $post_type,
            $c['label_singular'] ?? $post_type,
            $c['label_plural'] ?? $post_type
        );
        $tpl->register_post_type();
        $tpl->rewrite_init();
    }

    /**
     * Ensures a post type from dt_custom_post_types is registered (e.g. new HTTP request after settings import).
     *
     * @param string $post_type Post type slug.
     */
    private static function ensure_post_type_registered_from_options( string $post_type ) : void {
        self::register_post_type_for_current_request( $post_type );
    }

    /**
     * Applies general site settings.
     *
     * @param array $export_payload
     *
     * @return array
     */
    private static function apply_general_settings( array $export_payload ) : array {
        // General settings may be in export payload; for now we skip if not present.
        return [];
    }

    /**
     * Applies custom lists.
     *
     * @param array $dt_settings
     *
     * @return array
     */
    private static function apply_custom_lists( array $dt_settings ) : array {
        $custom_lists = $dt_settings['dt_site_custom_lists'] ?? null;
        if ( empty( $custom_lists ) || ! isset( $custom_lists['values'] ) ) {
            return [];
        }
        if ( function_exists( 'dt_get_option' ) ) {
            $existing = dt_get_option( 'dt_site_custom_lists' );
            $merged   = is_array( $existing ) ? array_merge( $existing, $custom_lists['values'] ) : $custom_lists['values'];
            update_option( 'dt_site_custom_lists', $merged, true );
        }
        return [];
    }

    /**
     * Applies tile settings from export.
     *
     * @param array $dt_settings
     *
     * @return array
     */
    private static function apply_tiles( array $dt_settings ) : array {
        $tiles_settings = $dt_settings['dt_tiles_settings']['values'] ?? [];
        $custom_tiles   = $dt_settings['dt_tiles_custom_settings']['values'] ?? [];
        if ( empty( $tiles_settings ) && empty( $custom_tiles ) ) {
            return [];
        }
        $existing = get_option( 'dt_custom_tiles', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }
        foreach ( $tiles_settings as $post_type => $tiles ) {
            if ( ! isset( $existing[ $post_type ] ) ) {
                $existing[ $post_type ] = [];
            }
            foreach ( (array) $tiles as $tile_key => $tile_config ) {
                $existing[ $post_type ][ $tile_key ] = $tile_config;
            }
        }
        if ( ! empty( $custom_tiles ) ) {
            $existing = array_merge( $existing, $custom_tiles );
        }
        update_option( 'dt_custom_tiles', $existing, true );
        return [];
    }

    /**
     * Applies field settings from export.
     *
     * @param array $dt_settings
     *
     * @return array
     */
    private static function apply_fields( array $dt_settings ) : array {
        $fields_settings = $dt_settings['dt_fields_settings']['values'] ?? [];
        $custom_fields   = $dt_settings['dt_fields_custom_settings']['values'] ?? [];
        if ( empty( $fields_settings ) && empty( $custom_fields ) ) {
            return [];
        }
        $existing = get_option( 'dt_field_customizations', [] );
        if ( ! is_array( $existing ) ) {
            $existing = [];
        }
        foreach ( $fields_settings as $post_type => $fields ) {
            if ( ! isset( $existing[ $post_type ] ) ) {
                $existing[ $post_type ] = [];
            }
            foreach ( (array) $fields as $field_key => $field_config ) {
                $existing[ $post_type ][ $field_key ] = $field_config;
            }
        }
        if ( ! empty( $custom_fields ) ) {
            $existing = array_merge( $existing, $custom_fields );
        }
        update_option( 'dt_field_customizations', $existing, true );
        return [];
    }

    /**
     * Applies roles from export.
     *
     * @param array $export_payload
     *
     * @return array
     */
    private static function apply_roles( array $export_payload ) : array {
        // Roles export/import structure may differ; placeholder.
        return [];
    }

    /**
     * Applies workflows from export.
     *
     * Restores dt_workflows_post_types and dt_workflows_defaults from the export payload.
     *
     * @param array $export_payload
     *
     * @return array
     */
    private static function apply_workflows( array $export_payload ) : array {
        $dt_settings = $export_payload['export']['dt_settings'] ?? [];

        $post_types_values = $dt_settings['dt_workflows_post_types']['values'] ?? [];
        if ( ! empty( $post_types_values ) && is_array( $post_types_values ) ) {
            update_option( 'dt_workflows_post_types', wp_json_encode( $post_types_values ), true );
        }

        $defaults_values = $dt_settings['dt_workflows_defaults']['values'] ?? [];
        if ( ! empty( $defaults_values ) && is_array( $defaults_values ) ) {
            update_option( 'dt_workflows_defaults', wp_json_encode( $defaults_values ), true );
        }

        return [];
    }

    /**
     * Adds comment activity to a record for export (Phase 2b).
     *
     * @param string $post_type Post type.
     * @param array  $record    Record from {@see DT_Posts::get_post()}.
     * @return array Record with optional dt_migration_comments.
     */
    public static function attach_migration_comments_to_record( string $post_type, array $record ) : array {
        if ( ! class_exists( 'DT_Posts' ) || empty( $record['ID'] ) ) {
            return $record;
        }
        $post_id         = (int) $record['ID'];
        $comments_result = DT_Posts::get_post_comments( $post_type, $post_id, false, 'all', [] );
        if ( is_wp_error( $comments_result ) || empty( $comments_result['comments'] ) || ! is_array( $comments_result['comments'] ) ) {
            return $record;
        }
        $record['dt_migration_comments'] = $comments_result['comments'];
        return $record;
    }

    /**
     * Imports a batch of records for a post type (pass 1: no connection fields).
     *
     * Preserves post IDs using import_id. Deletes existing records first when offset=0.
     * Connection fields are queued and applied later via {@see apply_all_deferred_connections()}.
     *
     * @param string $post_type                 Post type.
     * @param array  $records                   Array of post data from DT_Posts::get_post.
     * @param int    $offset                   Batch offset (0 = first batch, delete existing first).
     * @param bool   $clear_connection_queue_first When true, clears deferred connection queue (first records batch of a run without prior settings import).
     *
     * @return array{ imported: int, errors: array, fatal: bool }
     */
    public static function import_records_batch( string $post_type, array $records, int $offset = 0, bool $clear_connection_queue_first = false ) : array {
        $result = [
            'imported' => 0,
            'errors'   => [],
            'fatal'    => false,
        ];

        if ( $clear_connection_queue_first ) {
            self::clear_deferred_connection_queue();
        }

        if ( ! class_exists( 'DT_Posts' ) ) {
            $result['fatal']    = true;
            $result['errors'][] = __( 'DT_Posts not available.', 'disciple-tools-migration' );
            return $result;
        }

        self::ensure_post_type_registered_from_options( $post_type );

        // On first batch, delete existing posts of this type (destructive).
        if ( $offset === 0 ) {
            $deleted = self::delete_posts_by_type( $post_type );
            if ( $deleted < 0 ) {
                $result['fatal']    = true;
                $result['errors'][] = __( 'Failed to clear existing records.', 'disciple-tools-migration' );
                return $result;
            }
        }

        $records = self::sort_records_by_connection_deps( $post_type, $records );

        foreach ( $records as $record ) {
            $post_id = isset( $record['ID'] ) ? (int) $record['ID'] : 0;
            if ( ! $post_id ) {
                continue;
            }
            $source_author = isset( $record['post_author'] ) ? (int) $record['post_author'] : 0;
            $mapped_author = $source_author > 0 ? Disciple_Tools_Migration_System_Users::remap_user_id( $source_author ) : 0;

            $fields = self::prepare_record_fields_for_import( $record, $post_type );
            $fields = self::filter_fields_for_target( $post_type, $fields );

            $split = self::split_connection_fields( $post_type, $fields );
            $fields = $split['base'];
            if ( ! empty( $split['connection'] ) ) {
                self::merge_connections_into_deferred_queue( $post_type, $post_id, $split['connection'] );
            }

            $err = self::insert_or_update_post( $post_type, $post_id, $fields, $mapped_author );
            if ( is_wp_error( $err ) ) {
                $result['errors'][] = sprintf(
                    /* translators: 1: post type, 2: post ID, 3: error message */
                    __( 'Failed to import %1$s #%2$d: %3$s', 'disciple-tools-migration' ),
                    $post_type,
                    $post_id,
                    $err->get_error_message()
                );
            } else {
                ++$result['imported'];
                $comment_bundle = $record['dt_migration_comments'] ?? [];
                if ( ! empty( $comment_bundle ) && is_array( $comment_bundle ) ) {
                    foreach ( self::import_record_comments( $post_type, $post_id, $comment_bundle ) as $cerr ) {
                        $result['errors'][] = $cerr;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Removes connection-typed fields for pass 1; values are applied in pass 2.
     *
     * @param string $post_type Post type.
     * @param array  $fields    Fields prepared for import.
     *
     * @return array{ base: array, connection: array }
     */
    private static function split_connection_fields( string $post_type, array $fields ) : array {
        $connection = [];
        $base       = $fields;

        if ( ! class_exists( 'DT_Posts' ) || empty( $fields ) ) {
            return [ 'base' => $fields, 'connection' => [] ];
        }

        try {
            $post_settings = DT_Posts::get_post_settings( $post_type, false );
            $field_defs    = $post_settings['fields'] ?? [];
        } catch ( Throwable $e ) {
            return [ 'base' => $fields, 'connection' => [] ];
        }

        foreach ( array_keys( $fields ) as $key ) {
            if ( isset( $field_defs[ $key ]['type'] ) && $field_defs[ $key ]['type'] === 'connection' ) {
                $connection[ $key ] = $fields[ $key ];
                unset( $base[ $key ] );
            }
        }

        return [ 'base' => $base, 'connection' => $connection ];
    }

    /**
     * Merges connection fields for one post into the deferred queue transient.
     *
     * @param string $post_type       Post type.
     * @param int    $post_id         Post ID.
     * @param array  $connection_fields Field key => connection payload.
     *
     * @return void
     */
    private static function merge_connections_into_deferred_queue( string $post_type, int $post_id, array $connection_fields ) : void {
        if ( $post_id <= 0 || empty( $connection_fields ) ) {
            return;
        }

        $key   = $post_type . ':' . $post_id;
        $queue = get_transient( self::deferred_connections_transient_key() );
        if ( ! is_array( $queue ) ) {
            $queue = [];
        }

        if ( ! isset( $queue[ $key ] ) || ! is_array( $queue[ $key ] ) ) {
            $queue[ $key ] = [];
        }

        self::merge_connection_field_values( $queue[ $key ], $connection_fields );

        set_transient( self::deferred_connections_transient_key(), $queue, HOUR_IN_SECONDS );
    }

    /**
     * Deep-merges connection field structures (combines values by target ID).
     *
     * @param array $bucket   Existing field key => data (modified in place).
     * @param array $incoming New connection fields to merge.
     *
     * @return void
     */
    private static function merge_connection_field_values( array &$bucket, array $incoming ) : void {
        foreach ( $incoming as $fk => $data ) {
            if ( ! is_array( $data ) ) {
                continue;
            }
            if ( ! isset( $bucket[ $fk ] ) ) {
                $bucket[ $fk ] = $data;
                continue;
            }

            $old_vals = isset( $bucket[ $fk ]['values'] ) && is_array( $bucket[ $fk ]['values'] ) ? $bucket[ $fk ]['values'] : [];
            $new_vals = isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : [];
            $by_id    = [];

            foreach ( $old_vals as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $vid = isset( $item['value'] ) ? (int) $item['value'] : 0;
                if ( $vid > 0 ) {
                    $by_id[ $vid ] = $item;
                }
            }
            foreach ( $new_vals as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $vid = isset( $item['value'] ) ? (int) $item['value'] : 0;
                if ( $vid > 0 ) {
                    $by_id[ $vid ] = $item;
                }
            }

            $merged            = array_merge( $bucket[ $fk ], $data );
            $merged['values'] = array_values( $by_id );
            $bucket[ $fk ]     = $merged;
        }
    }

    /**
     * Drops connection targets that do not exist as the field's expected post type.
     *
     * @param string $source_post_type Post type of the record being updated.
     * @param array  $conn_fields      Connection fields only.
     *
     * @return array
     */
    private static function filter_connection_fields_by_field_settings( string $source_post_type, array $conn_fields ) : array {
        if ( ! class_exists( 'DT_Posts' ) || empty( $conn_fields ) ) {
            return [];
        }

        try {
            $post_settings = DT_Posts::get_post_settings( $source_post_type, false );
            $field_defs    = $post_settings['fields'] ?? [];
        } catch ( Throwable $e ) {
            return [];
        }

        $filtered = [];
        foreach ( $conn_fields as $field_key => $field_data ) {
            if ( ! isset( $field_defs[ $field_key ] ) || ( $field_defs[ $field_key ]['type'] ?? '' ) !== 'connection' ) {
                continue;
            }
            if ( ! is_array( $field_data ) || empty( $field_data['values'] ) || ! is_array( $field_data['values'] ) ) {
                continue;
            }

            $target_pt = $field_defs[ $field_key ]['post_type'] ?? $source_post_type;
            $valid     = [];

            foreach ( $field_data['values'] as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $tid = isset( $item['value'] ) ? (int) $item['value'] : 0;
                if ( $tid <= 0 ) {
                    continue;
                }
                if ( get_post_type( $tid ) === $target_pt ) {
                    $valid[] = $item;
                }
            }

            if ( ! empty( $valid ) ) {
                $filtered[ $field_key ] = array_merge( $field_data, [ 'values' => $valid ] );
            }
        }

        return $filtered;
    }

    /**
     * Pass 2: applies all deferred connection fields across imported post types.
     *
     * @return array{ applied: int, errors: array }
     */
    public static function apply_all_deferred_connections() : array {
        $tkey   = self::deferred_connections_transient_key();
        $stored = get_transient( $tkey );
        delete_transient( $tkey );
        delete_transient( 'dt_migration_deferred_group_connections' );

        $result = [ 'applied' => 0, 'errors' => [] ];

        if ( ! is_array( $stored ) || empty( $stored ) ) {
            return $result;
        }

        if ( ! class_exists( 'DT_Posts' ) ) {
            $result['errors'][] = __( 'DT_Posts not available.', 'disciple-tools-migration' );
            return $result;
        }

        $order_map = array_flip( self::POST_TYPE_ORDER );
        $keys      = array_keys( $stored );

        usort(
            $keys,
            static function ( string $a, string $b ) use ( $order_map ) : int {
                $pa = explode( ':', $a, 2 );
                $pb = explode( ':', $b, 2 );
                $ta = $pa[0] ?? '';
                $tb = $pb[0] ?? '';
                $ia = isset( $pa[1] ) ? (int) $pa[1] : 0;
                $ib = isset( $pb[1] ) ? (int) $pb[1] : 0;
                $oa = $order_map[ $ta ] ?? 100;
                $ob = $order_map[ $tb ] ?? 100;
                if ( $oa !== $ob ) {
                    return $oa <=> $ob;
                }
                return $ia <=> $ib;
            }
        );

        foreach ( $keys as $queue_key ) {
            $parts = explode( ':', $queue_key, 2 );
            if ( count( $parts ) < 2 ) {
                continue;
            }
            $pt      = $parts[0];
            $post_id = (int) $parts[1];
            if ( $post_id <= 0 || get_post_type( $post_id ) !== $pt ) {
                continue;
            }

            $conn_fields = $stored[ $queue_key ];
            if ( ! is_array( $conn_fields ) ) {
                continue;
            }

            $conn_fields = self::filter_connection_fields_by_field_settings( $pt, $conn_fields );
            if ( empty( $conn_fields ) ) {
                continue;
            }

            $err = DT_Posts::update_post( $pt, $post_id, $conn_fields, true, false );
            if ( is_wp_error( $err ) ) {
                $result['errors'][] = sprintf(
                    /* translators: 1: post type, 2: post ID, 3: error message */
                    __( 'Failed to apply connections for %1$s #%2$d: %3$s', 'disciple-tools-migration' ),
                    $pt,
                    $post_id,
                    $err->get_error_message()
                );
            } else {
                ++$result['applied'];
            }
        }

        return $result;
    }

    /**
     * @deprecated Use {@see apply_all_deferred_connections()}. Kept for backward compatibility.
     *
     * @return array{ applied: int, errors: array }
     */
    public static function apply_deferred_group_connections() : array {
        return self::apply_all_deferred_connections();
    }

    /**
     * Imports per-user private meta (dt_post_user_meta) for a slice of post IDs.
     *
     * Replace semantics: deletes all existing dt_post_user_meta rows for the in-scope
     * post IDs, then inserts the rows from the export whose post_id is in scope.
     * The source-side user_id is remapped via {@see Disciple_Tools_Migration_System_Users::remap_user_id()}.
     *
     * Rows whose remapped user does not exist on the target are skipped with a logged warning.
     * Rows whose post_id is not in scope are silently ignored (they belong to a different batch).
     *
     * @param array<int, array{ post_id:int, user_id:int, meta_key:string, meta_value:mixed, date:?string, category:?string }> $rows
     * @param int[] $post_ids_in_scope Post IDs covered by the current batch (already inserted).
     * @return array{ inserted:int, errors: string[] }
     */
    public static function import_post_user_meta_for_posts( array $rows, array $post_ids_in_scope ) : array {
        $result = [ 'inserted' => 0, 'errors' => [] ];

        $post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids_in_scope ) ) ) );
        if ( empty( $post_ids ) ) {
            return $result;
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->dt_post_user_meta} WHERE post_id IN ( $placeholders )",
                $post_ids
            )
        );
        // phpcs:enable

        if ( empty( $rows ) ) {
            return $result;
        }

        $scope = array_flip( $post_ids );
        foreach ( $rows as $row ) {
            $pid = isset( $row['post_id'] ) ? (int) $row['post_id'] : 0;
            if ( $pid <= 0 || ! isset( $scope[ $pid ] ) ) {
                continue;
            }
            $source_uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            if ( $source_uid <= 0 ) {
                continue;
            }
            $target_uid = Disciple_Tools_Migration_System_Users::remap_user_id( $source_uid );
            if ( $target_uid <= 0 || ! get_user_by( 'id', $target_uid ) ) {
                $result['errors'][] = sprintf(
                    /* translators: 1: post ID, 2: source user ID */
                    __( 'Skipped post_user_meta row (post #%1$d): no target user for source user %2$d.', 'disciple-tools-migration' ),
                    $pid,
                    $source_uid
                );
                continue;
            }

            $ok = $wpdb->insert(
                $wpdb->dt_post_user_meta,
                [
                    'user_id'    => $target_uid,
                    'post_id'    => $pid,
                    'meta_key'   => (string) ( $row['meta_key'] ?? '' ),
                    'meta_value' => $row['meta_value'] ?? '',
                    'date'       => $row['date'] ?? null,
                    'category'   => $row['category'] ?? null,
                ]
            );
            if ( $ok ) {
                ++$result['inserted'];
            }
        }

        return $result;
    }

    /**
     * Imports activity log rows for a slice of post IDs (wp_dt_activity_log).
     *
     * Replace semantics: deletes existing log rows for the given object_type and in-scope
     * object_ids, then inserts export rows with user_id remapped when greater than zero and new histid.
     * user_caps and hist_ip are taken from the export (historical record).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param int[]                           $post_ids_in_scope
     * @param string                          $post_type Object type stored in the log.
     * @return array{ inserted: int, errors: string[] }
     */
    public static function import_activity_log_for_posts( array $rows, array $post_ids_in_scope, string $post_type ) : array {
        $result = [ 'inserted' => 0, 'errors' => [] ];

        if ( '' === $post_type ) {
            return $result;
        }

        $post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids_in_scope ) ) ) );
        if ( empty( $post_ids ) ) {
            return $result;
        }

        global $wpdb;
        $table = isset( $wpdb->dt_activity_log ) ? $wpdb->dt_activity_log : $wpdb->prefix . 'dt_activity_log';
        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE object_type = %s AND object_id IN ( $placeholders )",
                array_merge( [ $post_type ], $post_ids )
            )
        );
        // phpcs:enable

        if ( empty( $rows ) ) {
            return $result;
        }

        $scope = array_flip( $post_ids );
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $pid = isset( $row['object_id'] ) ? (int) $row['object_id'] : 0;
            if ( $pid <= 0 || ! isset( $scope[ $pid ] ) ) {
                continue;
            }
            $obj_type = isset( $row['object_type'] ) ? (string) $row['object_type'] : '';
            if ( $obj_type !== $post_type ) {
                continue;
            }

            $source_uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            $target_uid = 0;
            if ( $source_uid > 0 ) {
                $target_uid = Disciple_Tools_Migration_System_Users::remap_user_id( $source_uid );
                if ( $target_uid <= 0 || ! get_user_by( 'id', $target_uid ) ) {
                    $result['errors'][] = sprintf(
                        /* translators: 1: post ID, 2: source user ID */
                        __( 'Skipped activity log row (post #%1$d): no target user for source user %2$d.', 'disciple-tools-migration' ),
                        $pid,
                        $source_uid
                    );
                    continue;
                }
            }

            $ok = $wpdb->insert(
                $table,
                [
                    'user_caps'      => (string) ( $row['user_caps'] ?? 'guest' ),
                    'action'         => (string) ( $row['action'] ?? '' ),
                    'object_type'    => $post_type,
                    'object_subtype' => (string) ( $row['object_subtype'] ?? '' ),
                    'object_name'    => (string) ( $row['object_name'] ?? '' ),
                    'object_id'      => $pid,
                    'user_id'        => $target_uid,
                    'hist_ip'        => isset( $row['hist_ip'] ) ? (string) $row['hist_ip'] : '127.0.0.1',
                    'hist_time'      => isset( $row['hist_time'] ) ? (int) $row['hist_time'] : 0,
                    'object_note'    => (string) ( $row['object_note'] ?? '' ),
                    'meta_id'        => isset( $row['meta_id'] ) ? (int) $row['meta_id'] : 0,
                    'meta_key'       => (string) ( $row['meta_key'] ?? '' ),
                    'meta_value'     => (string) ( $row['meta_value'] ?? '' ),
                    'meta_parent'    => isset( $row['meta_parent'] ) ? (int) $row['meta_parent'] : 0,
                    'old_value'      => (string) ( $row['old_value'] ?? '' ),
                    'field_type'     => (string) ( $row['field_type'] ?? '' ),
                ]
            );
            if ( $ok ) {
                ++$result['inserted'];
            }
        }

        return $result;
    }

    /**
     * Deletes all posts of a given post type.
     *
     * @param string $post_type Post type slug.
     * @return int Number deleted.
     */
    public static function delete_posts_by_type( string $post_type ) : int {
        $query = new WP_Query(
            [
                'post_type'      => $post_type,
                'post_status'   => 'any',
                'posts_per_page' => -1,
                'fields'        => 'ids',
            ]
        );
        $ids = $query->posts ?? [];
        $n   = 0;
        foreach ( $ids as $id ) {
            $deleted = wp_delete_post( (int) $id, true );
            if ( $deleted ) {
                ++$n;
            }
        }
        return $n;
    }

    /**
     * Sorts records so connection dependencies are satisfied (e.g. parent before child for groups).
     *
     * For groups, parent_groups and child_groups require the connected group to exist first.
     * Uses topological sort so parents and children are imported before the group that references them.
     *
     * @param string $post_type Post type.
     * @param array  $records   Array of records from export.
     * @return array Sorted records.
     */
    private static function sort_records_by_connection_deps( string $post_type, array $records ) : array {
        if ( $post_type !== 'groups' || empty( $records ) ) {
            return $records;
        }

        $id_to_record = [];
        $batch_ids    = [];
        foreach ( $records as $r ) {
            $id = isset( $r['ID'] ) ? (int) $r['ID'] : 0;
            if ( $id > 0 ) {
                $id_to_record[ $id ] = $r;
                $batch_ids[]        = $id;
            }
        }
        $batch_ids = array_unique( $batch_ids );

        $blocks = []; // blocks[dep_id] = list of ids that must wait for dep_id
        foreach ( $records as $r ) {
            $id   = isset( $r['ID'] ) ? (int) $r['ID'] : 0;
            $deps = [];
            foreach ( [ 'parent_groups', 'child_groups' ] as $conn_key ) {
                if ( empty( $r[ $conn_key ] ) || ! is_array( $r[ $conn_key ] ) ) {
                    continue;
                }
                foreach ( $r[ $conn_key ] as $item ) {
                    $oid = self::extract_post_id_from_connection_item( $item );
                    if ( $oid > 0 && in_array( $oid, $batch_ids, true ) && $oid !== $id ) {
                        $deps[] = $oid;
                    }
                }
            }
            foreach ( array_unique( $deps ) as $dep ) {
                $blocks[ $dep ]   = $blocks[ $dep ] ?? [];
                $blocks[ $dep ][] = $id;
            }
        }

        $in_degree = array_fill_keys( $batch_ids, 0 );
        foreach ( $records as $r ) {
            $id   = isset( $r['ID'] ) ? (int) $r['ID'] : 0;
            $deps = [];
            foreach ( [ 'parent_groups', 'child_groups' ] as $conn_key ) {
                if ( empty( $r[ $conn_key ] ) || ! is_array( $r[ $conn_key ] ) ) {
                    continue;
                }
                foreach ( $r[ $conn_key ] as $item ) {
                    $oid = self::extract_post_id_from_connection_item( $item );
                    if ( $oid > 0 && in_array( $oid, $batch_ids, true ) && $oid !== $id ) {
                        $deps[] = $oid;
                    }
                }
            }
            $in_degree[ $id ] = count( array_unique( $deps ) );
        }

        $ready = array_keys(
            array_filter(
                $in_degree,
                function ( $d ) {
                    return $d === 0;
                }
            )
        );
        $out  = [];
        $done = [];
        while ( ! empty( $ready ) ) {
            $nid = array_shift( $ready );
            if ( isset( $done[ $nid ] ) ) {
                continue;
            }
            $done[ $nid ] = true;
            if ( isset( $id_to_record[ $nid ] ) ) {
                $out[] = $id_to_record[ $nid ];
            }
            foreach ( $blocks[ $nid ] ?? [] as $waiting ) {
                $in_degree[ $waiting ] = isset( $in_degree[ $waiting ] ) ? $in_degree[ $waiting ] - 1 : 0;
                if ( $in_degree[ $waiting ] <= 0 && empty( $done[ $waiting ] ) ) {
                    $ready[] = $waiting;
                }
            }
        }
        foreach ( $batch_ids as $bid ) {
            if ( ! in_array( $bid, array_column( $out, 'ID' ), true ) && isset( $id_to_record[ $bid ] ) ) {
                $out[] = $id_to_record[ $bid ];
            }
        }
        return $out;
    }

    /**
     * Filters record fields to only those that exist on the target site.
     *
     * Skips unknown fields so records can still migrate with ID, name, and valid fields.
     *
     * @param string $post_type
     * @param array  $fields Prepared fields from prepare_record_fields_for_import.
     *
     * @return array Filtered fields safe for DT_Posts::update_post.
     */
    private static function filter_fields_for_target( string $post_type, array $fields ) : array {
        if ( ! class_exists( 'DT_Posts' ) || empty( $fields ) ) {
            return $fields;
        }

        try {
            $post_settings = DT_Posts::get_post_settings( $post_type, false );
            if ( empty( $post_settings['fields'] ) ) {
                return $fields;
            }

            // Ensure keys expected by check_for_invalid_post_fields / is_post_key_contact_method_or_connection exist.
            $post_settings = array_merge(
                [ 'channels' => [], 'connection_types' => [] ],
                $post_settings
            );

            $allowed_fields = apply_filters( 'dt_post_update_allow_fields', [], $post_type );
            $bad_fields     = DT_Posts::check_for_invalid_post_fields( $post_settings, $fields, $allowed_fields );

            foreach ( (array) $bad_fields as $bad ) {
                unset( $fields[ $bad ] );
            }
        } catch ( Throwable $e ) {
            // If filtering fails, return fields unchanged; DT_Posts may still accept them or return a clearer error.
            return $fields;
        }

        return $fields;
    }

    /**
     * Maps a source-site user ID from an exported user_select value to the target site (Phase 2).
     *
     * Uses {@see Disciple_Tools_Migration_System_Users::remap_user_id()} when a numeric user
     * reference is found; leaves email strings for DT_Posts to resolve.
     *
     * @param mixed $value Raw user_select field value from {@see DT_Posts::get_post()}.
     * @return mixed       Integer target user ID, email string, or normalized scalar for DT_Posts.
     */
    private static function normalize_and_remap_user_select_for_import( $value ) {
        if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
            return $value;
        }
        $source_uid = self::extract_source_user_id_from_export_user_select( $value );
        if ( $source_uid > 0 ) {
            return Disciple_Tools_Migration_System_Users::remap_user_id( $source_uid );
        }
        return self::normalize_field_value_for_import( $value );
    }

    /**
     * Parses a source WordPress user ID from an exported user_select payload.
     *
     * @param mixed $value Export shape: array with id / assigned-to, int, or user-{id} string.
     * @return int         User ID or 0 if not parseable as a user reference.
     */
    private static function extract_source_user_id_from_export_user_select( $value ) : int {
        if ( is_array( $value ) ) {
            if ( ! empty( $value['assigned-to'] ) && is_string( $value['assigned-to'] ) ) {
                if ( preg_match( '/^user-(\d+)$/', $value['assigned-to'], $matches ) ) {
                    return (int) $matches[1];
                }
            }
            if ( isset( $value['id'] ) && is_numeric( $value['id'] ) ) {
                return (int) $value['id'];
            }
            return 0;
        }
        if ( is_int( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
            return (int) $value;
        }
        if ( is_string( $value ) && preg_match( '/^user-(\d+)$/', $value, $matches ) ) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Remaps Disciple.Tools-style user tokens in HTML comments: [Label](userId).
     *
     * @param string $html Comment HTML/text.
     * @return string
     */
    private static function remap_mention_user_ids_in_comment_html( string $html ) : string {
        return (string) preg_replace_callback(
            '/@?\[([^\]]*)\]\((\d+)\)/',
            static function ( array $m ) {
                $old = (int) $m[2];
                if ( $old <= 0 ) {
                    return $m[0];
                }
                $new    = Disciple_Tools_Migration_System_Users::remap_user_id( $old );
                $prefix = isset( $m[0][0] ) && $m[0][0] === '@' ? '@' : '';
                return $prefix . '[' . $m[1] . '](' . $new . ')';
            },
            $html
        );
    }

    /**
     * Creates comments from an export payload (Phase 2b).
     *
     * @param string $post_type
     * @param int    $post_id
     * @param array  $comments Rows from dt_migration_comments.
     * @return array<int, string> Error messages.
     */
    private static function import_record_comments( string $post_type, int $post_id, array $comments ) : array {
        $errors = [];
        if ( empty( $comments ) || ! class_exists( 'DT_Posts' ) ) {
            return $errors;
        }
        $index = 0;
        foreach ( $comments as $row ) {
            ++$index;
            if ( ! is_array( $row ) ) {
                continue;
            }
            $content = isset( $row['comment_content'] ) ? (string) $row['comment_content'] : '';
            if ( $content === '' ) {
                continue;
            }
            $content = self::remap_mention_user_ids_in_comment_html( $content );

            $source_uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            $new_uid    = $source_uid > 0 ? Disciple_Tools_Migration_System_Users::remap_user_id( $source_uid ) : 0;
            // Remap may fall back to the source ID; that user often does not exist on the target.
            // wp_check_comment_data() calls get_userdata() and then ->has_cap(); false is fatal.
            if ( $new_uid > 0 ) {
                $comment_user = get_userdata( $new_uid );
                if ( ! $comment_user instanceof WP_User ) {
                    $new_uid = 0;
                }
            }

            $type = isset( $row['comment_type'] ) ? sanitize_key( (string) $row['comment_type'] ) : 'comment';
            if ( strlen( $type ) > 20 ) {
                $type = substr( $type, 0, 20 );
            }

            $args = [];
            if ( $new_uid > 0 ) {
                $args['user_id'] = $new_uid;
            }
            if ( ! empty( $row['comment_author'] ) ) {
                $args['comment_author'] = sanitize_text_field( (string) $row['comment_author'] );
            }
            if ( ! empty( $row['comment_date'] ) && function_exists( 'dt_validate_date' ) && dt_validate_date( (string) $row['comment_date'] ) ) {
                $args['comment_date'] = (string) $row['comment_date'];
            }

            $comment_meta = [];
            if ( ! empty( $row['comment_reactions'] ) && is_array( $row['comment_reactions'] ) ) {
                foreach ( $row['comment_reactions'] as $reaction_key => $reactors ) {
                    if ( strpos( (string) $reaction_key, 'reaction' ) !== 0 ) {
                        continue;
                    }
                    if ( ! is_array( $reactors ) ) {
                        continue;
                    }
                    foreach ( $reactors as $r ) {
                        if ( ! is_array( $r ) ) {
                            continue;
                        }
                        $rid = isset( $r['user_id'] ) ? (int) $r['user_id'] : 0;
                        if ( $rid <= 0 ) {
                            continue;
                        }
                        $mapped = Disciple_Tools_Migration_System_Users::remap_user_id( $rid );
                        if ( $mapped <= 0 ) {
                            continue;
                        }
                        $reactor_user = get_userdata( $mapped );
                        if ( ! $reactor_user instanceof WP_User ) {
                            continue;
                        }
                        if ( ! isset( $comment_meta[ $reaction_key ] ) ) {
                            $comment_meta[ $reaction_key ] = [];
                        }
                        $comment_meta[ $reaction_key ][] = $mapped;
                    }
                }
            }
            if ( ! empty( $row['comment_meta'] ) && is_array( $row['comment_meta'] ) ) {
                foreach ( $row['comment_meta'] as $mk => $entries ) {
                    $mk = (string) $mk;
                    if ( strpos( $mk, 'reaction' ) === 0 ) {
                        continue;
                    }
                    if ( ! is_array( $entries ) ) {
                        continue;
                    }
                    foreach ( $entries as $ent ) {
                        if ( ! is_array( $ent ) || ! array_key_exists( 'value', $ent ) ) {
                            continue;
                        }
                        if ( ! isset( $comment_meta[ $mk ] ) ) {
                            $comment_meta[ $mk ] = [];
                        }
                        $comment_meta[ $mk ][] = wp_unslash( $ent['value'] );
                    }
                }
            }
            if ( ! empty( $comment_meta ) ) {
                $args['comment_meta'] = $comment_meta;
            }

            $created = DT_Posts::add_post_comment( $post_type, $post_id, $content, $type, $args, false, true );
            if ( is_wp_error( $created ) ) {
                $errors[] = sprintf(
                    /* translators: 1: comment index, 2: post type, 3: post ID, 4: error message */
                    __( 'Comment #%1$d on %2$s #%3$d: %4$s', 'disciple-tools-migration' ),
                    $index,
                    $post_type,
                    $post_id,
                    $created->get_error_message()
                );
            }
        }
        return $errors;
    }

    /**
     * Normalizes a field value from export format to DT_Posts update format.
     *
     * Export returns key_select as { key, label }; DT expects the key string.
     * Export returns user_select as { assigned-to, id, display }; DT expects assigned-to value.
     * Prefer {@see normalize_and_remap_user_select_for_import()} for user_select during migration.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_field_value_for_import( $value ) {
        if ( is_array( $value ) && isset( $value['key'] ) ) {
            return (string) $value['key'];
        }
        if ( is_array( $value ) && isset( $value['assigned-to'] ) ) {
            return (string) $value['assigned-to'];
        }
        if ( is_array( $value ) && isset( $value['timestamp'] ) ) {
            return $value['timestamp'];
        }
        return $value;
    }

    /**
     * Normalizes a connection field from export format to DT_Posts update format.
     *
     * Export returns connections as an array of objects (e.g. [ ['ID' => 123, ... ], ... ]).
     * DT_Posts::update_post expects { values: [ ['value' => id], ... ], force_values?: true }.
     *
     * @param mixed $value Connection data from export.
     * @return array{ values: array, force_values: bool }
     */
    private static function normalize_connection_for_import( $value ) : array {
        $values = [];
        if ( is_array( $value ) && isset( $value['values'] ) ) {
            foreach ( (array) $value['values'] as $item ) {
                $id = self::extract_post_id_from_connection_item( $item );
                if ( $id > 0 ) {
                    $values[] = [ 'value' => $id ];
                }
            }
            return [
                'values'       => $values,
                'force_values' => ! empty( $value['force_values'] ),
            ];
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                $id = self::extract_post_id_from_connection_item( $item );
                if ( $id > 0 ) {
                    $values[] = [ 'value' => $id ];
                }
            }
        }
        return [ 'values' => $values, 'force_values' => true ];
    }

    /**
     * Normalizes a multi_select/tags field from export format to DT_Posts update format.
     *
     * Export may return plain arrays (e.g. ['web','facebook']) or mixed formats.
     * DT_Posts::update_post expects { values: [ ['value' => key], ... ], force_values?: true }.
     *
     * @param mixed $value Multi-select data from export.
     * @return array{ values: array, force_values: bool }
     */
    private static function normalize_multi_select_for_import( $value ) : array {
        $values = [];
        if ( is_array( $value ) && isset( $value['values'] ) ) {
            foreach ( (array) $value['values'] as $item ) {
                $v = self::extract_multi_select_value( $item );
                if ( $v !== '' && $v !== null ) {
                    $values[] = [ 'value' => $v ];
                }
            }
            return [
                'values'       => $values,
                'force_values' => ! empty( $value['force_values'] ),
            ];
        }
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                $v = self::extract_multi_select_value( $item );
                if ( $v !== '' && $v !== null ) {
                    $values[] = [ 'value' => $v ];
                }
            }
        }
        return [ 'values' => $values, 'force_values' => true ];
    }

    /**
     * Normalizes a link field from export format to DT_Posts update format.
     *
     * Export returns links as [{ type, value, meta_id }, ...].
     * DT_Posts::update_post expects { values: [ { type, value }, ... ], force_values?: true }.
     *
     * meta_id is stripped: it refers to the source-site postmeta row, and passing it would
     * send the entry through the "update existing" branch and update the wrong row (or none).
     *
     * @param mixed $value Link data from export.
     * @return array{ values: array, force_values: bool }
     */
    private static function normalize_link_for_import( $value ) : array {
        if ( is_array( $value ) && isset( $value['values'] ) ) {
            return [
                'values'       => array_values( (array) $value['values'] ),
                'force_values' => ! empty( $value['force_values'] ),
            ];
        }
        $values = [];
        if ( is_array( $value ) ) {
            foreach ( $value as $item ) {
                if ( ! is_array( $item ) || ! isset( $item['type'] ) || ! array_key_exists( 'value', $item ) ) {
                    continue;
                }
                $values[] = [
                    'type'  => (string) $item['type'],
                    'value' => $item['value'],
                ];
            }
        }
        return [ 'values' => $values, 'force_values' => true ];
    }

    /**
     * Extracts the value from a multi-select, location, or tags item.
     *
     * Handles: plain values, arrays with value/key (multi_select), grid_id/id (location).
     *
     * @param mixed $item
     * @return string|int|null
     */
    private static function extract_multi_select_value( $item ) {
        if ( is_string( $item ) || is_numeric( $item ) ) {
            return is_numeric( $item ) ? (int) $item : (string) $item;
        }
        if ( is_array( $item ) ) {
            if ( isset( $item['value'] ) ) {
                return is_numeric( $item['value'] ) ? (int) $item['value'] : (string) $item['value'];
            }
            if ( isset( $item['key'] ) ) {
                return (string) $item['key'];
            }
            if ( isset( $item['grid_id'] ) ) {
                return (int) $item['grid_id'];
            }
            if ( isset( $item['id'] ) ) {
                return is_numeric( $item['id'] ) ? (int) $item['id'] : (string) $item['id'];
            }
        }
        return null;
    }

    /**
     * Extracts post ID from a connection item (object or array).
     *
     * @param mixed $item Connection item: object with ID, or array with ID/value.
     * @return int
     */
    private static function extract_post_id_from_connection_item( $item ) : int {
        if ( is_numeric( $item ) ) {
            return (int) $item;
        }
        if ( is_object( $item ) && isset( $item->ID ) ) {
            return (int) $item->ID;
        }
        if ( is_array( $item ) ) {
            if ( isset( $item['ID'] ) ) {
                return (int) $item['ID'];
            }
            if ( isset( $item['value'] ) && is_numeric( $item['value'] ) ) {
                return (int) $item['value'];
            }
        }
        return 0;
    }

    /**
     * Prepares record fields for import (strips IDs, formats for create/update).
     *
     * @param array  $record    Full post from DT_Posts::get_post.
     * @param string $post_type Post type for connection field detection.
     *
     * @return array
     */
    private static function prepare_record_fields_for_import( array $record, string $post_type ) : array {
        $exclude = [
            'ID',
            'post_type',
            'post_date',
            'post_date_gmt',
            'permalink',
            'post_date_formatted',
            'post_author',
            'post_author_display_name',
            'dt_migration_comments',
        ];
        $connection_types     = [];
        $multi_select_types   = [];
        $user_select_types    = [];
        $link_types           = [];
        $private_field_types  = [];
        if ( class_exists( 'DT_Posts' ) ) {
            $post_settings      = DT_Posts::get_post_settings( $post_type, false );
            $connection_types   = (array) ( $post_settings['connection_types'] ?? [] );
            $field_settings     = (array) ( $post_settings['fields'] ?? [] );
            $values_based_types = [ 'multi_select', 'tags', 'location', 'location_meta' ];
            foreach ( $field_settings as $fk => $fs ) {
                if ( ! isset( $fs['type'] ) ) {
                    continue;
                }
                // Private field values flow through the post_user_meta block, never through records.
                if ( $fs['type'] === 'task' || ! empty( $fs['private'] ) ) {
                    $private_field_types[] = $fk;
                    continue;
                }
                if ( $fs['type'] === 'user_select' ) {
                    $user_select_types[] = $fk;
                } elseif ( $fs['type'] === 'link' ) {
                    $link_types[] = $fk;
                } elseif ( in_array( $fs['type'], $values_based_types, true ) ) {
                    $multi_select_types[] = $fk;
                }
            }
        }
        $fields = [];
        foreach ( $record as $key => $value ) {
            if ( in_array( $key, $exclude, true ) ) {
                continue;
            }
            if ( in_array( $key, $private_field_types, true ) ) {
                continue;
            }
            if ( in_array( $key, $connection_types, true ) ) {
                $fields[ $key ] = self::normalize_connection_for_import( $value );
            } elseif ( in_array( $key, $multi_select_types, true ) ) {
                $fields[ $key ] = self::normalize_multi_select_for_import( $value );
            } elseif ( in_array( $key, $user_select_types, true ) ) {
                $fields[ $key ] = self::normalize_and_remap_user_select_for_import( $value );
            } elseif ( in_array( $key, $link_types, true ) ) {
                $fields[ $key ] = self::normalize_link_for_import( $value );
            } else {
                $fields[ $key ] = self::normalize_field_value_for_import( $value );
            }
        }
        if ( isset( $fields['name'] ) && ! isset( $fields['title'] ) ) {
            $fields['title'] = $fields['name'];
        }
        return $fields;
    }

    /**
     * get_post_metadata filter: return [] for *_details keys during record import.
     *
     * The theme's update_post_contact_method expects get_post_meta(..., '_details', true)
     * to return an array. When the meta does not exist (or is corrupt as ''), the theme
     * does $details[$key] = $value and triggers "Cannot access offset of type string on string".
     * Short-circuiting with [] fixes this without modifying the theme.
     *
     * @param mixed  $value     Value from previous filter or null.
     * @param int    $object_id Post ID.
     * @param string $meta_key  Meta key.
     * @param bool   $single    Whether a single value is requested.
     * @param string $meta_type Meta type ('post').
     * @return mixed
     */
    public static function filter_details_meta_during_import( $value, $object_id, $meta_key, $single, $meta_type ) {
        if ( ! self::$during_record_import || $meta_type !== 'post' ) {
            return $value;
        }
        if ( substr( $meta_key, -8 ) !== '_details' ) {
            return $value;
        }
        return [ [] ];
    }

    /**
     * Inserts a post with a specific ID (preserves relationships).
     *
     * Uses wp_insert_post with import_id for the base post, then DT_Posts::update_post
     * to apply all field values, connections, and meta.
     *
     * @param string $post_type
     * @param int    $post_id   Desired post ID.
     * @param array  $fields    Prepared fields from prepare_record_fields_for_import.
     * @param int    $post_author_id Remapped WordPress user ID for post_author (0 = use current user on insert, unchanged on update).
     *
     * @return true|WP_Error
     */
    private static function insert_or_update_post( string $post_type, int $post_id, array $fields, int $post_author_id = 0 ) {
        $existing       = get_post( $post_id );
        $author_for_ins = $post_author_id > 0 ? $post_author_id : get_current_user_id();

        $run_update = function () use ( $post_type, $post_id, $fields ) {
            return DT_Posts::update_post( $post_type, $post_id, $fields, true, false );
        };

        if ( $existing && get_post_type( $post_id ) === $post_type ) {
            if ( $post_author_id > 0 && (int) $existing->post_author !== $post_author_id ) {
                wp_update_post(
                    wp_slash(
                        [
                            'ID'          => $post_id,
                            'post_author' => $post_author_id,
                        ]
                    ),
                    true
                );
            }
            self::$during_record_import = true;
            add_filter( 'get_post_metadata', [ self::class, 'filter_details_meta_during_import' ], 10, 5 );
            try {
                return $run_update();
            } finally {
                remove_filter( 'get_post_metadata', [ self::class, 'filter_details_meta_during_import' ], 10 );
                self::$during_record_import = false;
            }
        }

        $title = $fields['title'] ?? $fields['name'] ?? '';
        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Record has no title/name.', 'disciple-tools-migration' ) );
        }

        $post_arr = [
            'import_id'   => $post_id,
            'post_title'  => $title,
            'post_type'   => $post_type,
            'post_status' => $fields['post_status'] ?? 'publish',
            'post_author' => $author_for_ins,
        ];

        $inserted_id = wp_insert_post( $post_arr, true );
        if ( is_wp_error( $inserted_id ) ) {
            return $inserted_id;
        }
        if ( $inserted_id !== $post_id ) {
            return new WP_Error( 'id_mismatch', __( 'Could not preserve post ID.', 'disciple-tools-migration' ) );
        }

        self::$during_record_import = true;
        add_filter( 'get_post_metadata', [ self::class, 'filter_details_meta_during_import' ], 10, 5 );
        try {
            return $run_update();
        } finally {
            remove_filter( 'get_post_metadata', [ self::class, 'filter_details_meta_during_import' ], 10 );
            self::$during_record_import = false;
        }
    }

    /**
     * Returns post types to import in dependency order.
     *
     * @param array $selected Selected post type keys.
     *
     * @return string[]
     */
    public static function ordered_post_types( array $selected ) : array {
        $ordered = [];
        foreach ( self::POST_TYPE_ORDER as $pt ) {
            if ( ! empty( $selected[ $pt ] ) ) {
                $ordered[] = $pt;
            }
        }
        foreach ( array_keys( $selected ) as $pt ) {
            if ( ! in_array( $pt, $ordered, true ) && ! empty( $selected[ $pt ] ) ) {
                $ordered[] = $pt;
            }
        }
        return $ordered;
    }
}
