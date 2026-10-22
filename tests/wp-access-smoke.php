<?php
// Execute in an installed WordPress site: wp eval-file tests/wp-access-smoke.php
use VisWiz\Access;
use VisWiz\Database\DatasetRepository;
use VisWiz\Rest\Api;
use VisWiz\Rest\DatasetEditorApi;

function viswiz_access_assert( bool $ok, string $message ): void {
    if ( ! $ok ) {
        throw new RuntimeException( 'Access control: ' . $message );
    }
}

require_once ABSPATH . 'wp-admin/includes/user.php';
global $wpdb;
$administrator = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0];
$original_user = get_current_user_id();
$ids = array();
foreach ( array( 'author', 'contributor', 'editor', 'subscriber' ) as $role ) {
    $uid = wp_create_user( 'vw-access-' . $role . '-' . wp_generate_password( 8, false ), wp_generate_password(), 'vw-access-' . $role . '-' . wp_generate_password( 5, false ) . '@example.invalid' );
    viswiz_access_assert( ! is_wp_error( $uid ), 'Could not create ' . $role );
    ( new WP_User( $uid ) )->set_role( $role );
    $ids[ $role ] = (int) $uid;
}
$repo = new DatasetRepository();
$dataset_id = 0;
$post_id = 0;
try {
    wp_set_current_user( $ids['author'] );
    viswiz_access_assert( current_user_can( 'edit_viswiz_datasets' ), 'Author lacks dataset creation right' );
    viswiz_access_assert( current_user_can( 'edit_viswiz_visualizations' ), 'Author lacks visualization creation right' );
    $dataset_id = $repo->create( array( 'name' => 'ACL test', 'schema_type' => 'categorical' ) );
    viswiz_access_assert( ! is_wp_error( $dataset_id ), 'Could not create dataset' );
    $dataset_id = (int) $dataset_id;
    viswiz_access_assert( (int) $repo->get( $dataset_id )['created_by'] === $ids['author'], 'Incorrect dataset creator' );
    viswiz_access_assert( (int) $repo->get( $dataset_id )['owner_id'] === $ids['author'], 'Incorrect dataset owner' );
    $post_id = wp_insert_post( array(
        'post_type' => 'viswiz_visualization', 'post_status' => 'draft',
        'post_title' => 'ACL test', 'post_author' => $ids['author'],
    ), true );
    viswiz_access_assert( ! is_wp_error( $post_id ), 'Could not create visualization' );
    $post_id = (int) $post_id;
    viswiz_access_assert( current_user_can( 'edit_post', $post_id ), 'Creator cannot edit visualization' );

    wp_set_current_user( $ids['contributor'] );
    viswiz_access_assert( current_user_can( 'edit_viswiz_datasets' ), 'Contributor lacks dataset creation right' );
    viswiz_access_assert( current_user_can( 'edit_viswiz_visualizations' ), 'Contributor lacks visualization creation right' );
    viswiz_access_assert( ! Access::can_edit_dataset( $dataset_id ), 'Unassigned contributor can edit dataset' );
    viswiz_access_assert( ! current_user_can( 'edit_post', $post_id ), 'Unassigned contributor can edit visualization' );
    $rest = new WP_REST_Request( 'GET', '/viswiz/v2/datasets/' . $dataset_id );
    $rest->set_param( 'id', $dataset_id );
    viswiz_access_assert( ! Api::can_edit_datasets( $rest ), 'Dataset REST permission bypass' );

    $wpdb->insert( \VisWiz\Support::table( 'dataset_editors' ), array( 'dataset_id' => $dataset_id, 'user_id' => $ids['contributor'] ), array( '%d', '%d' ) );
    add_post_meta( $post_id, '_viswiz_editor_access', $ids['contributor'] );
    viswiz_access_assert( Access::can_edit_dataset( $dataset_id ), 'Dataset ACL editor cannot edit' );
    viswiz_access_assert( current_user_can( 'edit_post', $post_id ), 'Visualization ACL editor cannot edit' );
    viswiz_access_assert( ! Access::can_manage_dataset( $dataset_id ), 'ACL editor can alter dataset sharing' );
    viswiz_access_assert( ! Access::can_manage_visualization( $post_id ), 'ACL editor can alter visualization sharing' );
    viswiz_access_assert( DatasetEditorApi::can_edit_datasets( $rest ), 'Dataset collection denied to ACL editor' );
    $rest = new WP_REST_Request( 'POST', '/viswiz/v2/datasets/' . $dataset_id . '/editor/rows/batch' );
    $rest->set_param( 'id', $dataset_id );
    viswiz_access_assert( Access::can_edit_dataset( $dataset_id ), 'Batch edit intended access missing' );

    $wpdb->delete( \VisWiz\Support::table( 'dataset_editors' ), array( 'dataset_id' => $dataset_id, 'user_id' => $ids['contributor'] ) );
    delete_post_meta( $post_id, '_viswiz_editor_access', $ids['contributor'] );
    viswiz_access_assert( ! Access::can_edit_dataset( $dataset_id ), 'Revoked dataset editor still has access' );
    viswiz_access_assert( ! current_user_can( 'edit_post', $post_id ), 'Revoked visualization editor still has access' );

    wp_set_current_user( $ids['editor'] );
    viswiz_access_assert( Access::can_manage_dataset( $dataset_id ), 'WordPress Editor cannot manage dataset' );
    viswiz_access_assert( Access::can_manage_visualization( $post_id ), 'WordPress Editor cannot manage visualization' );
    $wpdb->update( \VisWiz\Support::table( 'datasets' ), array( 'owner_id' => $ids['contributor'] ), array( 'id' => $dataset_id ) );
    wp_update_post( array( 'ID' => $post_id, 'post_author' => $ids['contributor'] ) );

    wp_set_current_user( $ids['author'] );
    viswiz_access_assert( Access::can_edit_dataset( $dataset_id ), 'Original dataset creator lost access after transfer' );
    viswiz_access_assert( current_user_can( 'edit_post', $post_id ), 'Original visualization creator lost access after transfer' );
    viswiz_access_assert( Access::can_manage_dataset( $dataset_id ), 'Original creator cannot share dataset' );
    viswiz_access_assert( Access::can_manage_visualization( $post_id ), 'Original creator cannot share visualization' );
    wp_set_current_user( $ids['contributor'] );
    viswiz_access_assert( Access::can_edit_dataset( $dataset_id ), 'New dataset owner lacks access' );
    viswiz_access_assert( current_user_can( 'edit_post', $post_id ), 'New visualization owner lacks access' );

    wp_set_current_user( $ids['subscriber'] );
    viswiz_access_assert( ! current_user_can( 'edit_viswiz_visualizations' ), 'Subscriber can create visualization' );
    viswiz_access_assert( ! Access::can_edit_dataset( $dataset_id ), 'Subscriber can edit dataset' );
    viswiz_access_assert( ! current_user_can( 'edit_post', $post_id ), 'Subscriber can edit visualization' );

    wp_set_current_user( $ids['author'] );
    $list = $repo->list_with_counts( array( 'access_user_id' => $ids['author'] ) );
    viswiz_access_assert( in_array( $dataset_id, array_map( 'intval', array_column( $list, 'id' ) ), true ), 'Dataset missing from creator list' );
    $list = $repo->list_with_counts( array( 'access_user_id' => $ids['subscriber'] ) );
    viswiz_access_assert( ! in_array( $dataset_id, array_map( 'intval', array_column( $list, 'id' ) ), true ), 'Dataset visible to subscriber' );

    echo "VisWiz access control smoke: PASS\n";
} finally {
    wp_set_current_user( $administrator->ID );
    if ( $post_id ) {
        wp_delete_post( $post_id, true );
    }
    if ( $dataset_id ) {
        $repo->delete_with_usage_cleanup( $dataset_id );
    }
    foreach ( $ids as $uid ) {
        wp_delete_user( $uid );
    }
    wp_set_current_user( $original_user );
}
