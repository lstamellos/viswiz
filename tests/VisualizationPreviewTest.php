<?php
use PHPUnit\Framework\TestCase;

final class VisualizationPreviewTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_saved_and_unsaved_specs_share_one_frontend_payload_builder(): void {
        $frontend = file_get_contents( $this->root . '/src/Frontend/Frontend.php' );

        self::assertStringContainsString( 'public static function get_payload', $frontend );
        self::assertStringContainsString( 'public static function preview_payload', $frontend );
        self::assertStringContainsString( 'private static function build_payload', $frontend );
        self::assertGreaterThanOrEqual( 2, substr_count( $frontend, 'return self::build_payload(' ) );
        self::assertSame( 1, substr_count( $frontend, 'private static function public_dataset_payload' ) );
        self::assertStringContainsString( "new SalesQuery()", $frontend );
        self::assertStringContainsString( 'Registry::renderer_supports_schema', $frontend );
    }

    public function test_preview_endpoint_is_editor_only_and_returns_the_shared_spec(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $api = file_get_contents( $this->root . '/src/Rest/VisualizationPreviewApi.php' );

        self::assertStringContainsString( 'VisualizationPreviewApi::register();', $plugin );
        self::assertStringContainsString( "'/visualizations/preview'", $api );
        self::assertStringContainsString( "current_user_can( 'edit_viswiz_visualizations' )", $api );
        self::assertStringContainsString( 'Frontend::preview_payload', $api );
    }

    public function test_admin_preview_reuses_public_renderer_and_graph_runtime(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $admin = file_get_contents( $this->root . '/src/Admin/VisualizationPreview.php' );
        $javascript = file_get_contents( $this->root . '/assets/viswiz-visualization-preview.js' );

        self::assertStringContainsString( 'VisualizationPreview::register();', $plugin );
        self::assertStringContainsString( "wp_enqueue_script( 'viswiz-frontend' )", $admin );
        self::assertStringContainsString( "wp_enqueue_script( 'viswiz-graph-runtime' )", $admin );
        self::assertStringContainsString( 'data-viswiz-preview-canvas', $admin );
        self::assertStringContainsString( 'Unsaved preview', $admin );
        self::assertStringContainsString( 'window.VisWiz.render(canvas, spec);', $javascript );
        self::assertStringNotContainsString( 'renderPie', $javascript );
        self::assertStringNotContainsString( 'renderGraph', $javascript );
        self::assertStringNotContainsString( 'renderers =', $javascript );
    }

    public function test_normal_save_persists_unchecked_boolean_settings_as_false(): void {
        $admin = file_get_contents( $this->root . '/src/Admin/VisualizationPreview.php' );

        self::assertStringContainsString( 'save_post_viswiz_visualization', $admin );
        self::assertStringContainsString( 'normalize_saved_settings', $admin );
        self::assertStringContainsString( 'BOOLEAN_SETTINGS', $admin );
        self::assertStringContainsString( "isset( \$raw[ \$key ] ) ? rest_sanitize_boolean( \$raw[ \$key ] ) : false", $admin );
        self::assertStringContainsString( 'Registry::renderer_exists( $renderer )', $admin );
        self::assertStringContainsString( 'Frontend::sanitize_settings( $raw, $renderer )', $admin );
    }
}
