<?php
use PHPUnit\Framework\TestCase;

final class VisualizationCreationWorkflowTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_dataset_detail_exposes_only_registry_compatible_renderers(): void {
        $source = file_get_contents( $this->root . '/src/Admin/DatasetEditorPage.php' );

        self::assertStringContainsString( 'Registry::renderers()', $source );
        self::assertStringContainsString( "in_array( \$dataset['schema_type'], \$renderer['schemas'], true )", $source );
        self::assertStringContainsString( 'data-viswiz-create-visualization', $source );
        self::assertStringContainsString( 'viswiz_visualization_create_from_dataset', $source );
        self::assertStringContainsString( "current_user_can( 'edit_viswiz_visualizations' )", $source );
    }

    public function test_creation_handler_revalidates_compatibility_and_preconnects_dataset(): void {
        $source = file_get_contents( $this->root . '/src/Admin/DatasetEditorPage.php' );

        self::assertStringContainsString( "admin_post_viswiz_visualization_create_from_dataset", $source );
        self::assertStringContainsString( 'check_admin_referer( \'viswiz_visualization_create_from_dataset_\' . $dataset_id )', $source );
        self::assertStringContainsString( 'Registry::renderer_supports_schema', $source );
        self::assertStringContainsString( "'post_type'   => 'viswiz_visualization'", $source );
        self::assertStringContainsString( "'post_status' => 'draft'", $source );
        self::assertStringContainsString( "update_post_meta( \$post_id, '_viswiz_renderer', \$renderer )", $source );
        self::assertStringContainsString( "update_post_meta( \$post_id, '_viswiz_source_type', 'dataset' )", $source );
        self::assertStringContainsString( "update_post_meta( \$post_id, '_viswiz_dataset_id', \$dataset_id )", $source );
        self::assertStringContainsString( "admin_url( 'post.php?post=' . \$post_id . '&action=edit&viswiz_created_from_dataset=1' )", $source );
    }
}
