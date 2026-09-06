<?php
use PHPUnit\Framework\TestCase;

final class PublicGraphAccessibilityTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_public_graph_interactions_already_use_button_and_live_region_semantics(): void {
        $frontend = file_get_contents( $this->root . '/assets/viswiz.js' );
        $runtime  = file_get_contents( $this->root . '/assets/viswiz-graph-runtime.js' );

        self::assertStringContainsString( "class: 'viswiz-graph-node'", $frontend );
        self::assertStringContainsString( "'aria-label': `\${tr('viewNode'", $frontend );
        self::assertStringContainsString( "event.key === 'Enter' || event.key === ' '", $frontend );
        self::assertStringContainsString( "role: 'button',", $runtime );
        self::assertStringContainsString( "'aria-pressed': 'false'", $runtime );
        self::assertStringContainsString( "bar.setAttribute('aria-live', 'polite')", $runtime );
    }

    public function test_graph_runtime_hardens_modal_focus_and_dynamic_focus_return(): void {
        $runtime = file_get_contents( $this->root . '/src/Runtime/GraphRuntime.php' );

        self::assertStringContainsString( 'visibleModalOverlays', $runtime );
        self::assertStringContainsString( "event.stopImmediatePropagation()", $runtime );
        self::assertStringContainsString( "event.key === 'Escape'", $runtime );
        self::assertStringContainsString( "event.key === 'Tab'", $runtime );
        self::assertStringContainsString( 'focusableIn', $runtime );
        self::assertStringContainsString( ".viswiz-property-node-link", $runtime );
        self::assertStringContainsString( ".viswiz-selected-facet", $runtime );
        self::assertStringContainsString( ".viswiz-connection-focus-clear", $runtime );
        self::assertStringContainsString( "fallback?.focus", $runtime );
    }

    public function test_runtime_exposes_status_and_fullscreen_state_without_new_data_owner(): void {
        $runtime = file_get_contents( $this->root . '/src/Runtime/GraphRuntime.php' );

        self::assertStringContainsString( "graphStatus.setAttribute('role', 'status')", $runtime );
        self::assertStringContainsString( "facetHost.setAttribute('aria-live', 'polite')", $runtime );
        self::assertStringContainsString( "fullscreen.setAttribute('aria-pressed'", $runtime );
        self::assertStringContainsString( "document.addEventListener('fullscreenchange'", $runtime );
        self::assertStringContainsString( 'viswiz-fullscreen-status', $runtime );
        self::assertStringContainsString( 'viswiz-graph-action-status', $runtime );
        self::assertStringNotContainsString( 'fetch(', $runtime );
        self::assertStringNotContainsString( 'new WeakMap()', $runtime );
    }

    public function test_focus_visibility_contrast_and_reduced_motion_are_theme_resilient(): void {
        $runtime = file_get_contents( $this->root . '/src/Runtime/GraphRuntime.php' );

        self::assertStringContainsString( ':focus-visible', $runtime );
        self::assertStringContainsString( 'outline:3px solid #fff!important', $runtime );
        self::assertStringContainsString( 'box-shadow:0 0 0 5px #111827!important', $runtime );
        self::assertStringContainsString( 'background:#fff!important;color:#111827!important', $runtime );
        self::assertStringContainsString( '@media(prefers-reduced-motion:reduce)', $runtime );
        self::assertStringContainsString( 'transition:none!important', $runtime );
    }
}
