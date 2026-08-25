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
        self::assertStringContainsString( 'clearVisibilityFilters(container)', $javascript );
    }

    public function test_release_bump_does_not_change_database_schema_version(): void {
        $bootstrap = file_get_contents( dirname( __DIR__ ) . '/viswiz.php' );
        self::assertStringContainsString( "Version: 2.0.8", $bootstrap );
        self::assertStringContainsString( "define( 'VISWIZ_VERSION', '2.0.8' )", $bootstrap );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 )", $bootstrap );
    }
}
