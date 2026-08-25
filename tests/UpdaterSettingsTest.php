<?php
use PHPUnit\Framework\TestCase;

final class UpdaterSettingsTest extends TestCase {
    public function test_updater_only_supplies_update_metadata_and_package(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/src/Update/GitHubUpdater.php' );
        self::assertStringContainsString( "pre_set_site_transient_update_plugins", $source );
        self::assertStringContainsString( "plugins_api", $source );
        self::assertStringContainsString( "upgrader_source_selection", $source );
        self::assertStringContainsString( '$update->plugin', $source );
        self::assertStringContainsString( '$update->new_version', $source );
        self::assertStringContainsString( '$update->package', $source );
    }

    public function test_updater_does_not_override_wordpress_auto_update_decision(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/src/Update/GitHubUpdater.php' );
        self::assertStringNotContainsString( "add_filter( 'auto_update_plugin'", $source );
        self::assertStringNotContainsString( "get_site_option( 'auto_update_plugins'", $source );
        self::assertStringNotContainsString( "update_site_option( 'auto_update_plugins'", $source );
        self::assertStringNotContainsString( 'VISWIZ_AUTO_UPDATES', $source );
        self::assertStringNotContainsString( 'disable_autoupdate', $source );
        self::assertStringNotContainsString( '$update->autoupdate', $source );
    }

    public function test_plugin_has_no_duplicate_auto_update_settings_ui(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/src/Update/GitHubUpdater.php' );
        self::assertStringNotContainsString( "admin_post_viswiz_save_auto_update_setting", $source );
        self::assertStringNotContainsString( "add_action( 'admin_menu'", $source );
        self::assertStringNotContainsString( "add_action( 'network_admin_menu'", $source );
        self::assertStringNotContainsString( 'viswiz-updates', $source );
        self::assertStringNotContainsString( 'Automatically install new VisWiz releases', $source );
        self::assertStringNotContainsString( 'Update settings', $source );
    }

    public function test_obsolete_update_capability_is_removed_on_capability_upgrade(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' );
        self::assertStringContainsString( 'CAPABILITIES_VERSION = 2', $source );
        self::assertStringContainsString( "remove_cap( 'manage_viswiz_updates' )", $source );
        self::assertStringNotContainsString( "array( 'manage_viswiz_schema', 'manage_viswiz_settings', 'manage_viswiz_updates' )", $source );
    }
}
