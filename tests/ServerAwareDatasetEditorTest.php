<?php
use PHPUnit\Framework\TestCase;

final class ServerAwareDatasetEditorTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_server_editor_is_registered_as_primary_dataset_detail_surface(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $this->assertStringContainsString( 'DatasetEditorPage::register();', $plugin );
        $this->assertStringContainsString( 'DatasetEditorApi::register();', $plugin );

        $page = file_get_contents( $this->root . '/src/Admin/DatasetEditorPage.php' );
        $this->assertStringContainsString( "remove_submenu_page( 'viswiz', 'viswiz-datasets' )", $page );
        $this->assertStringContainsString( 'data-viswiz-server-editor="1"', $page );
        $this->assertStringNotContainsString( 'get_payload(', $page );
        $this->assertStringNotContainsString( 'viswiz-dataset-payload', $page );
        $this->assertStringContainsString( "wp_dequeue_script( 'viswiz-frontend' )", $page );
    }

    public function test_editor_collection_api_uses_wordpress_style_bounded_pagination(): void {
        $api = file_get_contents( $this->root . '/src/Rest/DatasetEditorApi.php' );
        $this->assertStringContainsString( "'page'", $api );
        $this->assertStringContainsString( "'per_page'", $api );
        $this->assertStringContainsString( "'search'", $api );
        $this->assertStringContainsString( "'X-WP-Total'", $api );
        $this->assertStringContainsString( "'X-WP-TotalPages'", $api );
        $this->assertStringContainsString( 'DatasetCollectionRepository::MAX_PER_PAGE', $api );
        $this->assertStringContainsString( '/nodes/options', $api );
    }

    public function test_collection_repository_queries_only_requested_pages(): void {
        $source = file_get_contents( $this->root . '/src/Database/DatasetCollectionRepository.php' );
        $this->assertStringContainsString( 'public const MAX_PER_PAGE = 100', $source );
        $this->assertStringContainsString( 'LIMIT %d OFFSET %d', $source );
        $this->assertStringContainsString( 'node_options', $source );
        $this->assertStringContainsString( 'dataset_id = %d', $source );
        $this->assertStringNotContainsString( 'get_payload(', $source );
    }

    public function test_browser_editor_has_no_full_payload_state_and_uses_lazy_node_lookup(): void {
        $source = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );
        $this->assertStringContainsString( 'X-WP-Total', $source );
        $this->assertStringContainsString( 'X-WP-TotalPages', $source );
        $this->assertStringContainsString( '/nodes/options?', $source );
        $this->assertStringContainsString( 'Server paged', $source );
        $this->assertStringContainsString( 'ArrowDown', $source );
        $this->assertStringNotContainsString( 'state.payload', $source );
        $this->assertStringNotContainsString( 'pageSlice(', $source );
    }

    public function test_large_editor_does_not_require_database_schema_change(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        $this->assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}
