<?php
/**
 * Dataset-backed editor request hydration and lightweight autosave synchronization.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * The dataset manager initially registered a hydration callback that copied the
 * canonical dataset into visualization post meta while merely opening the edit
 * screen. Replace it with request-only metadata overrides: editors see the
 * canonical dataset immediately, but persistent state changes only on Save.
 */
remove_action( 'add_meta_boxes_viswiz_visualization', 'viswiz_dataset_manager_hydrate_visualization_editor', 1 );
add_action( 'add_meta_boxes_viswiz_visualization', 'viswiz_dataset_editor_prepare_meta_overrides', 1, 1 );
add_action( 'wp_ajax_viswiz_autosave_node_type', 'viswiz_dataset_autosave_sync_prepare', 1 );

function viswiz_dataset_editor_prepare_meta_overrides( $post ) {
    if ( ! $post instanceof WP_Post || 'viswiz_visualization' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
        return;
    }

    $saved_dataset_id   = (int) get_post_meta( $post->ID, 'viswiz_dataset_id', true );
    $prefill_dataset_id = isset( $_GET['viswiz_dataset_id'] ) ? absint( $_GET['viswiz_dataset_id'] ) : 0;
    $dataset_id         = $saved_dataset_id ?: $prefill_dataset_id;

    if ( ! $dataset_id ) {
        return;
    }

    $dataset = viswiz_dataset_manager_get_dataset( $dataset_id );
    if ( ! $dataset ) {
        if ( $saved_dataset_id ) {
            $GLOBALS['viswiz_dataset_editor_meta_overrides'][ $post->ID ] = array(
                'viswiz_dataset_id' => 0,
            );
            viswiz_dataset_editor_enable_meta_override_filter();
        }
        return;
    }

    $current_type   = sanitize_key( get_post_meta( $post->ID, 'viswiz_type', true ) );
    $effective_type = viswiz_dataset_manager_effective_type( $dataset, $current_type );
    $payload        = viswiz_dataset_manager_get_payload( $dataset_id, $effective_type );

    if ( null === $payload ) {
        return;
    }

    $overrides = array(
        'viswiz_dataset_id' => $dataset_id,
        'viswiz_type'       => $effective_type,
    );

    if ( viswiz_is_graph_like_type( $effective_type ) ) {
        $overrides['viswiz_graph_data'] = viswiz_json_encode( $payload );
    } elseif ( 'progress' === $effective_type ) {
        $overrides['viswiz_manual_progress'] = viswiz_json_encode( $payload );
        $overrides['viswiz_source']          = 'manual';
    } elseif ( viswiz_is_chart_like_type( $effective_type ) ) {
        $overrides['viswiz_manual_pie'] = viswiz_json_encode( $payload );
        $overrides['viswiz_source']      = 'manual';
    }

    $GLOBALS['viswiz_dataset_editor_meta_overrides'][ $post->ID ] = $overrides;
    viswiz_dataset_editor_enable_meta_override_filter();
}

function viswiz_dataset_editor_enable_meta_override_filter() {
    if ( ! has_filter( 'get_post_metadata', 'viswiz_dataset_editor_filter_post_metadata' ) ) {
        add_filter( 'get_post_metadata', 'viswiz_dataset_editor_filter_post_metadata', 10, 5 );
    }
}

function viswiz_dataset_editor_filter_post_metadata( $value, $object_id, $meta_key, $single, $meta_type = 'post' ) {
    if ( 'post' !== $meta_type || '' === $meta_key ) {
        return $value;
    }

    $object_id = absint( $object_id );
    $overrides = $GLOBALS['viswiz_dataset_editor_meta_overrides'][ $object_id ] ?? array();
    if ( ! is_array( $overrides ) || ! array_key_exists( $meta_key, $overrides ) ) {
        return $value;
    }

    $resolved = $overrides[ $meta_key ];
    return $single ? $resolved : array( $resolved );
}

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
