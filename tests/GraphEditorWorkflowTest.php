<?php
use PHPUnit\Framework\TestCase;

final class GraphEditorWorkflowTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_node_context_relations_stay_server_paged(): void {
        $api  = file_get_contents( $this->root . '/src/Rest/DatasetEditorApi.php' );
        $repo = file_get_contents( $this->root . '/src/Database/DatasetCollectionRepository.php' );

        self::assertStringContainsString( "'node_uuid'", $api );
        self::assertStringContainsString( 'Support::is_uuid', $api );
        self::assertStringContainsString( "(e.from_node_uuid = %s OR e.to_node_uuid = %s)", $repo );
        self::assertStringContainsString( 'from_type', $repo );
        self::assertStringContainsString( 'from_subtype', $repo );
        self::assertStringContainsString( 'to_type', $repo );
        self::assertStringContainsString( 'to_subtype', $repo );
        self::assertStringContainsString( 'LIMIT %d OFFSET %d', $repo );
    }

    public function test_primary_graph_editor_owns_the_new_workflow(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );
        $admin      = file_get_contents( $this->root . '/src/Admin/DatasetEditorPage.php' );

        self::assertStringContainsString( 'viswiz-dataset-editor.js', $admin );
        self::assertFileDoesNotExist( $this->root . '/assets/viswiz-graph-editor-workflow.js' );
        self::assertStringContainsString( 'renderNodeRelationsPanel', $javascript );
        self::assertStringContainsString( 'duplicateNodeSeed', $javascript );
        self::assertStringContainsString( 'duplicateRelationSeed', $javascript );
        self::assertStringContainsString( "button('Create node…'", $javascript );
        self::assertStringContainsString( 'data-viswiz-relation-constraint', $javascript );
        self::assertStringContainsString( 'relationConstraintMessages', $javascript );
        self::assertStringContainsString( 'node_uuid', $javascript );
        self::assertStringContainsString( '/editor/nodes', $javascript );
        self::assertStringContainsString( '/editor/relations', $javascript );
    }

    public function test_relation_constraint_feedback_remains_non_fatal(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );
        $validator  = file_get_contents( $this->root . '/src/Domain/GraphValidator.php' );

        self::assertStringContainsString( 'relation_source_type_mismatch', $validator );
        self::assertStringContainsString( "self::issue( 'warning'", $validator );
        self::assertStringContainsString( 'viswiz-relation-constraint-warning', $javascript );
        self::assertStringNotContainsString( 'viswiz_relation_constraint_error', $javascript );
    }

    public function test_graph_editor_release_keeps_database_schema_version(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        self::assertStringContainsString( 'Version: 2.0.29', $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_VERSION', '2.0.29' );", $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}