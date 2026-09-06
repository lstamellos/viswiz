<?php
use PHPUnit\Framework\TestCase;

final class SpreadsheetEditorTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_row_datasets_load_a_dedicated_spreadsheet_surface(): void {
        $bootstrap = file_get_contents( $this->root . '/viswiz.php' );
        $admin     = file_get_contents( $this->root . '/src/Admin/SpreadsheetEditor.php' );

        self::assertStringContainsString( 'VisWiz\\Admin\\SpreadsheetEditor::register();', $bootstrap );
        self::assertStringContainsString( 'viswiz-spreadsheet-editor.js', $admin );
        self::assertStringNotContainsString( 'viswiz-spreadsheet-hardening.js', $admin );
        self::assertFileDoesNotExist( $this->root . '/assets/viswiz-spreadsheet-hardening.js' );
        self::assertStringContainsString( "'graph' === \$dataset['schema_type']", $admin );
        self::assertStringContainsString( "array( 'viswiz-dataset-editor-v2' )", $admin );
    }

    public function test_batch_endpoint_validates_schema_before_one_transactional_write(): void {
        $bootstrap = file_get_contents( $this->root . '/viswiz.php' );
        $api       = file_get_contents( $this->root . '/src/Rest/SpreadsheetEditorApi.php' );
        $repo      = file_get_contents( $this->root . '/src/Database/RowBatchRepository.php' );

        self::assertStringContainsString( 'VisWiz\\Rest\\SpreadsheetEditorApi::register();', $bootstrap );
        self::assertStringContainsString( '/editor/rows/batch', $api );
        self::assertStringContainsString( 'RowSchema::normalize_for_editor', $api );
        self::assertStringContainsString( 'RowBatchRepository::MAX_BATCH', $api );
        self::assertStringContainsString( 'START TRANSACTION', $repo );
        self::assertStringContainsString( 'FOR UPDATE', $repo );
        self::assertStringContainsString( 'expected_revision', $repo );
        self::assertStringContainsString( 'Spreadsheet edit:', $repo );
        self::assertSame( 1, substr_count( $repo, '$current_revision + 1' ) );
    }

    public function test_grid_supports_explicit_save_paste_and_keyboard_navigation_without_autosave(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-spreadsheet-editor.js' );

        self::assertStringContainsString( 'Save changes', $javascript );
        self::assertStringContainsString( 'delete_uuids', $javascript );
        self::assertStringContainsString( "clipboardData?.getData('text/plain')", $javascript );
        self::assertStringContainsString( "line.split('\\t')", $javascript );
        self::assertStringContainsString( "event.key === 'Tab'", $javascript );
        self::assertStringContainsString( "event.key === 'Enter'", $javascript );
        self::assertStringContainsString( "event.key === 'ArrowUp'", $javascript );
        self::assertStringContainsString( "event.key === 'ArrowDown'", $javascript );
        self::assertStringContainsString( 'unsaved change', $javascript );
        self::assertStringContainsString( 'viswiz_revision_conflict', $javascript );
        self::assertStringContainsString( "input.tagName === 'TEXTAREA' && !text.includes('\\t')", $javascript );
        self::assertStringContainsString( 'if (sheet.serverMessage)', $javascript );
        self::assertStringContainsString( "response.headers.get('X-VisWiz-Revision')", $javascript );
        self::assertStringContainsString( 'data-viswiz-spreadsheet-server-error', $javascript );
        self::assertStringContainsString( 'SIDE_MUTATION_SELECTORS', $javascript );
        self::assertSame( 1, substr_count( $javascript, '/editor/rows/batch' ) );
    }

    public function test_collection_responses_expose_the_dataset_revision(): void {
        $api = file_get_contents( $this->root . '/src/Rest/DatasetEditorApi.php' );

        self::assertStringContainsString( "'X-VisWiz-Revision'", $api );
        self::assertSame( 3, substr_count( $api, "(int) \$dataset['revision']" ) );
    }

    public function test_spreadsheet_release_keeps_database_schema_version(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        self::assertStringContainsString( 'Version: 2.0.39', $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_VERSION', '2.0.39' );", $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}
