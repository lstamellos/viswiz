<?php
/**
 * Dataset management UX and dataset/editor synchronization for VisWiz.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'admin_menu', 'viswiz_dataset_manager_replace_datasets_page', 120 );
add_action( 'admin_enqueue_scripts', 'viswiz_dataset_manager_enqueue_assets', 30 );
add_action( 'admin_post_viswiz_update_dataset_details', 'viswiz_dataset_manager_update_details' );
add_action( 'admin_post_viswiz_duplicate_dataset', 'viswiz_dataset_manager_duplicate_dataset' );
add_action( 'add_meta_boxes_viswiz_visualization', 'viswiz_dataset_manager_hydrate_visualization_editor', 1, 1 );
add_action( 'save_post_viswiz_visualization', 'viswiz_dataset_manager_guard_dataset_switch', 1 );
add_action( 'save_post_viswiz_visualization', 'viswiz_dataset_manager_touch_linked_dataset', 30 );
add_action( 'wp_ajax_viswiz_autosave_graph_node', 'viswiz_dataset_manager_prepare_ajax_touch', 1 );
add_action( 'plugins_loaded', 'viswiz_dataset_manager_replace_delete_handler', 100 );

function viswiz_dataset_manager_replace_datasets_page() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }

    remove_submenu_page( 'viswiz', 'viswiz-datasets' );
    add_submenu_page(
        'viswiz',
        __( 'Datasets', 'viswiz' ),
        __( 'Datasets', 'viswiz' ),
        'edit_posts',
        'viswiz-datasets',
        'viswiz_dataset_manager_render_page'
    );
}

function viswiz_dataset_manager_enqueue_assets( $hook ) {
    $screen = get_current_screen();
    if ( ! $screen || 'viswiz_page_viswiz-datasets' !== $screen->id ) {
        return;
    }

    wp_enqueue_style(
        'viswiz-dataset-manager',
        plugins_url( '../assets/viswiz-dataset-manager.css', __FILE__ ),
        array( 'viswiz-admin-style' ),
        defined( 'VISWIZ_VERSION' ) ? VISWIZ_VERSION : '1.0.0'
    );
    wp_enqueue_script(
        'viswiz-dataset-manager',
        plugins_url( '../assets/viswiz-dataset-manager.js', __FILE__ ),
        array(),
        defined( 'VISWIZ_VERSION' ) ? VISWIZ_VERSION : '1.0.0',
        true
    );
}

function viswiz_dataset_manager_get_dataset( $dataset_id ) {
    global $wpdb;
    $dataset_id = absint( $dataset_id );
    if ( ! $dataset_id ) {
        return null;
    }

    return $wpdb->get_row(
        $wpdb->prepare(
            'SELECT * FROM ' . viswiz_get_table_name( 'datasets' ) . ' WHERE id = %d',
            $dataset_id
        )
    );
}

function viswiz_dataset_manager_get_usage( $dataset_id ) {
    global $wpdb;
    $dataset_id = absint( $dataset_id );
    if ( ! $dataset_id ) {
        return array();
    }

    $visualizations_table = viswiz_get_table_name( 'visualization_data' );
    return $wpdb->get_results(
        $wpdb->prepare(
            "SELECT v.post_id, v.visualization_type, p.post_title, p.post_status, p.post_modified
             FROM $visualizations_table v
             LEFT JOIN {$wpdb->posts} p ON p.ID = v.post_id
             WHERE v.dataset_id = %d
             ORDER BY p.post_modified DESC, v.post_id DESC",
            $dataset_id
        )
    );
}

function viswiz_dataset_manager_dataset_has_relations( $dataset_id ) {
    global $wpdb;
    return (bool) $wpdb->get_var(
        $wpdb->prepare(
            'SELECT id FROM ' . viswiz_get_table_name( 'relations' ) . ' WHERE dataset_id = %d LIMIT 1',
            absint( $dataset_id )
        )
    );
}

function viswiz_dataset_manager_effective_type( $dataset, $current_type = '' ) {
    $current_type = sanitize_key( $current_type );
    $dataset_type = sanitize_key( $dataset->data_type ?? 'generic' );

    if ( 'generic' === $dataset_type && viswiz_dataset_manager_dataset_has_relations( $dataset->id ?? 0 ) ) {
        $dataset_type = 'graph';
    }
    if ( 'generic' === $dataset_type || '' === $dataset_type ) {
        return $current_type ?: 'progress';
    }
    if ( '' === $current_type ) {
        return $dataset_type;
    }

    if ( viswiz_is_graph_like_type( $dataset_type ) && viswiz_is_graph_like_type( $current_type ) ) {
        return $current_type;
    }
    if ( viswiz_is_chart_like_type( $dataset_type ) && viswiz_is_chart_like_type( $current_type ) ) {
        return $current_type;
    }
    if ( $dataset_type === $current_type ) {
        return $current_type;
    }

    return $dataset_type;
}

function viswiz_dataset_manager_get_payload( $dataset_id, $type ) {
    global $wpdb;
    $dataset_id = absint( $dataset_id );
    if ( ! $dataset_id ) {
        return null;
    }

    $points = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . viswiz_get_table_name( 'data_points' ) . ' WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC',
            $dataset_id
        ),
        ARRAY_A
    );
    $relations = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT * FROM ' . viswiz_get_table_name( 'relations' ) . ' WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC',
            $dataset_id
        ),
        ARRAY_A
    );

    if ( viswiz_is_graph_like_type( $type ) ) {
        return array(
            'nodes' => array_map(
                function ( $point ) {
                    $meta = json_decode( $point['meta'] ?? '[]', true );
                    $node = is_array( $meta ) ? $meta : array();
                    $node['id'] = $point['point_key'];
                    $node['label'] = $node['label'] ?? $point['label'];
                    $node['title'] = $node['title'] ?? $point['label'];
                    return $node;
                },
                $points
            ),
            'links' => array_map(
                function ( $relation ) {
                    $meta = json_decode( $relation['meta'] ?? '[]', true );
                    $link = is_array( $meta ) ? $meta : array();
                    $link['from'] = $relation['from_key'];
                    $link['to'] = $relation['to_key'];
                    $link['label'] = $link['label'] ?? $relation['label'];
                    $link['direction'] = $link['direction'] ?? $relation['direction'];
                    $link['intensity'] = isset( $link['intensity'] ) ? (float) $link['intensity'] : (float) $relation['intensity'];
                    $link['relation_type'] = $link['relation_type'] ?? $relation['relation_type'];
                    return $link;
                },
                $relations
            ),
        );
    }

    return array_map(
        function ( $point ) {
            $meta = json_decode( $point['meta'] ?? '[]', true );
            $row = is_array( $meta ) ? $meta : array();
            $row['label'] = $row['label'] ?? $point['label'];
            $row['value'] = isset( $row['value'] ) ? (float) $row['value'] : (float) $point['value'];
            $row['color'] = $row['color'] ?? $point['color'];
            return $row;
        },
        $points
    );
}

function viswiz_dataset_manager_hydrate_visualization_editor( $post ) {
    if ( ! $post instanceof WP_Post || 'viswiz_visualization' !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
        return;
    }

    $saved_dataset_id = (int) get_post_meta( $post->ID, 'viswiz_dataset_id', true );
    $prefill_dataset_id = isset( $_GET['viswiz_dataset_id'] ) ? absint( $_GET['viswiz_dataset_id'] ) : 0;
    $dataset_id = $saved_dataset_id ?: $prefill_dataset_id;
    if ( ! $dataset_id ) {
        return;
    }

    $dataset = viswiz_dataset_manager_get_dataset( $dataset_id );
    if ( ! $dataset ) {
        if ( $saved_dataset_id ) {
            update_post_meta( $post->ID, 'viswiz_dataset_id', 0 );
        }
        return;
    }

    if ( ! $saved_dataset_id && $prefill_dataset_id ) {
        update_post_meta( $post->ID, 'viswiz_dataset_id', $dataset_id );
    }

    $current_type = sanitize_key( get_post_meta( $post->ID, 'viswiz_type', true ) );
    $effective_type = viswiz_dataset_manager_effective_type( $dataset, $current_type );
    if ( $effective_type && $effective_type !== $current_type ) {
        update_post_meta( $post->ID, 'viswiz_type', $effective_type );
    }

    $payload = viswiz_dataset_manager_get_payload( $dataset_id, $effective_type );
    if ( null === $payload ) {
        return;
    }

    if ( viswiz_is_graph_like_type( $effective_type ) ) {
        update_post_meta( $post->ID, 'viswiz_graph_data', viswiz_json_encode( $payload ) );
    } elseif ( 'progress' === $effective_type ) {
        update_post_meta( $post->ID, 'viswiz_manual_progress', viswiz_json_encode( $payload ) );
        update_post_meta( $post->ID, 'viswiz_source', 'manual' );
    } elseif ( viswiz_is_chart_like_type( $effective_type ) ) {
        update_post_meta( $post->ID, 'viswiz_manual_pie', viswiz_json_encode( $payload ) );
        update_post_meta( $post->ID, 'viswiz_source', 'manual' );
    }
}

function viswiz_dataset_manager_guard_dataset_switch( $post_id ) {
    if ( ! isset( $_POST['viswiz_meta'] ) || ! is_array( $_POST['viswiz_meta'] ) ) {
        return;
    }
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    $submitted = wp_unslash( $_POST['viswiz_meta'] );
    $new_dataset_id = absint( $submitted['dataset_id'] ?? 0 );
    $old_dataset_id = (int) get_post_meta( $post_id, 'viswiz_dataset_id', true );
    if ( ! $new_dataset_id || $new_dataset_id === $old_dataset_id ) {
        return;
    }

    $dataset = viswiz_dataset_manager_get_dataset( $new_dataset_id );
    if ( ! $dataset ) {
        $_POST['viswiz_meta']['dataset_id'] = '0';
        return;
    }

    $submitted_type = sanitize_key( $submitted['type'] ?? '' );
    $effective_type = viswiz_dataset_manager_effective_type( $dataset, $submitted_type );
    $payload = viswiz_dataset_manager_get_payload( $new_dataset_id, $effective_type );
    if ( null === $payload ) {
        return;
    }

    $_POST['viswiz_meta']['type'] = $effective_type;
    if ( viswiz_is_graph_like_type( $effective_type ) ) {
        $_POST['viswiz_meta']['graph_data'] = wp_slash( $payload );
    } elseif ( 'progress' === $effective_type ) {
        $_POST['viswiz_meta']['manual_progress'] = wp_slash( $payload );
        $_POST['viswiz_meta']['source'] = 'manual';
    } elseif ( viswiz_is_chart_like_type( $effective_type ) ) {
        $_POST['viswiz_meta']['manual_pie'] = wp_slash( $payload );
        $_POST['viswiz_meta']['source'] = 'manual';
    }
}

function viswiz_dataset_manager_touch_dataset( $dataset_id ) {
    global $wpdb;
    $dataset_id = absint( $dataset_id );
    if ( ! $dataset_id ) {
        return;
    }
    $wpdb->update(
        viswiz_get_table_name( 'datasets' ),
        array( 'updated_at' => current_time( 'mysql' ) ),
        array( 'id' => $dataset_id ),
        array( '%s' ),
        array( '%d' )
    );
}

function viswiz_dataset_manager_touch_linked_dataset( $post_id ) {
    viswiz_dataset_manager_touch_dataset( (int) get_post_meta( $post_id, 'viswiz_dataset_id', true ) );
}

function viswiz_dataset_manager_prepare_ajax_touch() {
    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id ) {
        return;
    }
    add_action(
        'shutdown',
        function () use ( $post_id ) {
            viswiz_dataset_manager_touch_dataset( (int) get_post_meta( $post_id, 'viswiz_dataset_id', true ) );
        }
    );
}

function viswiz_dataset_manager_validate_graph( $payload ) {
    $issues = array();
    $nodes = is_array( $payload['nodes'] ?? null ) ? $payload['nodes'] : array();
    $links = is_array( $payload['links'] ?? null ) ? $payload['links'] : array();
    $seen = array();

    foreach ( $nodes as $index => $node ) {
        $id = sanitize_key( $node['id'] ?? '' );
        $title = trim( (string) ( $node['title'] ?? ( $node['label'] ?? '' ) ) );
        $node_type = sanitize_key( $node['node_type'] ?? ( $node['entity_type'] ?? '' ) );
        if ( '' === $id ) {
            $issues[] = sprintf( __( 'Node %d has no ID.', 'viswiz' ), $index + 1 );
            continue;
        }
        if ( isset( $seen[ $id ] ) ) {
            $issues[] = sprintf( __( 'Duplicate node ID: %s.', 'viswiz' ), $id );
        }
        $seen[ $id ] = true;
        if ( '' === $title ) {
            $issues[] = sprintf( __( 'Node %s has no title.', 'viswiz' ), $id );
        }
        if ( '' === $node_type ) {
            $issues[] = sprintf( __( 'Node %s has no node type.', 'viswiz' ), $id );
        }
    }

    foreach ( $links as $index => $link ) {
        $from = sanitize_key( $link['from'] ?? '' );
        $to = sanitize_key( $link['to'] ?? '' );
        if ( '' === $from || '' === $to ) {
            $issues[] = sprintf( __( 'Relation %d has a missing endpoint.', 'viswiz' ), $index + 1 );
            continue;
        }
        if ( ! isset( $seen[ $from ] ) ) {
            $issues[] = sprintf( __( 'Relation %d points from missing node %s.', 'viswiz' ), $index + 1, $from );
        }
        if ( ! isset( $seen[ $to ] ) ) {
            $issues[] = sprintf( __( 'Relation %d points to missing node %s.', 'viswiz' ), $index + 1, $to );
        }
    }

    return array_values( array_unique( $issues ) );
}

function viswiz_dataset_manager_get_degree_map( $payload ) {
    $degree = array();
    foreach ( $payload['nodes'] ?? array() as $node ) {
        $id = sanitize_key( $node['id'] ?? '' );
        if ( $id ) {
            $degree[ $id ] = 0;
        }
    }
    foreach ( $payload['links'] ?? array() as $link ) {
        $from = sanitize_key( $link['from'] ?? '' );
        $to = sanitize_key( $link['to'] ?? '' );
        if ( isset( $degree[ $from ] ) ) {
            $degree[ $from ]++;
        }
        if ( isset( $degree[ $to ] ) ) {
            $degree[ $to ]++;
        }
    }
    return $degree;
}

function viswiz_dataset_manager_get_editor_url( $dataset, $usage = array() ) {
    $dataset_id = absint( $dataset->id ?? 0 );
    $dataset_type = sanitize_key( $dataset->data_type ?? 'generic' );
    $graph_like = viswiz_is_graph_like_type( $dataset_type ) || viswiz_dataset_manager_dataset_has_relations( $dataset_id );
    $tab = $graph_like ? 'nodes' : 'data';

    foreach ( $usage as $item ) {
        $post_id = absint( $item->post_id ?? 0 );
        if ( $post_id && get_post( $post_id ) && current_user_can( 'edit_post', $post_id ) ) {
            return add_query_arg( 'viswiz_tab', $tab, get_edit_post_link( $post_id, 'raw' ) );
        }
    }

    return add_query_arg(
        array(
            'post_type' => 'viswiz_visualization',
            'viswiz_dataset_id' => $dataset_id,
            'viswiz_tab' => $tab,
        ),
        admin_url( 'post-new.php' )
    );
}

function viswiz_dataset_manager_render_page() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage VisWiz datasets.', 'viswiz' ) );
    }

    $selected_id = isset( $_GET['dataset_id'] ) ? absint( $_GET['dataset_id'] ) : 0;
    if ( $selected_id ) {
        viswiz_dataset_manager_render_detail( $selected_id );
        return;
    }

    $datasets = viswiz_get_admin_dataset_stats();
    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $type_filter = isset( $_GET['data_type'] ) ? sanitize_key( wp_unslash( $_GET['data_type'] ) ) : '';
    if ( $search || $type_filter ) {
        $datasets = array_values(
            array_filter(
                $datasets,
                function ( $dataset ) use ( $search, $type_filter ) {
                    if ( $type_filter && $type_filter !== sanitize_key( $dataset->data_type ) ) {
                        return false;
                    }
                    if ( $search ) {
                        $haystack = strtolower( $dataset->name . ' ' . $dataset->description . ' ' . $dataset->data_type );
                        if ( false === strpos( $haystack, strtolower( $search ) ) ) {
                            return false;
                        }
                    }
                    return true;
                }
            )
        );
    }
    $types = viswiz_get_supported_visualization_types();
    ?>
    <div class="wrap viswiz-admin-page viswiz-datasets-page viswiz-dataset-manager-page">
        <div class="viswiz-dataset-heading">
            <div>
                <h1><?php esc_html_e( 'Datasets', 'viswiz' ); ?></h1>
                <p class="viswiz-page-intro"><?php esc_html_e( 'Reusable datasets are canonical data sources. Inspect their contents and usage here; edit node graphs through the builder without maintaining a separate copy of the graph.', 'viswiz' ); ?></p>
            </div>
            <a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=viswiz_visualization' ) ); ?>"><?php esc_html_e( 'New visualization', 'viswiz' ); ?></a>
        </div>
        <?php if ( isset( $_GET['created'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dataset created.', 'viswiz' ); ?></p></div><?php endif; ?>
        <?php if ( isset( $_GET['deleted'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dataset deleted.', 'viswiz' ); ?></p></div><?php endif; ?>
        <form method="get" class="viswiz-dataset-filters">
            <input type="hidden" name="page" value="viswiz-datasets" />
            <label><span><?php esc_html_e( 'Search', 'viswiz' ); ?></span><input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php echo esc_attr__( 'Name, description or type', 'viswiz' ); ?>" /></label>
            <label><span><?php esc_html_e( 'Type', 'viswiz' ); ?></span><select name="data_type"><option value=""><?php esc_html_e( 'All types', 'viswiz' ); ?></option><?php foreach ( $types as $type_key => $type_label ) : ?><option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type_filter, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option><?php endforeach; ?><option value="generic" <?php selected( $type_filter, 'generic' ); ?>><?php esc_html_e( 'Generic', 'viswiz' ); ?></option></select></label>
            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'viswiz' ); ?></button>
            <?php if ( $search || $type_filter ) : ?><a class="button button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>"><?php esc_html_e( 'Clear', 'viswiz' ); ?></a><?php endif; ?>
        </form>
        <div class="viswiz-admin-two-column">
            <div class="viswiz-admin-panel">
                <h2><?php printf( esc_html__( 'Existing datasets (%d)', 'viswiz' ), count( $datasets ) ); ?></h2>
                <table class="widefat striped viswiz-dataset-table">
                    <thead><tr><th><?php esc_html_e( 'Dataset', 'viswiz' ); ?></th><th><?php esc_html_e( 'Content', 'viswiz' ); ?></th><th><?php esc_html_e( 'Used by', 'viswiz' ); ?></th><th><?php esc_html_e( 'Updated', 'viswiz' ); ?></th><th><?php esc_html_e( 'Actions', 'viswiz' ); ?></th></tr></thead>
                    <tbody>
                    <?php if ( empty( $datasets ) ) : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No datasets match these filters.', 'viswiz' ); ?></td></tr>
                    <?php else : foreach ( $datasets as $dataset ) :
                        $manage_url = add_query_arg( array( 'page' => 'viswiz-datasets', 'dataset_id' => absint( $dataset->id ) ), admin_url( 'admin.php' ) );
                        $usage = viswiz_dataset_manager_get_usage( $dataset->id );
                        $editor_url = viswiz_dataset_manager_get_editor_url( $dataset, $usage );
                        $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_export_dataset&dataset_id=' . absint( $dataset->id ) ), 'viswiz_export_dataset_' . absint( $dataset->id ) );
                        $duplicate_url = wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_duplicate_dataset&dataset_id=' . absint( $dataset->id ) ), 'viswiz_duplicate_dataset_' . absint( $dataset->id ) );
                        $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_delete_dataset&dataset_id=' . absint( $dataset->id ) ), 'viswiz_delete_dataset_' . absint( $dataset->id ) );
                        $graph_like = viswiz_is_graph_like_type( $dataset->data_type ) || (int) $dataset->relation_count > 0;
                    ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $manage_url ); ?>"><strong><?php echo esc_html( $dataset->name ); ?></strong></a><br /><span class="description">#<?php echo esc_html( $dataset->id ); ?> · <?php echo esc_html( $types[ $dataset->data_type ] ?? ucfirst( str_replace( '_', ' ', $dataset->data_type ) ) ); ?></span><?php if ( $dataset->description ) : ?><br /><span class="description"><?php echo esc_html( wp_trim_words( $dataset->description, 16 ) ); ?></span><?php endif; ?></td>
                            <td><strong><?php echo esc_html( (int) $dataset->point_count ); ?></strong> <?php echo $graph_like ? esc_html__( 'nodes', 'viswiz' ) : esc_html__( 'points', 'viswiz' ); ?><br /><span class="description"><?php printf( esc_html__( '%d relations', 'viswiz' ), (int) $dataset->relation_count ); ?></span></td>
                            <td><?php echo esc_html( (int) $dataset->visualization_count ); ?> <?php esc_html_e( 'visualizations', 'viswiz' ); ?></td>
                            <td><?php echo esc_html( $dataset->updated_at ); ?></td>
                            <td class="viswiz-dataset-row-actions"><a class="button button-small button-primary" href="<?php echo esc_url( $manage_url ); ?>"><?php esc_html_e( 'Manage', 'viswiz' ); ?></a><a class="button button-small" href="<?php echo esc_url( $editor_url ); ?>"><?php echo $graph_like ? esc_html__( 'Edit graph', 'viswiz' ) : esc_html__( 'Edit data', 'viswiz' ); ?></a><a class="button button-small" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export', 'viswiz' ); ?></a><a class="button button-small" href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'viswiz' ); ?></a><a class="button button-small button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this dataset and its stored points/relations? Linked visualizations will keep their visualization-specific fallback data.', 'viswiz' ) ); ?>');"><?php esc_html_e( 'Delete', 'viswiz' ); ?></a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="viswiz-admin-panel viswiz-admin-side-panel">
                <h2><?php esc_html_e( 'Create dataset', 'viswiz' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="viswiz_create_dataset" />
                    <?php wp_nonce_field( 'viswiz_create_dataset' ); ?>
                    <p><label for="viswiz_dataset_name"><?php esc_html_e( 'Name', 'viswiz' ); ?></label><input type="text" id="viswiz_dataset_name" name="dataset_name" class="regular-text" required /></p>
                    <p><label for="viswiz_dataset_type"><?php esc_html_e( 'Data type', 'viswiz' ); ?></label><select id="viswiz_dataset_type" name="dataset_type"><?php foreach ( $types as $type_key => $type_label ) : ?><option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option><?php endforeach; ?><option value="generic"><?php esc_html_e( 'Generic', 'viswiz' ); ?></option></select></p>
                    <p><label for="viswiz_dataset_description"><?php esc_html_e( 'Description', 'viswiz' ); ?></label><textarea id="viswiz_dataset_description" name="dataset_description" class="large-text" rows="4"></textarea></p>
                    <?php submit_button( __( 'Create dataset', 'viswiz' ), 'primary', 'submit', false ); ?>
                </form>
                <hr />
                <h2><?php esc_html_e( 'Graph workflow', 'viswiz' ); ?></h2>
                <ol><li><?php esc_html_e( 'Create the graph dataset once.', 'viswiz' ); ?></li><li><?php esc_html_e( 'Open it with Edit graph; VisWiz loads the canonical dataset nodes and relations into the builder.', 'viswiz' ); ?></li><li><?php esc_html_e( 'Use Manage to inspect validation, usage, node IDs, types and relation endpoints.', 'viswiz' ); ?></li><li><?php esc_html_e( 'Duplicate or export before a major structural revision.', 'viswiz' ); ?></li></ol>
            </div>
        </div>
    </div>
    <?php
}

function viswiz_dataset_manager_render_detail( $dataset_id ) {
    $dataset = viswiz_dataset_manager_get_dataset( $dataset_id );
    if ( ! $dataset ) {
        wp_die( esc_html__( 'Dataset not found.', 'viswiz' ) );
    }

    $usage = viswiz_dataset_manager_get_usage( $dataset_id );
    $graph_like = viswiz_is_graph_like_type( $dataset->data_type ) || viswiz_dataset_manager_dataset_has_relations( $dataset_id );
    $effective_type = $graph_like ? ( viswiz_is_graph_like_type( $dataset->data_type ) ? $dataset->data_type : 'graph' ) : $dataset->data_type;
    $payload = viswiz_dataset_manager_get_payload( $dataset_id, $effective_type );
    $nodes = $graph_like && is_array( $payload ) ? ( $payload['nodes'] ?? array() ) : array();
    $links = $graph_like && is_array( $payload ) ? ( $payload['links'] ?? array() ) : array();
    $issues = $graph_like ? viswiz_dataset_manager_validate_graph( $payload ) : array();
    $degree = $graph_like ? viswiz_dataset_manager_get_degree_map( $payload ) : array();
    $editor_url = viswiz_dataset_manager_get_editor_url( $dataset, $usage );
    $new_viz_url = add_query_arg( array( 'post_type' => 'viswiz_visualization', 'viswiz_dataset_id' => $dataset_id, 'viswiz_tab' => $graph_like ? 'nodes' : 'data' ), admin_url( 'post-new.php' ) );
    $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_export_dataset&dataset_id=' . $dataset_id ), 'viswiz_export_dataset_' . $dataset_id );
    $duplicate_url = wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_duplicate_dataset&dataset_id=' . $dataset_id ), 'viswiz_duplicate_dataset_' . $dataset_id );
    ?>
    <div class="wrap viswiz-admin-page viswiz-dataset-manager-page viswiz-dataset-detail-page">
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>">&larr; <?php esc_html_e( 'All datasets', 'viswiz' ); ?></a></p>
        <div class="viswiz-dataset-heading">
            <div><h1><?php echo esc_html( $dataset->name ); ?></h1><p class="viswiz-page-intro"><code>#<?php echo esc_html( $dataset->id ); ?></code> · <?php echo esc_html( ucfirst( str_replace( '_', ' ', $dataset->data_type ) ) ); ?><?php if ( $dataset->description ) : ?> · <?php echo esc_html( $dataset->description ); ?><?php endif; ?></p></div>
            <div class="viswiz-dataset-heading-actions"><a class="button button-primary" href="<?php echo esc_url( $editor_url ); ?>"><?php echo $graph_like ? esc_html__( 'Open graph editor', 'viswiz' ) : esc_html__( 'Open data editor', 'viswiz' ); ?></a><a class="button" href="<?php echo esc_url( $new_viz_url ); ?>"><?php esc_html_e( 'New visualization', 'viswiz' ); ?></a><a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export JSON', 'viswiz' ); ?></a><a class="button" href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'viswiz' ); ?></a></div>
        </div>
        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dataset details updated.', 'viswiz' ); ?></p></div><?php endif; ?>
        <?php if ( isset( $_GET['duplicated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Dataset duplicated. You are viewing the new copy.', 'viswiz' ); ?></p></div><?php endif; ?>
        <div class="viswiz-admin-kpis">
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( $graph_like ? count( $nodes ) : count( (array) $payload ) ); ?></strong><span><?php echo $graph_like ? esc_html__( 'Nodes', 'viswiz' ) : esc_html__( 'Data points', 'viswiz' ); ?></span></div>
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( $graph_like ? count( $links ) : 0 ); ?></strong><span><?php esc_html_e( 'Relations', 'viswiz' ); ?></span></div>
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( count( $usage ) ); ?></strong><span><?php esc_html_e( 'Linked visualizations', 'viswiz' ); ?></span></div>
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( count( $issues ) ); ?></strong><span><?php esc_html_e( 'Validation warnings', 'viswiz' ); ?></span></div>
        </div>
        <div class="viswiz-dataset-detail-grid">
            <section class="viswiz-admin-panel">
                <h2><?php esc_html_e( 'Dataset details', 'viswiz' ); ?></h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="viswiz_update_dataset_details" /><input type="hidden" name="dataset_id" value="<?php echo esc_attr( $dataset_id ); ?>" /><?php wp_nonce_field( 'viswiz_update_dataset_details_' . $dataset_id ); ?>
                    <p><label for="viswiz_dataset_manage_name"><strong><?php esc_html_e( 'Name', 'viswiz' ); ?></strong></label><input type="text" id="viswiz_dataset_manage_name" name="dataset_name" class="large-text" value="<?php echo esc_attr( $dataset->name ); ?>" required /></p>
                    <p><label for="viswiz_dataset_manage_description"><strong><?php esc_html_e( 'Description', 'viswiz' ); ?></strong></label><textarea id="viswiz_dataset_manage_description" name="dataset_description" class="large-text" rows="4"><?php echo esc_textarea( $dataset->description ); ?></textarea></p>
                    <p><strong><?php esc_html_e( 'Data type', 'viswiz' ); ?>:</strong> <code><?php echo esc_html( $dataset->data_type ); ?></code> <span class="description"><?php esc_html_e( 'The type is kept stable once a dataset contains data; change visualization type in the builder when compatible.', 'viswiz' ); ?></span></p>
                    <?php submit_button( __( 'Save dataset details', 'viswiz' ), 'secondary', 'submit', false ); ?>
                </form>
            </section>
            <section class="viswiz-admin-panel">
                <h2><?php esc_html_e( 'Usage', 'viswiz' ); ?></h2>
                <?php if ( empty( $usage ) ) : ?><p><?php esc_html_e( 'This dataset is not linked to a saved visualization yet. Opening the editor will start a new visualization preloaded with this dataset.', 'viswiz' ); ?></p><?php else : ?><ul class="viswiz-dataset-usage-list"><?php foreach ( $usage as $item ) : $post_id = absint( $item->post_id ); ?><li><a href="<?php echo esc_url( get_edit_post_link( $post_id, 'raw' ) ); ?>"><?php echo esc_html( $item->post_title ?: sprintf( __( 'Visualization #%d', 'viswiz' ), $post_id ) ); ?></a><span><?php echo esc_html( $item->visualization_type . ' · ' . $item->post_status ); ?></span></li><?php endforeach; ?></ul><?php endif; ?>
            </section>
        </div>
        <?php if ( $graph_like ) : ?>
            <section class="viswiz-admin-panel viswiz-dataset-validation <?php echo $issues ? 'has-warnings' : 'is-clean'; ?>">
                <h2><?php esc_html_e( 'Graph validation', 'viswiz' ); ?></h2>
                <?php if ( empty( $issues ) ) : ?><p><strong><?php esc_html_e( 'No obvious node/relation integrity issues found.', 'viswiz' ); ?></strong></p><?php else : ?><p><?php printf( esc_html__( '%d issues should be reviewed before publishing:', 'viswiz' ), count( $issues ) ); ?></p><ul><?php foreach ( array_slice( $issues, 0, 30 ) as $issue ) : ?><li><?php echo esc_html( $issue ); ?></li><?php endforeach; ?></ul><?php if ( count( $issues ) > 30 ) : ?><p class="description"><?php printf( esc_html__( '%d additional warnings not shown.', 'viswiz' ), count( $issues ) - 30 ); ?></p><?php endif; ?><?php endif; ?>
            </section>
            <div class="viswiz-dataset-graph-sections">
                <section class="viswiz-admin-panel">
                    <div class="viswiz-dataset-section-heading"><h2><?php esc_html_e( 'Nodes', 'viswiz' ); ?></h2><input type="search" placeholder="<?php echo esc_attr__( 'Search title, ID, type or subtype', 'viswiz' ); ?>" data-viswiz-dataset-node-search /></div>
                    <p class="description" data-viswiz-dataset-node-status><?php printf( esc_html__( 'Showing %d nodes.', 'viswiz' ), min( 150, count( $nodes ) ) ); ?></p>
                    <div class="viswiz-dataset-table-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Title', 'viswiz' ); ?></th><th><?php esc_html_e( 'Type', 'viswiz' ); ?></th><th><?php esc_html_e( 'ID', 'viswiz' ); ?></th><th><?php esc_html_e( 'Relations', 'viswiz' ); ?></th></tr></thead><tbody data-viswiz-dataset-node-rows><?php foreach ( array_slice( $nodes, 0, 150 ) as $node ) : $id = sanitize_key( $node['id'] ?? '' ); $title = $node['title'] ?? ( $node['label'] ?? '' ); $type = sanitize_key( $node['node_type'] ?? ( $node['entity_type'] ?? '' ) ); $subtype = sanitize_key( $node['node_subtype'] ?? '' ); $search_text = strtolower( implode( ' ', array( $title, $id, $type, $subtype ) ) ); ?><tr data-viswiz-dataset-node-row data-search="<?php echo esc_attr( $search_text ); ?>"><td><strong><?php echo esc_html( $title ?: __( '(untitled)', 'viswiz' ) ); ?></strong></td><td><?php echo esc_html( $type ?: '—' ); ?><?php if ( $subtype ) : ?><br /><span class="description"><?php echo esc_html( $subtype ); ?></span><?php endif; ?></td><td><code><?php echo esc_html( $id ?: '—' ); ?></code></td><td><?php echo esc_html( $degree[ $id ] ?? 0 ); ?></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php if ( count( $nodes ) > 150 ) : ?><p class="description"><?php printf( esc_html__( 'The inspector shows the first 150 of %d nodes. Use the graph editor for the complete working set.', 'viswiz' ), count( $nodes ) ); ?></p><?php endif; ?>
                </section>
                <section class="viswiz-admin-panel">
                    <div class="viswiz-dataset-section-heading"><h2><?php esc_html_e( 'Relations', 'viswiz' ); ?></h2><input type="search" placeholder="<?php echo esc_attr__( 'Search endpoints, label or type', 'viswiz' ); ?>" data-viswiz-dataset-relation-search /></div>
                    <p class="description" data-viswiz-dataset-relation-status><?php printf( esc_html__( 'Showing %d relations.', 'viswiz' ), min( 150, count( $links ) ) ); ?></p>
                    <div class="viswiz-dataset-table-scroll"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'From', 'viswiz' ); ?></th><th><?php esc_html_e( 'Relation', 'viswiz' ); ?></th><th><?php esc_html_e( 'To', 'viswiz' ); ?></th><th><?php esc_html_e( 'Direction', 'viswiz' ); ?></th></tr></thead><tbody data-viswiz-dataset-relation-rows><?php foreach ( array_slice( $links, 0, 150 ) as $link ) : $from = sanitize_key( $link['from'] ?? '' ); $to = sanitize_key( $link['to'] ?? '' ); $label = $link['label'] ?? ''; $relation_type = sanitize_key( $link['relation_type'] ?? '' ); $direction = sanitize_key( $link['direction'] ?? 'directed' ); $search_text = strtolower( implode( ' ', array( $from, $to, $label, $relation_type, $direction ) ) ); ?><tr data-viswiz-dataset-relation-row data-search="<?php echo esc_attr( $search_text ); ?>"><td><code><?php echo esc_html( $from ?: '—' ); ?></code></td><td><strong><?php echo esc_html( $label ?: ( $relation_type ?: __( '(unlabelled)', 'viswiz' ) ) ); ?></strong><?php if ( $relation_type && $relation_type !== sanitize_key( $label ) ) : ?><br /><span class="description"><?php echo esc_html( $relation_type ); ?></span><?php endif; ?></td><td><code><?php echo esc_html( $to ?: '—' ); ?></code></td><td><?php echo esc_html( $direction ); ?></td></tr><?php endforeach; ?></tbody></table></div>
                    <?php if ( count( $links ) > 150 ) : ?><p class="description"><?php printf( esc_html__( 'The inspector shows the first 150 of %d relations. Use the graph editor for the complete working set.', 'viswiz' ), count( $links ) ); ?></p><?php endif; ?>
                </section>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function viswiz_dataset_manager_update_details() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    $dataset_id = absint( $_POST['dataset_id'] ?? 0 );
    check_admin_referer( 'viswiz_update_dataset_details_' . $dataset_id );
    $dataset = viswiz_dataset_manager_get_dataset( $dataset_id );
    if ( ! $dataset ) {
        wp_die( esc_html__( 'Dataset not found.', 'viswiz' ) );
    }

    $name = sanitize_text_field( wp_unslash( $_POST['dataset_name'] ?? '' ) );
    if ( '' === $name ) {
        $name = $dataset->name;
    }
    $description = sanitize_textarea_field( wp_unslash( $_POST['dataset_description'] ?? '' ) );
    global $wpdb;
    $wpdb->update(
        viswiz_get_table_name( 'datasets' ),
        array( 'name' => $name, 'description' => $description, 'updated_at' => current_time( 'mysql' ) ),
        array( 'id' => $dataset_id ),
        array( '%s', '%s', '%s' ),
        array( '%d' )
    );

    wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $dataset_id . '&updated=1' ) );
    exit;
}

function viswiz_dataset_manager_duplicate_dataset() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    $dataset_id = absint( $_GET['dataset_id'] ?? 0 );
    check_admin_referer( 'viswiz_duplicate_dataset_' . $dataset_id );
    $dataset = viswiz_dataset_manager_get_dataset( $dataset_id );
    if ( ! $dataset ) {
        wp_die( esc_html__( 'Dataset not found.', 'viswiz' ) );
    }

    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert(
        viswiz_get_table_name( 'datasets' ),
        array( 'name' => $dataset->name . ' copy', 'description' => $dataset->description, 'data_type' => $dataset->data_type, 'created_at' => $now, 'updated_at' => $now ),
        array( '%s', '%s', '%s', '%s', '%s' )
    );
    $new_id = (int) $wpdb->insert_id;
    if ( ! $new_id ) {
        wp_die( esc_html__( 'Could not duplicate dataset.', 'viswiz' ) );
    }

    $points = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . viswiz_get_table_name( 'data_points' ) . ' WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC', $dataset_id ), ARRAY_A );
    foreach ( $points as $point ) {
        $wpdb->insert(
            viswiz_get_table_name( 'data_points' ),
            array( 'visualization_id' => 0, 'dataset_id' => $new_id, 'point_key' => $point['point_key'], 'label' => $point['label'], 'value' => (float) $point['value'], 'color' => $point['color'], 'meta' => $point['meta'], 'sort_order' => (int) $point['sort_order'], 'created_at' => $now, 'updated_at' => $now )
        );
    }
    $relations = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . viswiz_get_table_name( 'relations' ) . ' WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC', $dataset_id ), ARRAY_A );
    foreach ( $relations as $relation ) {
        $wpdb->insert(
            viswiz_get_table_name( 'relations' ),
            array( 'visualization_id' => 0, 'dataset_id' => $new_id, 'from_key' => $relation['from_key'], 'to_key' => $relation['to_key'], 'label' => $relation['label'], 'direction' => $relation['direction'], 'intensity' => (float) $relation['intensity'], 'relation_type' => $relation['relation_type'], 'meta' => $relation['meta'], 'sort_order' => (int) $relation['sort_order'], 'created_at' => $now, 'updated_at' => $now )
        );
    }

    wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $new_id . '&duplicated=1' ) );
    exit;
}

function viswiz_dataset_manager_replace_delete_handler() {
    remove_action( 'admin_post_viswiz_delete_dataset', 'viswiz_admin_delete_dataset' );
    add_action( 'admin_post_viswiz_delete_dataset', 'viswiz_dataset_manager_delete_dataset' );
}

function viswiz_dataset_manager_delete_dataset() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    $dataset_id = absint( $_GET['dataset_id'] ?? 0 );
    check_admin_referer( 'viswiz_delete_dataset_' . $dataset_id );
    if ( $dataset_id ) {
        global $wpdb;
        $visualizations_table = viswiz_get_table_name( 'visualization_data' );
        $post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $visualizations_table WHERE dataset_id = %d", $dataset_id ) );
        foreach ( $post_ids as $post_id ) {
            update_post_meta( absint( $post_id ), 'viswiz_dataset_id', 0 );
        }
        $wpdb->delete( viswiz_get_table_name( 'datasets' ), array( 'id' => $dataset_id ), array( '%d' ) );
        $wpdb->delete( viswiz_get_table_name( 'data_points' ), array( 'dataset_id' => $dataset_id ), array( '%d' ) );
        $wpdb->delete( viswiz_get_table_name( 'relations' ), array( 'dataset_id' => $dataset_id ), array( '%d' ) );
        $wpdb->query( $wpdb->prepare( "UPDATE $visualizations_table SET dataset_id = 0 WHERE dataset_id = %d", $dataset_id ) );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&deleted=1' ) );
    exit;
}
