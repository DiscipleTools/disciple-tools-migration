<?php

class PluginTest extends TestCase
{
    public function test_plugin_installed() {
        activate_plugin( 'disciple-tools-migration/disciple-tools-migration.php' );

        $this->assertContains(
            'disciple-tools-migration/disciple-tools-migration.php',
            get_option( 'active_plugins' )
        );
    }
}
