<?php
use PHPUnit\Framework\TestCase;

final class GraphContextFixTest extends TestCase {
    public function test_graph_context_fix_is_registered_after_node_cards(): void {
        $runtime = file_get_contents( dirname( __DIR__ ) . '/src/Runtime/RenderingCompatibility.php' );
        self::assertStringContainsString( 'viswiz-graph-context-fix.js', $runtime );
        self::assertStringContainsString( 'GRAPH_CONTEXT_HANDLE', $runtime );
        self::assertStringContainsString( 'array( self::NODE_CARD_HANDLE )', $runtime );
        self::assertStringContainsString( 'wp_enqueue_script( self::GRAPH_CONTEXT_HANDLE )', $runtime );
    }

    public function test_no_image_cards_receive_the_same_rounded_clip_as_image_cards(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-graph-context-fix.js' );
        self::assertStringContainsString( 'function fixCardClip', $javascript );
        self::assertStringContainsString( '.viswiz-node-card-title-panel', $javascript );
        self::assertStringContainsString( "document.createElementNS(svgNS, 'clipPath')", $javascript );
        self::assertStringContainsString( "panel.setAttribute('clip-path', clipValue)", $javascript );
        self::assertStringContainsString( "background.getAttribute(attribute)", $javascript );
    }

    public function test_modal_related_nodes_are_resolved_from_the_full_dataset_context(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-graph-context-fix.js' );
        self::assertStringContainsString( 'function addRelatedSection', $javascript );
        self::assertStringContainsString( 'spec?.data?.relations', $javascript );
        self::assertStringContainsString( 'spec?.data?.nodes', $javascript );
        self::assertStringContainsString( 'relation.from_node_uuid', $javascript );
        self::assertStringContainsString( 'relation.to_node_uuid', $javascript );
        self::assertStringContainsString( 'viswiz-related-node-link', $javascript );
    }

    public function test_related_node_navigation_waits_for_redraw_and_restores_search_filters(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-graph-context-fix.js' );
        self::assertStringContainsString( 'function captureVisibilityFilters', $javascript );
        self::assertStringContainsString( 'function temporarilyRevealAllNodeTypes', $javascript );
        self::assertStringContainsString( 'function waitForNode', $javascript );
        self::assertStringContainsString( 'function findNewNodeModal', $javascript );
        self::assertStringContainsString( 'restoreFilters()', $javascript );
        self::assertStringContainsString( 'snapshot.searchValue', $javascript );
        self::assertStringContainsString( 'snapshot.nodeTypeValue', $javascript );
        self::assertStringContainsString( 'nextOverlay.dataset.viswizNodeUuid', $javascript );
        self::assertStringNotContainsString( 'clearVisibilityFilters(container)', $javascript );
    }

    public function test_release_version_and_database_schema_version_are_independent(): void {
        $bootstrap = file_get_contents( dirname( __DIR__ ) . '/viswiz.php' );
        self::assertMatchesRegularExpression( "/Version:\\s+([0-9]+\\.[0-9]+\\.[0-9]+)/", $bootstrap );
        preg_match( "/Version:\\s+([0-9]+\\.[0-9]+\\.[0-9]+)/", $bootstrap, $header_match );
        preg_match( "/define\\( 'VISWIZ_VERSION', '([^']+)' \\)/", $bootstrap, $constant_match );
        self::assertSame( $header_match[1] ?? null, $constant_match[1] ?? null );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 )", $bootstrap );
    }
}
