<?php
if ( ! defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly

/**
 * Class Disciple_Tools_Migration_Tab_Export
 *
 * Placeholder for the Migration Export tab. Will be wired to settings in later phases.
 */
class Disciple_Tools_Migration_Tab_Export {
    public function content() {
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
     * Renders the main Export tab content, depending on settings and mode.
     *
     * @param array $settings
     */
    public function main_column( array $settings ) {
        ?>
        <!-- Box -->
        <table class="widefat striped">
            <thead>
            <tr>
                <th><?php esc_html_e( 'Export', 'disciple-tools-migration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>
                    <?php if ( empty( $settings['enabled'] ) ) : ?>
                        <p>
                            <?php esc_html_e( 'Migration is currently disabled. Enable it on the Settings tab in order to generate exports.', 'disciple-tools-migration' ); ?>
                        </p>
                    <?php else : ?>
                        <?php if ( $settings['mode'] === 'api' ) : ?>
                            <p>
                                <?php esc_html_e( 'This site (Server A) serves migration exports via API endpoints. A remote Disciple.Tools site (Server B) can connect and fetch the settings and records below.', 'disciple-tools-migration' ); ?>
                            </p>
                            <?php
                            $allowed          = $settings['allowed_items'] ?? [];
                            $settings_preview = $this->get_api_export_preview( $allowed );
                            $records_preview  = $this->get_api_records_preview( $allowed );
                            $post_type_count  = is_array( $records_preview ) ? count( $records_preview ) : 0;
                            ?>
                            <?php
                            $show_api_preview = ! empty( $settings_preview ) || ! empty( $records_preview )
                                || ! empty( $allowed['general_settings'] ) || ! empty( $allowed['custom_lists'] )
                                || ! empty( $allowed['roles'] ) || ! empty( $allowed['workflows'] )
                                || ! empty( $allowed['system_users'] );
                            ?>
                            <?php if ( $show_api_preview ) : ?>
                                <h3 style="margin-top: 20px;"><?php esc_html_e( 'API Export Preview', 'disciple-tools-migration' ); ?></h3>
                                <p class="description" style="margin-bottom: 16px;">
                                    <?php esc_html_e( 'Summary of what will be exported when Server B connects and fetches from this site.', 'disciple-tools-migration' ); ?>
                                </p>
                                <table class="widefat striped" style="margin-bottom: 20px;">
                                    <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Setting Type', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Enabled', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Notes', 'disciple-tools-migration' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php
                                    $wp_user_count = function_exists( 'count_users' ) ? (int) ( count_users()['total_users'] ?? 0 ) : 0;
                                    $settings_rows = [
                                        'system_users'     => [
                                            'label' => __( 'WordPress users (system)', 'disciple-tools-migration' ),
                                            'notes' => ! empty( $allowed['system_users'] ) && $wp_user_count
                                                ? sprintf( esc_html__( '%d users (safe fields only; no passwords).', 'disciple-tools-migration' ), $wp_user_count )
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
                                            <td><?php echo esc_html( $row['label'] ); ?></td>
                                            <td><?php echo $is_enabled ? esc_html__( 'Yes', 'disciple-tools-migration' ) : esc_html__( 'No', 'disciple-tools-migration' ); ?></td>
                                            <td><?php echo esc_html( $row['notes'] ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php if ( ! empty( $records_preview ) ) : ?>
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
                                        foreach ( $records_preview as $post_type => $data ) :
                                            $summary = $settings_preview[ $post_type ] ?? [ 'tiles' => 0, 'fields' => 0 ];
                                            $count   = isset( $data['count'] ) ? (int) $data['count'] : 0;
                                            ?>
                                            <tr>
                                                <td><?php echo esc_html( $post_type ); ?></td>
                                                <td><?php echo isset( $summary['tiles'] ) ? (int) $summary['tiles'] : 0; ?></td>
                                                <td><?php echo isset( $summary['fields'] ) ? (int) $summary['fields'] : 0; ?></td>
                                                <td><?php echo $count; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else : ?>
                                    <p>
                                        <?php esc_html_e( 'No record types are enabled for export. Enable at least one on the Settings tab.', 'disciple-tools-migration' ); ?>
                                    </p>
                                <?php endif; ?>
                            <?php else : ?>
                                <p>
                                    <?php esc_html_e( 'Enable settings and record types on the Settings tab to see what will be exported.', 'disciple-tools-migration' ); ?>
                                </p>
                            <?php endif; ?>
                        <?php else : ?>
                            <p>
                                <?php esc_html_e( 'This site is configured to export migration packages as downloadable JSON files.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'The export includes the settings and record types you have enabled on the Settings tab. By default all records of each type are included; use By Range or By limited records for advanced partial exports.', 'disciple-tools-migration' ); ?>
                            </p>
                            <?php
                            $record_stats = Disciple_Tools_Migration_Export_File::get_record_stats();
                            if ( ! empty( $record_stats ) ) :
                                ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="dt_migration_download_export">
                                <?php wp_nonce_field( 'dt_migration_download_export', 'dt_migration_download_export_nonce' ); ?>
                                <table class="widefat striped dt-migration-export-table" style="margin-top: 16px;">
                                    <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Post Type', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Total', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'ID Range', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Export By', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Limit Records', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Export From ID', 'disciple-tools-migration' ); ?></th>
                                        <th><?php esc_html_e( 'Export To ID', 'disciple-tools-migration' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ( $record_stats as $post_type => $stat ) : ?>
                                        <tr class="dt-migration-export-row" data-post-type="<?php echo esc_attr( $post_type ); ?>">
                                            <td><?php echo esc_html( $post_type ); ?></td>
                                            <td><?php echo (int) $stat['count']; ?></td>
                                            <td><?php echo (int) $stat['min_id'] . ' &ndash; ' . (int) $stat['max_id']; ?></td>
                                            <td>
                                                <select name="dt_migration_export_by[<?php echo esc_attr( $post_type ); ?>]" class="dt-migration-export-by" style="width: 100%;">
                                                    <option value="all" selected><?php esc_html_e( 'All', 'disciple-tools-migration' ); ?></option>
                                                    <option value="range"><?php esc_html_e( 'By Range', 'disciple-tools-migration' ); ?></option>
                                                    <option value="limit"><?php esc_html_e( 'By Limited Records', 'disciple-tools-migration' ); ?></option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" class="dt-migration-export-limit" name="dt_migration_export_limit[<?php echo esc_attr( $post_type ); ?>]" value="" min="0" placeholder="<?php esc_attr_e( 'e.g. 100', 'disciple-tools-migration' ); ?>" style="width:80px;" disabled>
                                            </td>
                                            <td>
                                                <input type="number" class="dt-migration-export-min-id" name="dt_migration_export_min_id[<?php echo esc_attr( $post_type ); ?>]" value="" min="0" placeholder="<?php echo (int) $stat['min_id']; ?>" style="width:80px;" disabled>
                                            </td>
                                            <td>
                                                <input type="number" class="dt-migration-export-max-id" name="dt_migration_export_max_id[<?php echo esc_attr( $post_type ); ?>]" value="" min="0" placeholder="<?php echo (int) $stat['max_id']; ?>" style="width:80px;" disabled>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p style="margin-top: 16px;">
                                    <button type="submit" class="button button-primary">
                                        <?php esc_html_e( 'Download Export (JSON)', 'disciple-tools-migration' ); ?>
                                    </button>
                                </p>
                            </form>
                            <script>
                            ( function( $ ) {
                                'use strict';
                                function applyExportBy( $row ) {
                                    var $select = $row.find( '.dt-migration-export-by' );
                                    var mode = $select.val();
                                    var $limit = $row.find( '.dt-migration-export-limit' );
                                    var $minId = $row.find( '.dt-migration-export-min-id' );
                                    var $maxId = $row.find( '.dt-migration-export-max-id' );
                                    if ( mode === 'all' ) {
                                        $limit.prop( 'disabled', true ).val( '' );
                                        $minId.prop( 'disabled', true ).val( '' );
                                        $maxId.prop( 'disabled', true ).val( '' );
                                    } else if ( mode === 'limit' ) {
                                        $limit.prop( 'disabled', false );
                                        $minId.prop( 'disabled', true ).val( '' );
                                        $maxId.prop( 'disabled', true ).val( '' );
                                    } else {
                                        $limit.prop( 'disabled', true ).val( '' );
                                        $minId.prop( 'disabled', false );
                                        $maxId.prop( 'disabled', false );
                                    }
                                }
                                $( document ).ready( function() {
                                    $( '.dt-migration-export-table' ).find( '.dt-migration-export-row' ).each( function() {
                                        applyExportBy( $( this ) );
                                    } );
                                    $( '.dt-migration-export-table' ).on( 'change', '.dt-migration-export-by', function() {
                                        applyExportBy( $( this ).closest( '.dt-migration-export-row' ) );
                                    } );
                                } );
                            } )( jQuery );
                            </script>
                            <?php else : ?>
                                <p>
                                    <?php esc_html_e( 'No record types are enabled for export. Enable at least one record type on the Settings tab.', 'disciple-tools-migration' ); ?>
                                </p>
                            <?php endif; ?>
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
     * Builds settings preview (tiles/fields counts per post type) for API export.
     *
     * @param array $allowed Allowed items from migration settings.
     * @return array<string, array{ tiles: int, fields: int }>
     */
    private function get_api_export_preview( array $allowed ) : array {
        $preview = [];
        if ( ! class_exists( 'DT_Posts' ) || ( empty( $allowed['tiles'] ) && empty( $allowed['fields'] ) && empty( $allowed['records'] ) ) ) {
            return $preview;
        }
        $post_types = DT_Posts::get_post_types();
        $tiles_all  = [];
        $fields_all = [];
        if ( ! empty( $allowed['tiles'] ) ) {
            foreach ( $post_types as $pt ) {
                $tiles_all[ $pt ] = DT_Posts::get_post_tiles( $pt, false );
            }
        }
        if ( ! empty( $allowed['fields'] ) ) {
            foreach ( $post_types as $pt ) {
                $fields_all[ $pt ] = DT_Posts::get_post_field_settings( $pt, false, true );
            }
        }
        $allowed_records = $allowed['records'] ?? [];
        foreach ( $allowed_records as $post_type => $enabled ) {
            if ( ! $enabled ) {
                continue;
            }
            $preview[ $post_type ] = [
                'tiles'  => isset( $tiles_all[ $post_type ] ) ? count( (array) $tiles_all[ $post_type ] ) : 0,
                'fields' => isset( $fields_all[ $post_type ] ) ? count( (array) $fields_all[ $post_type ] ) : 0,
            ];
        }
        return $preview;
    }

    /**
     * Builds records preview (count per post type) for API export.
     *
     * @param array $allowed Allowed items from migration settings.
     * @return array<string, array{ count: int }>
     */
    private function get_api_records_preview( array $allowed ) : array {
        if ( ! class_exists( 'Disciple_Tools_Migration_Export_File' ) ) {
            return [];
        }
        $stats   = Disciple_Tools_Migration_Export_File::get_record_stats();
        $allowed = $allowed['records'] ?? [];
        $result  = [];
        foreach ( $allowed as $post_type => $enabled ) {
            if ( $enabled && isset( $stats[ $post_type ] ) ) {
                $result[ $post_type ] = [ 'count' => (int) $stats[ $post_type ]['count'] ];
            }
        }
        return $result;
    }

    /**
     * Renders the Export information column.
     *
     * @param array $settings
     */
    public function right_column( array $settings ) {
        $site_url  = get_site_url();
        $wp_theme  = wp_get_theme();
        $dt_version = $wp_theme->version;
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
                        <?php
                        printf(
                            /* translators: 1: site url, 2: DT theme version */
                            esc_html__( 'Current site: %1$s (Disciple.Tools version %2$s)', 'disciple-tools-migration' ),
                            esc_html( $site_url ),
                            esc_html( $dt_version )
                        );
                        ?>
                    </p>
                    <p>
                        <?php
                        printf(
                            /* translators: %s: migration mode label */
                            esc_html__( 'Migration mode: %s', 'disciple-tools-migration' ),
                            esc_html( $settings['mode'] === 'api' ? __( 'API Endpoints', 'disciple-tools-migration' ) : __( 'Downloadable File', 'disciple-tools-migration' ) )
                        );
                        ?>
                    </p>
                    <p>
                        <?php esc_html_e( 'Export will eventually build on this configuration to produce either API responses or downloadable packages containing the selected settings and records.', 'disciple-tools-migration' ); ?>
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