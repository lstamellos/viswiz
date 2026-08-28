<?php
use PHPUnit\Framework\TestCase;

final class SchemaAwareDatasetEditorTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_registry_defines_editor_contracts_for_every_row_schema(): void {
        $source = file_get_contents( $this->root . '/src/Domain/Registry.php' );
        foreach ( array( 'categorical', 'time_series', 'xy', 'geo', 'progress', 'diagram' ) as $schema ) {
            $this->assertStringContainsString( "'{$schema}'", $source );
        }
        foreach ( array( 'meta.target', 'meta.text', 'datetime-local', 'Latitude', 'Longitude', 'Section title' ) as $contract ) {
            $this->assertStringContainsString( $contract, $source );
        }
    }

    public function test_browser_row_editor_is_schema_driven_and_keeps_advanced_data_separate(): void {
        $source = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );
        $this->assertStringContainsString( 'cfg.schemas?.[state.schema]?.editor', $source );
        $this->assertStringContainsString( 'data-viswiz-schema-field', $source );
        $this->assertStringContainsString( 'Additional metadata JSON', $source );
        $this->assertStringContainsString( 'Stable key', $source );
        $this->assertStringContainsString( 'viswiz-editor-advanced', $source );
        $this->assertStringNotContainsString( '<th>Label</th><th>Value</th><th>X/date</th><th>Y</th><th>Lat</th><th>Lng</th>', $source );
    }

    public function test_targeted_row_writes_apply_server_side_schema_validation(): void {
        $api = file_get_contents( $this->root . '/src/Rest/DatasetEditorApi.php' );
        $schema = file_get_contents( $this->root . '/src/Domain/RowSchema.php' );
        $this->assertStringContainsString( 'RowSchema::normalize_for_editor', $api );
        $this->assertStringContainsString( 'viswiz_invalid_row_schema', $schema );
        $this->assertStringContainsString( "'time_series'", $schema );
        $this->assertStringContainsString( "'progress'", $schema );
        $this->assertStringContainsString( "'diagram'", $schema );
        $this->assertStringContainsString( 'strtotime( $x_value )', $schema );
    }

    public function test_schema_aware_editor_does_not_change_database_schema_version(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        $this->assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}
