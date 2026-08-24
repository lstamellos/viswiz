<?php
/**
 * Keep dataset-backed graph tables in sync after lightweight node type autosaves.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_viswiz_autosave_node_type', 'viswiz_dataset_autosave_sync_prepare', 1 );

function viswiz_dataset_autosave_sync_prepare() {
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    add_action(
        'shutdown',
        function () use ( $post_id ) {
            $dataset_id = (int) get_post_meta( $post_id, 'viswiz_dataset_id', true );
            if ( ! $dataset_id ) {
                return;
            }

            $graph_data = json_decode( get_post_meta( $post_id, 'viswiz_graph_data', true ) ?: '[]', true );
            if ( ! is_array( $graph_data ) ) {
                return;
            }

            viswiz_sync_graph_tables_from_saved_meta( $post_id, $graph_data );
            if ( function_exists( 'viswiz_dataset_manager_touch_dataset' ) ) {
                viswiz_dataset_manager_touch_dataset( $dataset_id );
            }
        }
    );
}
