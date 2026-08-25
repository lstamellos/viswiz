<?php
use PHPUnit\Framework\TestCase;
use VisWiz\Domain\GraphValidator;
use VisWiz\Domain\Registry;

final class GraphValidatorTest extends TestCase {
    public function test_valid_graph_has_no_errors(): void {
        $payload = array(
            'nodes' => array(
                array( 'uuid' => '11111111-1111-4111-8111-111111111111', 'slug' => 'a', 'title' => 'A', 'node_type' => 'person' ),
                array( 'uuid' => '22222222-2222-4222-8222-222222222222', 'slug' => 'b', 'title' => 'B', 'node_type' => 'organization' ),
            ),
            'relations' => array(
                array( 'from_node_uuid' => '11111111-1111-4111-8111-111111111111', 'to_node_uuid' => '22222222-2222-4222-8222-222222222222', 'relation_type' => 'member_of' ),
            ),
        );
        $issues = GraphValidator::validate( $payload, Registry::default_node_types(), Registry::default_relation_types() );
        $errors = array_filter( $issues, static fn( array $issue ): bool => 'error' === $issue['severity'] );
        $this->assertSame( array(), array_values( $errors ) );
    }

    public function test_duplicate_and_orphan_data_are_rejected(): void {
        $uuid = '11111111-1111-4111-8111-111111111111';
        $payload = array(
            'nodes' => array(
                array( 'uuid' => $uuid, 'slug' => 'same', 'title' => 'A', 'node_type' => 'person' ),
                array( 'uuid' => $uuid, 'slug' => 'same', 'title' => '', 'node_type' => '' ),
            ),
            'relations' => array(
                array( 'from_node_uuid' => $uuid, 'to_node_uuid' => '33333333-3333-4333-8333-333333333333', 'relation_type' => 'member_of' ),
            ),
        );
        $codes = array_column( GraphValidator::validate( $payload, Registry::default_node_types(), Registry::default_relation_types() ), 'code' );
        $this->assertContains( 'duplicate_node_uuid', $codes );
        $this->assertContains( 'duplicate_node_slug', $codes );
        $this->assertContains( 'missing_node_title', $codes );
        $this->assertContains( 'missing_node_type', $codes );
        $this->assertContains( 'missing_relation_target', $codes );
    }

    public function test_relation_schema_mismatch_is_warning_not_data_loss_error(): void {
        $payload = array(
            'nodes' => array(
                array( 'uuid' => '11111111-1111-4111-8111-111111111111', 'slug' => 'org', 'title' => 'Org', 'node_type' => 'organization' ),
                array( 'uuid' => '22222222-2222-4222-8222-222222222222', 'slug' => 'person', 'title' => 'Person', 'node_type' => 'person' ),
            ),
            'relations' => array(
                array( 'from_node_uuid' => '11111111-1111-4111-8111-111111111111', 'to_node_uuid' => '22222222-2222-4222-8222-222222222222', 'relation_type' => 'member_of' ),
            ),
        );
        $issues = GraphValidator::validate( $payload, Registry::default_node_types(), Registry::default_relation_types() );
        $this->assertContains( 'relation_source_type_mismatch', array_column( $issues, 'code' ) );
    }
}
