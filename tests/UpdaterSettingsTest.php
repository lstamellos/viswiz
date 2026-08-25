<?php
use PHPUnit\Framework\TestCase;

final class UpdaterSettingsTest extends TestCase {
    public function test_auto_update_decision_uses_native_wordpress_site_option(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/src/Update/GitHubUpdater.php' );
        self::assertStringContainsString( "get_site_option( 'auto_update_plugins'", $source );
        self::assertStringContainsString( 'return self::auto_updates_enabled();', $source );
        self::assertStringNotContainsString( 'return (bool) VISWIZ_AUTO_UPDATES;', $source );
    }

    public function test_wp_admin_can_save_and_surface_the_viswiz_auto_update_setting(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/src/Update/GitHubUpdater.php' );
        self::assertStringContainsString( 'admin_post_viswiz_save_auto_update_setting', $source );
        self::assertStringContainsString( "update_site_option( 'auto_update_plugins'", $source );
        self::assertStringContainsString( 'Update settings', $source );
        self::assertStringContainsString( 'Automatically install new VisWiz releases', $source );
        self::assertStringContainsString( 'network_admin_url', $source );
    }

    public function test_legacy_constant_is_only_reported_as_deprecated_not_as_a_lock(): void {
        $source = file_get_contents( dirname( __DIR__ ) . '/src/Update/GitHubUpdater.php' );
        self::assertStringContainsString( 'VISWIZ_AUTO_UPDATES is deprecated', $source );
        self::assertStringNotContainsString( "return (bool) VISWIZ_AUTO_UPDATES", $source );
        self::assertStringNotContainsString( 'Automatic updates are locked by VISWIZ_AUTO_UPDATES', $source );
    }
}
