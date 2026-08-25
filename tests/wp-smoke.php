<?php
use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\Registry;
use VisWiz\Frontend\Frontend;
use VisWiz\Database\Migrator;
use VisWiz\Support;
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

$bad_uuid = $repo->save_node( $id, array( 'uuid' => 'not-a-uuid', 'slug' => 'bad', 'title' => 'Bad', 'node_type' => 'person' ), 4 );
viswiz_smoke_assert( is_wp_error( $bad_uuid ) && 'viswiz_invalid_uuid' === $bad_uuid->get_error_code(), 'Malformed node UUID was not rejected.' );
$missing_endpoint = $repo->save_edge( $id, array( 'uuid' => '44444444-4444-4444-8444-444444444444', 'from_node_uuid' => $a['uuid'], 'to_node_uuid' => '55555555-5555-4555-8555-555555555555', 'relation_type' => 'member_of' ), 4 );
viswiz_smoke_assert( is_wp_error( $missing_endpoint ) && 'viswiz_relation_endpoint_missing' === $missing_endpoint->get_error_code(), 'Missing relation endpoint was not rejected.' );

$a['slug'] = 'person-a-renamed';
$r = $repo->save_node( $id, $a, 4 );
viswiz_smoke_assert( ! is_wp_error( $r ) && 5 === $r['revision'], 'Node slug rename failed.' );
$relations = $repo->get_edges( $id );
viswiz_smoke_assert( $relations[0]['from_node_uuid'] === $a['uuid'], 'Changing an editable slug changed relation identity.' );

$conflict = $repo->save_node( $id, $a, 4 );
viswiz_smoke_assert( is_wp_error( $conflict ) && 'viswiz_revision_conflict' === $conflict->get_error_code(), 'Optimistic concurrency conflict was not enforced.' );

$restored = $repo->restore_revision( $id, 4, 5 );
viswiz_smoke_assert( ! is_wp_error( $restored ) && 6 === $restored['revision'], 'Dataset revision restore failed.' );
$restored_nodes = $repo->get_nodes( $id );
$restored_a = array_values( array_filter( $restored_nodes, static fn( array $node ): bool => $node['uuid'] === $a['uuid'] ) );
viswiz_smoke_assert( $restored_a && 'person-a' === $restored_a[0]['slug'], 'Revision restore did not recover the previous node state.' );

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

