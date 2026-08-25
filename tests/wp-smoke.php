<?php
use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\Registry;
use VisWiz\Frontend\Frontend;
use VisWiz\WooCommerce\SalesQuery;

function viswiz_smoke_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
}

viswiz_smoke_assert( (int) get_option( 'viswiz_db_schema_version' ) === VISWIZ_DB_VERSION, 'DB migration did not complete.' );

$repo = new DatasetRepository();
$id = $repo->create( array( 'name' => 'CI graph', 'schema_type' => 'graph' ) );
viswiz_smoke_assert( ! is_wp_error( $id ), 'Could not create graph dataset.' );
$id = (int) $id;
$dataset = $repo->get( $id );
viswiz_smoke_assert( 1 === (int) $dataset['revision'], 'Initial revision is not 1.' );

$a = array( 'uuid' => '11111111-1111-4111-8111-111111111111', 'slug' => 'person-a', 'title' => 'Person A', 'node_type' => 'person' );
$b = array( 'uuid' => '22222222-2222-4222-8222-222222222222', 'slug' => 'org-b', 'title' => 'Org B', 'node_type' => 'organization' );
$r = $repo->save_node( $id, $a, 1 ); viswiz_smoke_assert( ! is_wp_error( $r ) && 2 === $r['revision'], 'Node A targeted save failed.' );
$r = $repo->save_node( $id, $b, 2 ); viswiz_smoke_assert( ! is_wp_error( $r ) && 3 === $r['revision'], 'Node B targeted save failed.' );
$r = $repo->save_edge( $id, array( 'uuid' => '33333333-3333-4333-8333-333333333333', 'from_node_uuid' => $a['uuid'], 'to_node_uuid' => $b['uuid'], 'relation_type' => 'member_of', 'label' => 'Member of' ), 3 );
viswiz_smoke_assert( ! is_wp_error( $r ) && 4 === $r['revision'], 'Relation targeted save failed.' );

$a['slug'] = 'person-a-renamed';
$r = $repo->save_node( $id, $a, 4 );
viswiz_smoke_assert( ! is_wp_error( $r ) && 5 === $r['revision'], 'Node slug rename failed.' );
$relations = $repo->get_edges( $id );
viswiz_smoke_assert( $relations[0]['from_node_uuid'] === $a['uuid'], 'Changing an editable slug changed relation identity.' );

$conflict = $repo->save_node( $id, $a, 4 );
viswiz_smoke_assert( is_wp_error( $conflict ) && 'viswiz_revision_conflict' === $conflict->get_error_code(), 'Optimistic concurrency conflict was not enforced.' );

$post_id = wp_insert_post( array( 'post_type' => 'viswiz_visualization', 'post_title' => 'CI visualization', 'post_status' => 'publish' ) );
update_post_meta( $post_id, '_viswiz_renderer', 'graph' );
update_post_meta( $post_id, '_viswiz_source_type', 'dataset' );
update_post_meta( $post_id, '_viswiz_dataset_id', $id );
update_post_meta( $post_id, '_viswiz_settings', array() );
$public = Frontend::get_payload( $post_id );
viswiz_smoke_assert( ! is_wp_error( $public ) && 2 === count( $public['data']['nodes'] ), 'Published graph payload failed.' );

$duplicate = $repo->duplicate( $id );
viswiz_smoke_assert( ! is_wp_error( $duplicate ) && 2 === count( $repo->get_nodes( (int) $duplicate ) ), 'Dataset duplication failed.' );

$editor = wp_create_user( 'viswiz-ci-editor', wp_generate_password(), 'viswiz-ci-editor@example.invalid' );
$user = get_user_by( 'id', $editor ); $user->set_role( 'editor' ); wp_set_current_user( $editor );
$schemaAttempt = Registry::update_node_types( Registry::default_node_types() );
viswiz_smoke_assert( is_wp_error( $schemaAttempt ) && 'viswiz_forbidden_schema' === $schemaAttempt->get_error_code(), 'Editor could mutate global schema.' );

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( $admin ) { wp_set_current_user( $admin[0]->ID ); }

if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_create_order' ) ) {
    $order = wc_create_order();
    $order->set_total( 123.45 );
    $order->set_status( 'completed' );
    $order->save();
    $woo = ( new SalesQuery() )->query( array( 'metric' => 'revenue', 'group_by' => 'total', 'period_value' => 1, 'period_unit' => 'days' ), false );
    viswiz_smoke_assert( ! is_wp_error( $woo ) && ! empty( $woo['rows'] ), 'WooCommerce query failed.' );
    viswiz_smoke_assert( (float) $woo['rows'][0]['value'] >= 123.45, 'WooCommerce revenue total omitted the CI order.' );
}

$repo->delete_with_usage_cleanup( $id );
viswiz_smoke_assert( 0 === (int) get_post_meta( $post_id, '_viswiz_dataset_id', true ), 'Dataset deletion did not detach visualization.' );

echo "VisWiz WordPress smoke tests passed.\n";
