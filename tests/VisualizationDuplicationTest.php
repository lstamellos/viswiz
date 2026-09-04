<?php
use PHPUnit\Framework\TestCase;

final class VisualizationDuplicationTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_duplication_is_registered_as_a_small_admin_adapter(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $admin  = file_get_contents( $this->root . '/src/Admin/VisualizationDuplicator.php' );

        self::assertStringContainsString( 'use VisWiz\\Admin\\VisualizationDuplicator;', $plugin );
        self::assertStringContainsString( 'VisualizationDuplicator::register();', $plugin );
        self::assertStringContainsString( "admin_post_viswiz_visualization_duplicate", $admin );
        self::assertStringContainsString( "add_filter( 'post_row_actions'", $admin );
        self::assertStringContainsString( "add_meta_boxes_viswiz_visualization", $admin );
        self::assertStringContainsString( 'data-viswiz-duplicate-visualization', $admin );
    }

    public function test_duplicate_reuses_canonical_configuration_without_copying_dataset_data(): void {
        $admin = file_get_contents( $this->root . '/src/Admin/VisualizationDuplicator.php' );

        foreach ( array(
            '_viswiz_renderer',
            '_viswiz_source_type',
            '_viswiz_dataset_id',
            '_viswiz_settings',
            '_viswiz_woo_config',
        ) as $meta_key ) {
            self::assertStringContainsString( "'{$meta_key}'", $admin );
        }

        self::assertStringContainsString( "'post_status' => 'draft'", $admin );
        self::assertStringContainsString( "'post_author' => get_current_user_id()", $admin );
        self::assertStringContainsString( "get_post_meta( \$source_id, \$meta_key, true )", $admin );
        self::assertStringContainsString( "current_user_can( 'edit_post', \$post_id )", $admin );
        self::assertStringContainsString( "current_user_can( 'edit_viswiz_visualizations' )", $admin );
        self::assertStringContainsString( "check_admin_referer( 'viswiz_visualization_duplicate_' . \$source_id )", $admin );
        self::assertStringContainsString( 'viswiz_duplicated_from=', $admin );

        self::assertStringNotContainsString( 'DatasetRepository', $admin );
        self::assertStringNotContainsString( 'duplicate_dataset', $admin );
        self::assertStringNotContainsString( '_viswiz_last_validation_error', $admin );
    }
}
