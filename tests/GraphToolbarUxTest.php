<?php
use PHPUnit\Framework\TestCase;

final class GraphToolbarUxTest extends TestCase {
    public function test_toolbar_ux_script_is_registered_after_graph_context(): void {
        $runtime = file_get_contents( dirname( __DIR__ ) . '/src/Runtime/RenderingCompatibility.php' );
        self::assertStringContainsString( 'TOOLBAR_UX_HANDLE', $runtime );
        self::assertStringContainsString( 'viswiz-toolbar-ux.js', $runtime );
        self::assertStringContainsString( 'array( self::GRAPH_CONTEXT_HANDLE )', $runtime );
        self::assertStringContainsString( 'wp_enqueue_script( self::TOOLBAR_UX_HANDLE )', $runtime );
    }

    public function test_search_has_a_dedicated_clear_control(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        self::assertStringContainsString( 'viswiz-search-group', $javascript );
        self::assertStringContainsString( 'viswiz-clear-search', $javascript );
        self::assertStringContainsString( "search.value = ''", $javascript );
        self::assertStringContainsString( "search.dispatchEvent(new Event('input'", $javascript );
    }

    public function test_clear_all_filters_resets_native_filters_and_property_facet_but_not_search(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        $start = strpos( $javascript, "clear.addEventListener('click', () => {", strpos( $javascript, 'function ensureClearFiltersControl' ) );
        $end   = strpos( $javascript, "    });\n    }", $start );
        self::assertNotFalse( $start );
        self::assertNotFalse( $end );
        $handler = substr( $javascript, $start, $end - $start );
        self::assertStringContainsString( "select.value = ''", $handler );
        self::assertStringContainsString( "select.dispatchEvent(new Event('change'", $handler );
        self::assertStringContainsString( "viswiz:clear-property-filter", $handler );
        self::assertStringNotContainsString( "search.value = ''", $handler );
    }

    public function test_property_click_mode_is_locked_to_fade_and_hidden_from_ui(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( "mode.value = 'fade'", $javascript );
        self::assertStringContainsString( "mode.hidden = true", $javascript );
        self::assertStringContainsString( "mode.disabled = true", $javascript );
        self::assertStringContainsString( '.viswiz-property-filter-mode{display:none!important}', $stylesheet );
    }

    public function test_clear_all_filters_is_positioned_after_relation_filter(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        self::assertStringContainsString( 'const relationSelect = selects[1] || null;', $javascript );
        self::assertStringContainsString( 'relationSelect.after(clear);', $javascript );
        self::assertStringContainsString( 'viswiz-clear-all-filters', $javascript );
    }
}
