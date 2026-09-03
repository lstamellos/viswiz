<?php
use PHPUnit\Framework\TestCase;

final class RendererSpecificSettingsTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_registry_is_the_renderer_capability_source(): void {
        $registry = file_get_contents( $this->root . '/src/Domain/Registry.php' );

        self::assertStringContainsString( "'woo_live'", $registry );
        self::assertStringContainsString( "'settings'", $registry );
        self::assertStringContainsString( 'renderer_supports_woo_live', $registry );
        self::assertStringContainsString( 'renderer_settings', $registry );
        self::assertStringContainsString( 'renderer_setting_defaults', $registry );
        self::assertStringContainsString( "'pie'", $registry );
        self::assertStringContainsString( "array_merge( \$common, array( 'show_legend' ) )", $registry );
        self::assertStringContainsString( "array_merge( \$common, array( 'target' ) )", $registry );
        self::assertStringContainsString( "'show_graph_toolbar'", $registry );
        self::assertStringContainsString( "'node_modal_related_heading'", $registry );
    }

    public function test_public_and_preview_payloads_apply_renderer_defaults_and_source_capabilities(): void {
        $frontend = file_get_contents( $this->root . '/src/Frontend/Frontend.php' );

        self::assertStringContainsString( 'self::sanitize_settings( $config[\'settings\'] ?? array(), $renderer )', $frontend );
        self::assertStringContainsString( 'Registry::renderer_supports_woo_live( $renderer )', $frontend );
        self::assertStringContainsString( "'viswiz_renderer_source_mismatch'", $frontend );
        self::assertStringContainsString( "public static function sanitize_settings( mixed \$value, string \$renderer = '' )", $frontend );
        self::assertStringContainsString( 'Registry::renderer_setting_defaults( $renderer )', $frontend );
    }

    public function test_admin_adapter_groups_controls_without_a_renderer_family_map(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-renderer-settings.js' );
        $preview = file_get_contents( $this->root . '/src/Admin/VisualizationPreview.php' );

        foreach ( array( 'Data / source', 'Appearance', 'Labels / content', 'Interaction', 'Advanced' ) as $heading ) {
            self::assertStringContainsString( $heading, $javascript );
        }
        self::assertStringContainsString( 'cfg.renderers?.[renderer.value]', $javascript );
        self::assertStringContainsString( 'meta.settings', $javascript );
        self::assertStringContainsString( 'meta.woo_live', $javascript );
        self::assertStringContainsString( 'runtime.wooAvailable === true', $javascript );
        self::assertStringNotContainsString( "['graph', 'flow_diagram', 'org_chart'", $javascript );
        self::assertStringNotContainsString( ':has(', $javascript );
        self::assertStringContainsString( 'viswiz-renderer-settings.js', $preview );
        self::assertStringContainsString( "'VisWizRendererSettings'", $preview );
        self::assertStringContainsString( "'wooAvailable' => class_exists( '\\WooCommerce' )", $preview );
        self::assertStringContainsString( "array( 'viswiz-renderer-settings', 'viswiz-frontend', 'viswiz-graph-runtime' )", $preview );
    }

    public function test_settings_release_does_not_require_a_database_schema_change(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}
