<?php
use PHPUnit\Framework\TestCase;

final class ConnectionFocusTest extends TestCase {
    public function test_connection_focus_module_is_registered_after_toolbar_ux(): void {
        $runtime = file_get_contents( dirname( __DIR__ ) . '/src/Runtime/RenderingCompatibility.php' );
        self::assertStringContainsString( 'CONNECTION_FOCUS_HANDLE', $runtime );
        self::assertStringContainsString( 'viswiz-connection-focus.js', $runtime );
        self::assertStringContainsString( 'array( self::TOOLBAR_UX_HANDLE )', $runtime );
        self::assertStringContainsString( 'wp_enqueue_script( self::CONNECTION_FOCUS_HANDLE )', $runtime );
        self::assertStringContainsString( 'viswiz-connection-focus.css', $runtime );
    }

    public function test_focus_is_a_contextual_node_action_not_a_permanent_toolbar_control(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-connection-focus.js' );
        self::assertStringContainsString( "button.className = 'viswiz-focus-connections'", $javascript );
        self::assertStringContainsString( 'modal.querySelector', $javascript );
        self::assertStringContainsString( 'toolbar.after(bar)', $javascript );
        self::assertStringContainsString( "bar.hidden = true", $javascript );
        self::assertStringContainsString( 'focusConnections(container, node.uuid, 1)', $javascript );
    }

    public function test_neighborhood_supports_one_and_two_hops_in_both_directions(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-connection-focus.js' );
        self::assertStringContainsString( 'function connectionNeighborhood', $javascript );
        self::assertStringContainsString( 'adjacency.get(from).add(to)', $javascript );
        self::assertStringContainsString( 'adjacency.get(to).add(from)', $javascript );
        self::assertStringContainsString( 'const depth = hops === 2 ? 2 : 1', $javascript );
        self::assertStringContainsString( "state.hops = hops === 2 ? 2 : 1", $javascript );
    }

    public function test_relation_filter_limits_the_connection_neighborhood(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-connection-focus.js' );
        self::assertStringContainsString( 'function currentRelationType', $javascript );
        self::assertStringContainsString( 'if (relationType && relation.relation_type !== relationType) return;', $javascript );
        self::assertStringContainsString( 'currentRelationType(container)', $javascript );
    }

    public function test_focus_hides_nodes_and_edges_outside_the_neighborhood(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-connection-focus.js' );
        $stylesheet = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-connection-focus.css' );
        self::assertStringContainsString( 'is-viswiz-connection-outside', $javascript );
        self::assertStringContainsString( 'is-viswiz-connection-focus-edge-outside', $javascript );
        self::assertStringContainsString( 'display:none!important', $stylesheet );
        self::assertStringContainsString( 'is-viswiz-connection-root', $javascript );
    }

    public function test_active_focus_bar_can_switch_hops_and_clear_focus(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-connection-focus.js' );
        self::assertStringContainsString( "one.dataset.hops = '1'", $javascript );
        self::assertStringContainsString( "two.dataset.hops = '2'", $javascript );
        self::assertStringContainsString( "button.setAttribute('aria-pressed'", $javascript );
        self::assertStringContainsString( "clear.setAttribute('aria-label', labels.clearFocus)", $javascript );
        self::assertStringContainsString( 'clearFocus(container)', $javascript );
    }

    public function test_focus_reapplies_after_native_graph_redraws(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-connection-focus.js' );
        self::assertStringContainsString( 'MutationObserver', $javascript );
        self::assertStringContainsString( "node.matches('.viswiz-graph-node,.viswiz-graph-edge,.viswiz-graph-edge-label,.viswiz-graph-stage,.viswiz-graph-toolbar')", $javascript );
        self::assertStringContainsString( "toolbar?.addEventListener('input'", $javascript );
        self::assertStringContainsString( "toolbar?.addEventListener('change'", $javascript );
    }
}
