<?php
use PHPUnit\Framework\TestCase;

final class AdministratorDiagnosticsTest extends TestCase {
    public function test_diagnostics_are_bounded_and_do_not_log_payloads(): void {
        $root = dirname( __DIR__ );
        $diagnostics = file_get_contents( $root . '/src/Diagnostics.php' );
        $page = file_get_contents( $root . '/src/Admin/DiagnosticsPage.php' );
        $plugin = file_get_contents( $root . '/src/Plugin.php' );
        $api = file_get_contents( $root . '/src/Rest/Api.php' );
        $import = file_get_contents( $root . '/src/Rest/ImportApi.php' );
        $migration = file_get_contents( $root . '/src/Database/Migrator.php' );

        self::assertStringContainsString( 'private const MAX_EVENTS = 20', $diagnostics );
        self::assertStringContainsString( 'sanitize_key( $code )', $diagnostics );
        self::assertStringContainsString( "array_slice( $events, 0, self::MAX_EVENTS )", $diagnostics );
        self::assertStringContainsString( "get_transient( $throttle )", $diagnostics );
        self::assertStringContainsString( "'manage_viswiz_settings'", $page );
        self::assertStringContainsString( 'DiagnosticsPage::register();', $plugin );
        self::assertStringContainsString( "Diagnostics::record( 'public_load'", $api );
        self::assertStringContainsString( "Diagnostics::record( 'import_preview'", $import );
        self::assertStringContainsString( "Diagnostics::record( 'import_commit'", $import );
        self::assertStringContainsString( "Diagnostics::record( 'migration'", $migration );
        self::assertStringNotContainsString( 'REQUEST_URI', $diagnostics );
        self::assertStringNotContainsString( 'query_string', $diagnostics );
    }
}
