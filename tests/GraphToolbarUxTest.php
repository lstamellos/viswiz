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

    public function test_search_has_a_compact_x_clear_control_with_accessible_tooltip(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( 'viswiz-search-group', $javascript );
        self::assertStringContainsString( 'viswiz-clear-search', $javascript );
        self::assertStringContainsString( "clear.textContent = '×'", $javascript );
        self::assertStringContainsString( "clear.setAttribute('aria-label', labels.clearSearch)", $javascript );
        self::assertStringContainsString( 'clear.title = labels.clearSearch', $javascript );
        self::assertStringContainsString( "search.value = ''", $javascript );
        self::assertStringContainsString( "search.dispatchEvent(new Event('input'", $javascript );
        self::assertStringContainsString( 'flex-wrap:nowrap!important', $stylesheet );
        self::assertStringContainsString( 'width:0!important', $stylesheet );
    }

    public function test_clear_all_filters_resets_native_filters_and_all_selected_facets_but_not_search(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        $start = strpos( $javascript, "clear.addEventListener('click', () => {", strpos( $javascript, 'function ensureClearFiltersControl' ) );
        $end   = strpos( $javascript, "      });\n    }", $start );
        self::assertNotFalse( $start );
        self::assertNotFalse( $end );
        $handler = substr( $javascript, $start, $end - $start );
        self::assertStringContainsString( "select.value = ''", $handler );
        self::assertStringContainsString( "select.dispatchEvent(new Event('change'", $handler );
        self::assertStringContainsString( 'clearSelectedFacets(container)', $handler );
        self::assertStringContainsString( 'viswiz:clear-property-filter', $handler );
        self::assertStringNotContainsString( "search.value = ''", $handler );
    }

    public function test_property_click_mode_is_fade_only_and_hidden_from_ui(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( "mode.value = 'fade'", $javascript );
        self::assertStringContainsString( "mode.hidden = true", $javascript );
        self::assertStringContainsString( "mode.disabled = true", $javascript );
        self::assertStringContainsString( "group.style.opacity = selected.size && !match ? '0.18' : ''", $javascript );
        self::assertStringContainsString( "group.style.filter = selected.size && !match ? 'grayscale(1)' : ''", $javascript );
        self::assertStringNotContainsString( "group.style.display = selected.size && !match ? 'none'", $javascript );
        self::assertStringContainsString( '.viswiz-property-filter-mode,.viswiz-property-filter-clear{display:none!important}', $stylesheet );
    }

    public function test_click_selected_type_and_subtype_facets_are_multi_select_and_removable(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        self::assertStringContainsString( 'selected: new Map()', $javascript );
        self::assertStringContainsString( 'state.selected.set(key, { kind, value })', $javascript );
        self::assertStringContainsString( 'state.selected.delete(key)', $javascript );
        self::assertStringContainsString( 'function nodeMatchesSelected', $javascript );
        self::assertStringContainsString( '.some((tag) =>', $javascript );
        self::assertStringContainsString( 'viswiz-selected-facets', $javascript );
        self::assertStringContainsString( 'viswiz-selected-facet', $javascript );
        self::assertStringContainsString( 'event.stopImmediatePropagation()', $javascript );
    }

    public function test_title_zoom_and_fullscreen_share_one_stable_header_row(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( 'function ensureGraphHeader', $javascript );
        self::assertStringContainsString( 'viswiz-graph-header', $javascript );
        self::assertStringContainsString( "['−', '+', '100%'].includes", $javascript );
        self::assertStringContainsString( 'viswiz-view-controls', $javascript );
        self::assertStringContainsString( "container.querySelector('.viswiz-fullscreen')", $javascript );
        self::assertStringContainsString( 'fullscreen.parentElement !== controls', $javascript );
        self::assertStringContainsString( 'controls.appendChild(fullscreen)', $javascript );
        self::assertStringNotContainsString( 'if (!zoomButtons.length) return;', $javascript );
        self::assertStringContainsString( '.viswiz-graph-header{display:flex', $stylesheet );
        self::assertStringContainsString( '.viswiz-view-controls{position:static!important', $stylesheet );
        self::assertStringContainsString( '.viswiz-view-controls>.viswiz-fullscreen{position:static!important', $stylesheet );
    }

    public function test_search_and_native_filters_are_grouped_as_indivisible_toolbar_units(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.css' );
        self::assertStringContainsString( 'function ensureFilterGroup', $javascript );
        self::assertStringContainsString( "group.className = 'viswiz-filter-group'", $javascript );
        self::assertStringContainsString( 'nativeFilterSelects(toolbar).forEach((select) => group.appendChild(select))', $javascript );
        self::assertStringContainsString( 'if (clear) group.appendChild(clear);', $javascript );
        self::assertStringNotContainsString( 'clear && clear.parentElement !== group', $javascript );
        self::assertStringContainsString( '.viswiz-filter-group{display:flex', $stylesheet );
        self::assertStringContainsString( 'flex-wrap:nowrap', $stylesheet );
    }

    public function test_clear_all_filters_is_positioned_after_relation_filter_before_grouping(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-toolbar-ux.js' );
        self::assertStringContainsString( 'const relationSelect = selects[1] || null;', $javascript );
        self::assertStringContainsString( 'relationSelect.after(clear);', $javascript );
        self::assertStringContainsString( 'viswiz-clear-all-filters', $javascript );
    }

    public function test_compatibility_layout_css_is_attached_to_the_primary_frontend_style(): void {
        $runtime = file_get_contents( dirname( __DIR__ ) . '/src/Runtime/RenderingCompatibility.php' );
        self::assertStringContainsString( "wp_add_inline_style( 'viswiz-frontend', $css )", $runtime );
        self::assertStringNotContainsString( 'wp_enqueue_style( self::STYLE_HANDLE )', $runtime );
    }
}
