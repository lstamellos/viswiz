<?php
use PHPUnit\Framework\TestCase;

final class GraphRuntimeTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_graph_runtime_is_one_frontend_enhancement_asset(): void {
        $runtime = file_get_contents( $this->root . '/src/Runtime/GraphRuntime.php' );
        $bootstrap = file_get_contents( $this->root . '/viswiz.php' );

        self::assertStringContainsString( 'viswiz-graph-runtime.js', $runtime );
        self::assertStringContainsString( 'viswiz-graph-runtime.css', $runtime );
        self::assertStringContainsString( "array( 'viswiz-frontend' )", $runtime );
        self::assertStringContainsString( 'VisWiz\\Runtime\\GraphRuntime::register();', $bootstrap );

        foreach ( array(
            'viswiz-rendering-compat.js',
            'viswiz-node-cards.js',
            'viswiz-graph-context-fix.js',
            'viswiz-toolbar-ux.js',
            'viswiz-connection-focus.js',
        ) as $legacy ) {
            self::assertStringNotContainsString( $legacy, $runtime );
            self::assertFileDoesNotExist( $this->root . '/assets/' . $legacy );
        }
    }

    public function test_runtime_has_one_per_visualization_state_model(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-graph-runtime.js' );

        self::assertStringContainsString( 'const stateMap = new WeakMap();', $javascript );
        self::assertStringContainsString( 'function stateFor(container)', $javascript );
        self::assertStringContainsString( 'selectedFacets: new Map()', $javascript );
        self::assertStringContainsString( "focusUuid: ''", $javascript );
        self::assertStringContainsString( 'function setSpec(container, spec)', $javascript );
        self::assertStringContainsString( 'api.render = (container, spec) =>', $javascript );
        self::assertStringNotContainsString( 'const specCache = new WeakMap()', $javascript );
        self::assertStringNotContainsString( 'const facetState = new WeakMap()', $javascript );
    }

    public function test_admin_preview_can_supply_the_live_editor_spec(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-graph-runtime.js' );
        $runtime = file_get_contents( $this->root . '/src/Runtime/GraphRuntime.php' );

        self::assertStringContainsString( 'if (isGraphSpec(spec)) setSpec(container, spec);', $javascript );
        self::assertStringContainsString( 'state.spec = spec;', $javascript );
        self::assertStringContainsString( 'window.VisWizGraphRuntime', $javascript );
        self::assertStringContainsString( 'wp_add_inline_script( self::SCRIPT_HANDLE, $bootstrap,', $runtime );
        self::assertStringContainsString( 'window.VisWizGraphRuntime?.setSpec(container, spec);', $runtime );
        self::assertStringContainsString( "container.querySelector('.viswiz-graph-frame')", $runtime );
    }

    public function test_runtime_preserves_graph_card_facets_modal_navigation_and_focus(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-graph-runtime.js' );

        self::assertStringContainsString( 'function wrapTitle', $javascript );
        self::assertStringContainsString( 'viswiz-node-card-tag', $javascript );
        self::assertStringContainsString( 'function applyFacets', $javascript );
        self::assertStringContainsString( 'viswiz-selected-facets', $javascript );
        self::assertStringContainsString( 'function openRelatedNode', $javascript );
        self::assertStringContainsString( 'function waitForNode', $javascript );
        self::assertStringContainsString( 'function showPropertyView', $javascript );
        self::assertStringContainsString( 'function connectionNeighborhood', $javascript );
        self::assertStringContainsString( 'function focusConnections', $javascript );
        self::assertStringContainsString( 'focusHops', $javascript );
    }

    public function test_global_observer_only_tracks_structural_child_changes(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-graph-runtime.js' );

        self::assertStringContainsString(
            "observer.observe(document.documentElement, { childList: true, subtree: true });",
            $javascript
        );
        self::assertStringNotContainsString( 'attributes: true', $javascript );
        self::assertStringNotContainsString( 'attributeFilter:', $javascript );
    }

    public function test_release_bump_does_not_change_database_schema_version(): void {
        $bootstrap = file_get_contents( $this->root . '/viswiz.php' );

        self::assertStringContainsString( 'Version: 2.0.23', $bootstrap );
        self::assertStringContainsString( "define( 'VISWIZ_VERSION', '2.0.23' );", $bootstrap );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $bootstrap );
    }
}
