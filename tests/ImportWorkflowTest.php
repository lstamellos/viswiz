<?php
use PHPUnit\Framework\TestCase;

final class ImportWorkflowTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_import_services_are_registered(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $this->assertStringContainsString( 'ImportUi::register();', $plugin );
        $this->assertStringContainsString( 'ImportApi::register();', $plugin );
    }

    public function test_import_routes_require_dataset_edit_capability(): void {
        $api = file_get_contents( $this->root . '/src/Rest/ImportApi.php' );
        $this->assertStringContainsString( "/datasets/(?P<id>\\d+)/import/preview", $api );
        $this->assertStringContainsString( "/datasets/(?P<id>\\d+)/import'", $api );
        $this->assertStringContainsString( "current_user_can( 'edit_viswiz_datasets' )", $api );
        $this->assertStringContainsString( 'expected_revision', $api );
        $this->assertStringContainsString( 'ImportGuard::validate', $api );
    }

    public function test_importer_uses_one_atomic_canonical_write_path(): void {
        $source = file_get_contents( $this->root . '/src/Import/DatasetImporter.php' );
        $this->assertStringContainsString( "array( 'append', 'upsert', 'replace' )", $source );
        $this->assertStringContainsString( 'replace_payload( $dataset_id', $source );
        $this->assertStringContainsString( 'GraphValidator::validate', $source );
        $this->assertStringContainsString( "private const IMPORT_KEY = '_viswiz_import_key'", $source );
        $this->assertStringContainsString( 'private const MAX_RECORDS = 20000', $source );
        $this->assertStringNotContainsString( 'START TRANSACTION', $source );
    }

    public function test_stable_keys_are_guarded_before_preview_and_commit(): void {
        $guard = file_get_contents( $this->root . '/src/Import/ImportGuard.php' );
        $this->assertStringContainsString( "'append', 'upsert'", $guard );
        $this->assertStringContainsString( 'Every upsert record needs a non-empty row key.', $guard );
        $this->assertStringContainsString( 'Every upsert record needs a non-empty external key.', $guard );
        $this->assertStringContainsString( 'Use upsert to update it.', $guard );
        $this->assertStringContainsString( "private const IMPORT_KEY = '_viswiz_import_key'", $guard );
    }

    public function test_relation_import_uses_global_type_defaults_when_columns_are_unmapped(): void {
        $api = file_get_contents( $this->root . '/src/Rest/ImportApi.php' );
        $this->assertStringContainsString( 'apply_relation_defaults', $api );
        $this->assertStringContainsString( "'inverse_label' => 'inverse_label'", $api );
        $this->assertStringContainsString( "'direction'     => 'direction'", $api );
        $this->assertStringContainsString( "'intensity'     => 'intensity'", $api );
        $this->assertStringContainsString( 'Registry::relation_types()', $api );
    }

    public function test_guided_import_ui_supports_file_paste_mapping_and_preview(): void {
        $source = file_get_contents( $this->root . '/assets/viswiz-import.js' );
        $this->assertStringContainsString( 'parseDelimited', $source );
        $this->assertStringContainsString( 'detectDelimiter', $source );
        $this->assertStringContainsString( "new TextDecoder('utf-8', { fatal: true })", $source );
        $this->assertStringContainsString( "encoding = 'windows-1253'", $source );
        $this->assertStringContainsString( 'data-viswiz-import-source', $source );
        $this->assertStringContainsString( 'data-viswiz-import-map', $source );
        $this->assertStringContainsString( '/import/preview', $source );
        $this->assertStringContainsString( 'expected_revision', $source );
        $this->assertStringContainsString( 'Advanced JSON replacement', $source );
        $this->assertStringNotContainsString( 'Papa.parse', $source );
    }

    public function test_graph_import_exposes_stable_external_key_mapping(): void {
        $source = file_get_contents( $this->root . '/assets/viswiz-import.js' );
        $this->assertStringContainsString( "['external_key', 'External key'", $source );
        $this->assertStringContainsString( "['from_key', 'From node key'", $source );
        $this->assertStringContainsString( "['to_key', 'To node key'", $source );
        $this->assertStringContainsString( "<option value=\"nodes\">Nodes</option><option value=\"relations\">Relations</option>", $source );
    }
}
