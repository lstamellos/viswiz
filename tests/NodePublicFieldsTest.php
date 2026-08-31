<?php
use PHPUnit\Framework\TestCase;

final class NodePublicFieldsTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_graph_dataset_page_loads_structured_public_fields_adapter(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $admin = file_get_contents( $this->root . '/src/Admin/NodePublicFields.php' );

        self::assertStringContainsString( 'use VisWiz\\Admin\\NodePublicFields;', $plugin );
        self::assertStringContainsString( 'NodePublicFields::register();', $plugin );
        self::assertStringContainsString( "'graph' !== (string) \$dataset['schema_type']", $admin );
        self::assertStringContainsString( 'viswiz-node-public-fields.js', $admin );
        self::assertStringContainsString( "array( 'viswiz-dataset-editor-v2' )", $admin );
    }

    public function test_normal_graph_workflow_uses_structured_fields_and_advanced_raw_metadata(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-node-public-fields.js' );

        self::assertStringContainsString( 'Public fields', $javascript );
        self::assertStringContainsString( 'Add public field', $javascript );
        self::assertStringContainsString( "const TYPES = ['short', 'long', 'url', 'formatted'];", $javascript );
        self::assertStringContainsString( 'data-viswiz-public-field-row', $javascript );
        self::assertStringContainsString( 'data-viswiz-public-field-up', $javascript );
        self::assertStringContainsString( 'data-viswiz-public-field-down', $javascript );
        self::assertStringContainsString( 'Additional metadata JSON', $javascript );
        self::assertStringContainsString( 'Advanced metadata', $javascript );
        self::assertStringContainsString( 'viswizNodeMetaAdvanced', $javascript );
        self::assertStringContainsString( 'viswizRelationMetaAdvanced', $javascript );
        self::assertStringContainsString( 'delete meta.public_fields;', $javascript );
        self::assertStringContainsString( 'meta.public_fields = fields;', $javascript );
        self::assertStringContainsString( 'const advancedOnly = textarea.value;', $javascript );
        self::assertStringContainsString( 'window.setTimeout(() =>', $javascript );
        self::assertStringContainsString( 'textarea.value = advancedOnly;', $javascript );
    }

    public function test_public_fields_adapter_does_not_own_graph_data_or_mutations(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-node-public-fields.js' );

        self::assertStringNotContainsString( 'fetch(', $javascript );
        self::assertStringNotContainsString( 'restUrl', $javascript );
        self::assertStringNotContainsString( '/editor/nodes', $javascript );
        self::assertStringNotContainsString( '/editor/relations', $javascript );
        self::assertStringContainsString( "form.addEventListener('submit', () => {", $javascript );
        self::assertStringContainsString( 'syncMeta(textarea, editor.list);', $javascript );
    }

    public function test_backend_and_public_payload_keep_the_existing_sanitized_contract(): void {
        $repo = file_get_contents( $this->root . '/src/Database/DatasetRepository.php' );
        $frontend = file_get_contents( $this->root . '/src/Frontend/Frontend.php' );

        self::assertStringContainsString( "array( 'short', 'long', 'url', 'formatted' )", $repo );
        self::assertStringContainsString( "'formatted' === \$type ? wp_kses_post", $repo );
        self::assertStringContainsString( "'public_fields'", $repo );
        self::assertStringContainsString( "\$node['meta']['public_fields']", $frontend );
        self::assertStringContainsString( "'public_fields'    => \$public_fields", $frontend );
    }

    public function test_public_fields_release_keeps_database_schema_version(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        self::assertStringContainsString( 'Version: 2.0.26', $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_VERSION', '2.0.26' );", $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}