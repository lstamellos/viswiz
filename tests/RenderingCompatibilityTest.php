<?php
use PHPUnit\Framework\TestCase;

final class RenderingCompatibilityTest extends TestCase {
    public function test_runtime_compatibility_is_registered_from_bootstrap(): void {
        $bootstrap = file_get_contents( dirname( __DIR__ ) . '/viswiz.php' );
        self::assertStringContainsString( 'RenderingCompatibility::register()', $bootstrap );
    }

    public function test_runtime_compatibility_waits_for_frontend_renderer_and_loads_styles(): void {
        $runtime = file_get_contents( dirname( __DIR__ ) . '/src/Runtime/RenderingCompatibility.php' );
        self::assertStringContainsString( "array( 'viswiz-frontend' )", $runtime );
        self::assertStringContainsString( "add_action( 'wp_footer'", $runtime );
        self::assertStringContainsString( "add_action( 'admin_enqueue_scripts'", $runtime );
        self::assertStringContainsString( 'wp_register_style', $runtime );
        self::assertStringContainsString( 'viswiz-rendering-compat.css', $runtime );
    }

    public function test_runtime_js_recovers_admin_preview_and_late_frontend_blocks(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.js' );
        self::assertStringContainsString( '[data-viswiz-inline-spec]', $javascript );
        self::assertStringContainsString( '[data-viswiz-visualization]', $javascript );
        self::assertStringContainsString( 'MutationObserver', $javascript );
        self::assertStringContainsString( 'window.VisWiz.render', $javascript );
        self::assertStringContainsString( 'window.VisWiz.load', $javascript );
    }

    public function test_graph_nodes_restore_featured_images_from_public_gallery(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.js' );
        self::assertStringContainsString( 'image_gallery', $javascript );
        self::assertStringContainsString( 'viswiz-graph-node-image', $javascript );
        self::assertStringContainsString( 'data-viswiz-node-uuid', $javascript );
        self::assertStringContainsString( 'show_node_images', $javascript );
    }

    public function test_node_modal_is_portaled_without_breaking_fullscreen_or_scroll_position(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.js' );
        self::assertStringContainsString( 'document.fullscreenElement', $javascript );
        self::assertStringContainsString( 'document.body', $javascript );
        self::assertStringContainsString( 'preventScroll', $javascript );
        self::assertStringContainsString( 'window.scrollTo', $javascript );
        self::assertStringContainsString( 'data-viswiz-portaled', $javascript );
    }

    public function test_related_nodes_become_interactive_navigation_controls(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( 'viswiz-related-node-link', $javascript );
        self::assertStringContainsString( 'openRelatedNode', $javascript );
        self::assertStringContainsString( 'viswiz-related-node-link', $stylesheet );
    }
}
