<?php
use PHPUnit\Framework\TestCase;

final class StateOwnershipAuditTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_dataset_detail_has_one_server_aware_data_owner(): void {
        $admin = file_get_contents( $this->root . '/assets/viswiz-admin.js' );
        $editor = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );
        $page = file_get_contents( $this->root . '/src/Admin/DatasetEditorPage.php' );

        self::assertStringContainsString( "'viswiz-dataset-editor-v2'", $page );
        self::assertStringContainsString( "array( 'viswiz-admin-v2', 'wp-i18n' )", $page );
        self::assertStringContainsString( 'data-viswiz-server-editor="1"', $page );
        self::assertStringContainsString( '/editor/rows', $editor );
        self::assertStringContainsString( '/editor/nodes', $editor );
        self::assertStringContainsString( '/editor/relations', $editor );
        self::assertStringNotContainsString( 'initDatasetEditor', $admin );
        self::assertStringNotContainsString( '/editor/rows', $admin );
        self::assertStringNotContainsString( '/editor/nodes', $admin );
        self::assertStringNotContainsString( '/editor/relations', $admin );
        self::assertStringNotContainsString( 'fetch(', $admin );
    }

    public function test_ui_adapters_do_not_refetch_or_own_canonical_dataset_state(): void {
        foreach ( array(
            'viswiz-node-public-fields.js',
            'viswiz-node-rich-editor.js',
            'viswiz-renderer-settings.js',
            'viswiz-visualization-presets.js',
            'viswiz-woo-source-selection.js',
            'viswiz-dataset-editor-keyboard.js',
        ) as $file ) {
            $javascript = file_get_contents( $this->root . '/assets/' . $file );
            self::assertStringNotContainsString( 'fetch(', $javascript, $file );
            self::assertStringNotContainsString( '/editor/rows', $javascript, $file );
            self::assertStringNotContainsString( '/editor/nodes', $javascript, $file );
            self::assertStringNotContainsString( '/editor/relations', $javascript, $file );
        }
    }

    public function test_public_runtime_does_not_refetch_visualization_payloads(): void {
        $frontend = file_get_contents( $this->root . '/assets/viswiz.js' );
        $runtime = file_get_contents( $this->root . '/assets/viswiz-graph-runtime.js' );

        self::assertStringNotContainsString( 'fetch(', $frontend );
        self::assertStringNotContainsString( 'fetch(', $runtime );
        self::assertStringContainsString( 'const stateMap = new WeakMap();', $runtime );
        self::assertStringContainsString( 'function stateFor(container)', $runtime );
    }

    public function test_visualization_preview_uses_the_canonical_preview_endpoint_and_public_renderer(): void {
        $preview = file_get_contents( $this->root . '/assets/viswiz-visualization-preview.js' );
        $api = file_get_contents( $this->root . '/src/Rest/VisualizationPreviewApi.php' );

        self::assertSame( 1, substr_count( $preview, 'fetch(' ) );
        self::assertStringContainsString( '/preview', $preview );
        self::assertStringContainsString( 'window.VisWiz.render', $preview );
        self::assertStringContainsString( 'Frontend::build_visualization_payload', $api );
    }
}
