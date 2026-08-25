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
        self::assertStringContainsString( 'viswiz-node-cards.js', $runtime );
        self::assertStringContainsString( 'viswiz-node-cards', $runtime );
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

    public function test_node_cards_use_self_contained_full_bleed_svg_presentation(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-node-cards.js' );
        self::assertStringContainsString( 'preserveAspectRatio', $javascript );
        self::assertStringContainsString( 'xMidYMid slice', $javascript );
        self::assertStringContainsString( 'viswiz-node-card-cover', $javascript );
        self::assertStringContainsString( 'viswiz-node-card-title-panel', $javascript );
        self::assertStringContainsString( 'viswiz-node-card-tag', $javascript );
        self::assertStringContainsString( 'node.node_type', $javascript );
        self::assertStringContainsString( 'node.node_subtype', $javascript );
        self::assertStringContainsString( 'show_type_badges', $javascript );
        self::assertStringContainsString( 'function ensureDefs', $javascript );
        self::assertStringContainsString( "fill: 'rgba(0,0,0,.72)'", $javascript );
        self::assertStringContainsString( "fill: 'rgba(15,23,42,.84)'", $javascript );
        self::assertStringContainsString( "fill: '#ffffff'", $javascript );
    }

    public function test_node_titles_are_wrapped_without_frontend_truncation(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-node-cards.js' );
        self::assertStringContainsString( 'function wrapTitle', $javascript );
        self::assertStringContainsString( 'title.replaceChildren()', $javascript );
        self::assertStringContainsString( "const width = 200", $javascript );
        self::assertStringContainsString( "const tspan = svgEl('tspan'", $javascript );
        self::assertStringNotContainsString( 'truncate(titleValue', $javascript );
    }

    public function test_graph_property_tags_are_keyboard_accessible_facets_with_fade_or_hide_modes(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-node-cards.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( 'viswiz-property-filter-mode', $javascript );
        self::assertStringContainsString( "new Option(tr('fadeOthers'", $javascript );
        self::assertStringContainsString( "new Option(tr('hideOthers'", $javascript );
        self::assertStringContainsString( "group.style.filter = active && !match && state.mode === 'fade' ? 'grayscale(1)'", $javascript );
        self::assertStringContainsString( "group.style.display = active && !match && state.mode === 'hide' ? 'none'", $javascript );
        self::assertStringContainsString( "role: 'button'", $javascript );
        self::assertStringContainsString( "tabindex: '0'", $javascript );
        self::assertStringContainsString( 'viswiz-property-filter-clear', $stylesheet );
        self::assertStringContainsString( '.viswiz-node-card-tag.is-active', $stylesheet );
    }

    public function test_node_properties_open_property_views_with_linked_node_lists(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-node-cards.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( 'function showPropertyView', $javascript );
        self::assertStringContainsString( 'viswiz-property-overlay', $javascript );
        self::assertStringContainsString( 'viswiz-property-node-list', $javascript );
        self::assertStringContainsString( 'viswiz-property-node-link', $javascript );
        self::assertStringContainsString( 'viswiz-node-property-link', $javascript );
        self::assertStringContainsString( "addPropertyLink('node_type'", $javascript );
        self::assertStringContainsString( "addPropertyLink('node_subtype'", $javascript );
        self::assertStringContainsString( 'viswiz-property-node-link', $stylesheet );
        self::assertStringContainsString( 'viswiz-node-property-link', $stylesheet );
    }

    public function test_node_modal_is_portaled_without_breaking_fullscreen_or_scroll_position(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.js' );
        self::assertStringContainsString( 'document.fullscreenElement', $javascript );
        self::assertStringContainsString( 'document.body', $javascript );
        self::assertStringContainsString( 'preventScroll', $javascript );
        self::assertStringContainsString( 'window.scrollTo', $javascript );
        self::assertStringContainsString( "overlay.dataset.viswizPortaled = '1'", $javascript );
    }

    public function test_related_nodes_become_interactive_navigation_controls(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( 'viswiz-related-node-link', $javascript );
        self::assertStringContainsString( 'openRelatedNode', $javascript );
        self::assertStringContainsString( 'viswiz-related-node-link', $stylesheet );
    }
}
