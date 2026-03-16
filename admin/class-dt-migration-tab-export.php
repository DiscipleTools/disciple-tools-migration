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
                                <?php esc_html_e( 'This site is configured to serve migration exports via API endpoints.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'In a future phase, a remote Disciple.Tools site (Server B) will be able to call this site (Server A) to fetch settings and records selected on the Settings tab.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'For now, use this tab to confirm that API mode is enabled and review which areas are eligible for export.', 'disciple-tools-migration' ); ?>
                            </p>
                        <?php else : ?>
                            <p>
                                <?php esc_html_e( 'This site is configured to export migration packages as downloadable files.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'In a future phase, this tab will provide controls to generate a migration package (JSON/zip) that can be downloaded and imported into another Disciple.Tools site.', 'disciple-tools-migration' ); ?>
                            </p>
                            <p>
                                <?php esc_html_e( 'The contents of the export will respect the settings and record types you have enabled on the Settings tab.', 'disciple-tools-migration' ); ?>
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