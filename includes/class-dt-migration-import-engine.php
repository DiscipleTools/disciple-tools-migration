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
    const POST_TYPE_ORDER = [ 'peoplegroups', 'contacts', 'groups', 'trainings' ];

    /**
     * Imports settings from an export payload.
     *
     * @param array $export_payload Export structure from Server A (export endpoint).
     * @param array $selected      Selected setting types: general_settings, custom_lists, tiles, fields, roles, workflows.
     *
     * @return array{ success: bool, applied: array, errors: array }
     */
    public static function import_settings( array $export_payload, array $selected ) : array {
        $result = [
            'success' => true,
            'applied' => [],
            'errors'  => [],
        ];

        $dt_settings = $export_payload['export']['dt_settings'] ?? [];
        if ( empty( $dt_settings ) ) {
            return $result;
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
     * @param array $export_payload
     *
     * @return array
     */
    private static function apply_workflows( array $export_payload ) : array {
        // Workflows export/import structure may differ; placeholder.
        return [];
    }

    /**
     * Imports a batch of records for a post type.
     *
     * Preserves post IDs using import_id. Deletes existing records first when offset=0.
     *
     * @param string $post_type Post type.
     * @param array  $records   Array of post data from DT_Posts::get_post.
     * @param int    $offset    Batch offset (0 = first batch, delete existing first).
     *
     * @return array{ imported: int, errors: array }
     */
    public static function import_records_batch( string $post_type, array $records, int $offset = 0 ) : array {
        $result = [
            'imported' => 0,
            'errors'   => [],
        ];

        if ( ! class_exists( 'DT_Posts' ) ) {
            $result['errors'][] = __( 'DT_Posts not available.', 'disciple-tools-migration' );
            return $result;
        }

        // On first batch, delete existing posts of this type (destructive).
        if ( $offset === 0 ) {
            $deleted = self::delete_posts_by_type( $post_type );
            if ( $deleted < 0 ) {
                $result['errors'][] = __( 'Failed to clear existing records.', 'disciple-tools-migration' );
                return $result;
            }
        }

        foreach ( $records as $record ) {
            $post_id = isset( $record['ID'] ) ? (int) $record['ID'] : 0;
            if ( ! $post_id ) {
                continue;
            }
            $fields = self::prepare_record_fields_for_import( $record );
            $err    = self::insert_or_update_post( $post_type, $post_id, $fields );
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
            }
        }

        return $result;
    }

    /**
     * Deletes all posts of a given post type.
     *
     * @param string $post_type
     *
     * @return int Number deleted, or -1 on error.
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
     * Prepares record fields for import (strips IDs, formats for create/update).
     *
     * @param array $record Full post from DT_Posts::get_post.
     *
     * @return array
     */
    private static function prepare_record_fields_for_import( array $record ) : array {
        $exclude = [ 'ID', 'post_type', 'post_date', 'post_date_gmt', 'permalink', 'post_date_formatted' ];
        $fields  = [];
        foreach ( $record as $key => $value ) {
            if ( in_array( $key, $exclude, true ) ) {
                continue;
            }
            $fields[ $key ] = $value;
        }
        if ( isset( $fields['name'] ) && ! isset( $fields['title'] ) ) {
            $fields['title'] = $fields['name'];
        }
        return $fields;
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
     *
     * @return true|WP_Error
     */
    private static function insert_or_update_post( string $post_type, int $post_id, array $fields ) {
        $existing = get_post( $post_id );
        if ( $existing && get_post_type( $post_id ) === $post_type ) {
            return DT_Posts::update_post( $post_type, $post_id, $fields, true, false );
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
        ];

        $inserted_id = wp_insert_post( $post_arr, true );
        if ( is_wp_error( $inserted_id ) ) {
            return $inserted_id;
        }
        if ( $inserted_id !== $post_id ) {
            return new WP_Error( 'id_mismatch', __( 'Could not preserve post ID.', 'disciple-tools-migration' ) );
        }

        return DT_Posts::update_post( $post_type, $post_id, $fields, true, false );
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
