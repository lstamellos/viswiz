<?php
use PHPUnit\Framework\TestCase;

final class PretestHardeningTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_external_row_write_paths_share_the_schema_guard(): void {
        $api   = file_get_contents( $this->root . '/src/Rest/Api.php' );
        $guard = file_get_contents( $this->root . '/src/Domain/RowWriteGuard.php' );

        self::assertStringContainsString( 'use VisWiz\\Domain\\RowWriteGuard;', $api );
        self::assertGreaterThanOrEqual( 2, substr_count( $api, 'RowWriteGuard::normalize_payload' ) );
        self::assertStringContainsString( 'RowWriteGuard::normalize_row', $api );
        self::assertStringContainsString( 'RowSchema::normalize_for_editor', $guard );
        self::assertStringContainsString( 'canonicalize_aliases', $guard );
        self::assertStringContainsString( "'x_value'", $guard );
        self::assertStringContainsString( "'x_numeric'", $guard );
        self::assertStringContainsString( "'y_value'", $guard );
        self::assertStringContainsString( "'latitude'", $guard );
        self::assertStringContainsString( "'longitude'", $guard );
        self::assertStringContainsString( 'viswiz_row_payload_validation', $guard );
    }

    public function test_spreadsheet_state_owner_surfaces_errors_and_guards_side_mutations(): void {
        $admin      = file_get_contents( $this->root . '/src/Admin/SpreadsheetEditor.php' );
        $javascript = file_get_contents( $this->root . '/assets/viswiz-spreadsheet-editor.js' );

        self::assertStringContainsString( 'viswiz-spreadsheet-editor.js', $admin );
        self::assertStringNotContainsString( 'viswiz-spreadsheet-hardening.js', $admin );
        self::assertFileDoesNotExist( $this->root . '/assets/viswiz-spreadsheet-hardening.js' );
        self::assertStringContainsString( 'sheet.serverMessage', $javascript );
        self::assertStringContainsString( 'data-viswiz-spreadsheet-server-error', $javascript );
        self::assertStringContainsString( 'data-viswiz-import-button', $javascript );
        self::assertStringContainsString( 'data-viswiz-commerce-snapshot', $javascript );
        self::assertStringContainsString( 'data-viswiz-restore-revision', $javascript );
        self::assertStringContainsString( 'Save or discard spreadsheet changes', $javascript );
    }

    public function test_hardening_release_keeps_database_schema_version(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        self::assertStringContainsString( 'Version: 2.0.22', $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_VERSION', '2.0.22' );", $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}