// Exercise the 1.x -> 2.x migration against real legacy table names and prove
// that the migration copies data instead of mutating the legacy tables in place.
global $wpdb;
$legacy_datasets  = Support::legacy_table( 'datasets' );
$legacy_points    = Support::legacy_table( 'data_points' );
$legacy_relations = Support::legacy_table( 'relations' );
$charset          = $wpdb->get_charset_collate();
$wpdb->query( "DROP TABLE IF EXISTS {$legacy_relations}" );
$wpdb->query( "DROP TABLE IF EXISTS {$legacy_points}" );
$wpdb->query( "DROP TABLE IF EXISTS {$legacy_datasets}" );
$wpdb->query( "CREATE TABLE {$legacy_datasets} (id bigint unsigned NOT NULL AUTO_INCREMENT,name varchar(190) NOT NULL,description text NULL,data_type varchar(40) NOT NULL DEFAULT 'generic',created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY(id)) {$charset}" );
$wpdb->query( "CREATE TABLE {$legacy_points} (id bigint unsigned NOT NULL AUTO_INCREMENT,visualization_id bigint unsigned NOT NULL DEFAULT 0,dataset_id bigint unsigned NOT NULL DEFAULT 0,point_key varchar(190) NOT NULL DEFAULT '',label varchar(190) NOT NULL DEFAULT '',value double NOT NULL DEFAULT 0,color varchar(20) NOT NULL DEFAULT '',meta longtext NULL,sort_order int NOT NULL DEFAULT 0,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY(id)) {$charset}" );
$wpdb->query( "CREATE TABLE {$legacy_relations} (id bigint unsigned NOT NULL AUTO_INCREMENT,visualization_id bigint unsigned NOT NULL DEFAULT 0,dataset_id bigint unsigned NOT NULL DEFAULT 0,from_key varchar(190) NOT NULL DEFAULT '',to_key varchar(190) NOT NULL DEFAULT '',label varchar(190) NOT NULL DEFAULT '',direction varchar(20) NOT NULL DEFAULT 'directed',intensity double NOT NULL DEFAULT 1,relation_type varchar(80) NOT NULL DEFAULT '',meta longtext NULL,sort_order int NOT NULL DEFAULT 0,created_at datetime NOT NULL,updated_at datetime NOT NULL,PRIMARY KEY(id)) {$charset}" );
$now = current_time( 'mysql' );
$wpdb->insert( $legacy_datasets, array( 'id' => 77, 'name' => 'Legacy categorical', 'description' => 'Must remain untouched', 'data_type' => 'bar', 'created_at' => $now, 'updated_at' => $now ) );
$wpdb->insert( $legacy_datasets, array( 'id' => 78, 'name' => 'Legacy empty', 'description' => 'Retry-safe empty dataset', 'data_type' => 'bar', 'created_at' => $now, 'updated_at' => $now ) );
$wpdb->insert( $legacy_points, array( 'dataset_id' => 77, 'point_key' => 'legacy-a', 'label' => 'Legacy A', 'value' => 42, 'color' => '#123456', 'meta' => '{}', 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now ) );
delete_option( 'viswiz_v2_legacy_migrated' );
delete_option( 'viswiz_v2_legacy_dataset_map' );
Migrator::install();
$map = get_option( 'viswiz_v2_legacy_dataset_map', array() );
viswiz_smoke_assert( ! empty( $map[77] ), 'Legacy dataset ID was not mapped to a v2 dataset.' );
$migrated_id = (int) $map[77];
viswiz_smoke_assert( Support::table( 'datasets' ) !== $legacy_datasets, 'V2 and legacy dataset tables unexpectedly share the same physical table.' );
viswiz_smoke_assert( 'bar' === (string) $wpdb->get_var( "SELECT data_type FROM {$legacy_datasets} WHERE id=77" ), 'Legacy dataset table was mutated by the v2 migration.' );
viswiz_smoke_assert( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$legacy_points} WHERE dataset_id=77" ), 'Legacy data points were removed during migration.' );
$migrated = $repo->get( $migrated_id );
viswiz_smoke_assert( $migrated && 'categorical' === $migrated['schema_type'], 'Legacy renderer type was not converted to a v2 schema.' );
viswiz_smoke_assert( 42.0 === (float) $repo->get_rows( $migrated_id )[0]['value'], 'Legacy row payload was not copied correctly.' );
viswiz_smoke_assert( ! empty( $map[78] ), 'Empty legacy dataset ID was not mapped to a v2 dataset.' );
$empty_migrated_id = (int) $map[78];
$empty_dataset = $repo->get( $empty_migrated_id );
viswiz_smoke_assert( $empty_dataset && 2 === (int) $empty_dataset['revision'], 'Empty legacy dataset was not marked as payload-migrated.' );
delete_option( 'viswiz_v2_legacy_migrated' );
Migrator::install();
$retry_map = get_option( 'viswiz_v2_legacy_dataset_map', array() );
viswiz_smoke_assert( (int) ( $retry_map[78] ?? 0 ) === $empty_migrated_id, 'Legacy migration retry changed the mapped empty dataset ID.' );
$empty_after_retry = $repo->get( $empty_migrated_id );
viswiz_smoke_assert( $empty_after_retry && 2 === (int) $empty_after_retry['revision'], 'Legacy migration retry rewrote an already migrated empty dataset.' );
$repo->delete_with_usage_cleanup( $migrated_id );
$repo->delete_with_usage_cleanup( $empty_migrated_id );

$wpdb->query( "DROP TABLE IF EXISTS {$legacy_relations}" );
$wpdb->query( "DROP TABLE IF EXISTS {$legacy_points}" );
$wpdb->query( "DROP TABLE IF EXISTS {$legacy_datasets}" );

echo "VisWiz WordPress smoke tests passed.\n";
