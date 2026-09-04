<?php
use PHPUnit\Framework\TestCase;

final class VisualizationPresetsTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_presets_are_registered_as_a_personal_admin_adapter(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $admin  = file_get_contents( $this->root . '/src/Admin/VisualizationPresets.php' );

        self::assertStringContainsString( 'use VisWiz\\Admin\\VisualizationPresets;', $plugin );
        self::assertStringContainsString( 'VisualizationPresets::register();', $plugin );
        self::assertStringContainsString( "'viswiz_visualization_presets_v1'", $admin );
        self::assertStringContainsString( 'get_user_meta( get_current_user_id()', $admin );
        self::assertStringContainsString( 'update_user_meta( get_current_user_id()', $admin );
        self::assertStringContainsString( "current_user_can( 'edit_viswiz_visualizations' )", $admin );
        self::assertStringContainsString( "check_ajax_referer( 'viswiz_visualization_presets', 'nonce' )", $admin );
    }

    public function test_presets_reuse_the_canonical_renderer_settings_contract(): void {
        $admin = file_get_contents( $this->root . '/src/Admin/VisualizationPresets.php' );

        self::assertStringContainsString( 'Frontend::sanitize_settings( $raw, $renderer )', $admin );
        self::assertStringContainsString( "array_diff( Registry::renderer_settings( \$renderer ), array( 'title' ) )", $admin );
        self::assertStringContainsString( 'array_intersect_key( $sanitized, $allowed_keys, $present_keys )', $admin );
        self::assertStringContainsString( 'MAX_PRESETS = 50', $admin );

        self::assertStringNotContainsString( 'DatasetRepository', $admin );
        self::assertStringNotContainsString( 'update_post_meta', $admin );
        self::assertStringNotContainsString( '_viswiz_dataset_id', $admin );
        self::assertStringNotContainsString( '_viswiz_source_type', $admin );
        self::assertStringNotContainsString( '_viswiz_woo_config', $admin );
    }

    public function test_client_applies_only_target_renderer_display_fields_and_reuses_live_preview_events(): void {
        $admin = file_get_contents( $this->root . '/src/Admin/VisualizationPresets.php' );
        $javascript = file_get_contents( $this->root . '/assets/viswiz-visualization-presets.js' );

        self::assertStringContainsString( "array( 'viswiz-visualization-preview' )", $admin );
        self::assertStringContainsString( 'adminCfg.renderers?.[renderer]?.settings', $javascript );
        self::assertStringContainsString( 'if (!active.has(key)) return;', $javascript );
        self::assertStringContainsString( '[name^="viswiz_settings["]', $javascript );
        self::assertStringContainsString( "field.dispatchEvent(new Event('input', { bubbles: true }))", $javascript );
        self::assertStringContainsString( "lastChanged.dispatchEvent(new Event('change', { bubbles: true }))", $javascript );
        self::assertStringNotContainsString( 'window.VisWiz.render', $javascript );
        self::assertStringNotContainsString( 'viswiz_source_type', $javascript );
        self::assertStringNotContainsString( 'viswiz_dataset_id', $javascript );
        self::assertStringNotContainsString( 'viswiz_woo', $javascript );
    }
}
