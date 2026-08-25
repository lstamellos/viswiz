<?php
namespace VisWiz\Database;

use VisWiz\Domain\GraphValidator;
use VisWiz\Domain\Registry;
use VisWiz\Support;
use WP_Error;

final class DatasetRepository {
    public function get( int $dataset_id ): ?array {
        global $wpdb;
        $table = Support::table( 'datasets' );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $dataset_id ), ARRAY_A );
        if ( ! is_array( $row ) ) {
            return null;
        }
        $row['id']       = (int) $row['id'];
        $row['revision'] = (int) $row['revision'];
        $row['settings'] = Support::json_decode_array( $row['settings'] ?? '' );
        return $row;
    }

    public function list_with_counts( array $args = array() ): array {
        global $wpdb;
        $datasets = Support::table( 'datasets' );
        $rows     = Support::table( 'rows' );
        $nodes    = Support::table( 'nodes' );
        $edges    = Support::table( 'edges' );
        $posts    = $wpdb->posts;
        $meta     = $wpdb->postmeta;

        $search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
        $schema = sanitize_key( (string) ( $args['schema_type'] ?? '' ) );
        $limit  = min( 200, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
        $offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
        $where  = array( '1=1' );
        $params = array();

        if ( '' !== $search ) {
            $like    = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(d.name LIKE %s OR d.description LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ( '' !== $schema && Registry::schema_exists( $schema ) ) {
            $where[]  = 'd.schema_type = %s';
            $params[] = $schema;
        }

        $sql = "SELECT d.*,
                    COALESCE(rc.row_count,0) AS row_count,
                    COALESCE(nc.node_count,0) AS node_count,
                    COALESCE(ec.edge_count,0) AS relation_count,
                    COALESCE(vc.visualization_count,0) AS visualization_count
                FROM {$datasets} d
                LEFT JOIN (SELECT dataset_id,COUNT(*) row_count FROM {$rows} GROUP BY dataset_id) rc ON rc.dataset_id=d.id
                LEFT JOIN (SELECT dataset_id,COUNT(*) node_count FROM {$nodes} GROUP BY dataset_id) nc ON nc.dataset_id=d.id
                LEFT JOIN (SELECT dataset_id,COUNT(*) edge_count FROM {$edges} GROUP BY dataset_id) ec ON ec.dataset_id=d.id
                LEFT JOIN (
                    SELECT CAST(pm.meta_value AS UNSIGNED) dataset_id,COUNT(DISTINCT p.ID) visualization_count
                    FROM {$meta} pm
                    INNER JOIN {$posts} p ON p.ID=pm.post_id AND p.post_type='viswiz_visualization' AND p.post_status<>'trash'
                    WHERE pm.meta_key='_viswiz_dataset_id'
                    GROUP BY CAST(pm.meta_value AS UNSIGNED)
                ) vc ON vc.dataset_id=d.id
                WHERE " . implode( ' AND ', $where ) . '
                ORDER BY d.updated_at DESC,d.id DESC
                LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = $offset;

        $prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items    = $wpdb->get_results( $prepared, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( $items as &$item ) {
            $item['id']                  = (int) $item['id'];
            $item['revision']            = (int) $item['revision'];
            $item['row_count']           = (int) $item['row_count'];
            $item['node_count']          = (int) $item['node_count'];
            $item['relation_count']      = (int) $item['relation_count'];
            $item['visualization_count'] = (int) $item['visualization_count'];
            $item['settings']            = Support::json_decode_array( $item['settings'] ?? '' );
        }
        unset( $item );
        return $items;
    }

    public function count( array $args = array() ): int {
        global $wpdb;
        $table  = Support::table( 'datasets' );
        $search = sanitize_text_field( (string) ( $args['search'] ?? '' ) );
        $schema = sanitize_key( (string) ( $args['schema_type'] ?? '' ) );
        $where  = array( '1=1' );
        $params = array();
        if ( '' !== $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(name LIKE %s OR description LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }
        if ( '' !== $schema && Registry::schema_exists( $schema ) ) {
            $where[]  = 'schema_type = %s';
            $params[] = $schema;
        }
        $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode( ' AND ', $where );
        if ( $params ) {
            $sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }
        return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    }

    public function create( array $data ) {
        global $wpdb;
        $table       = Support::table( 'datasets' );
        $schema_type = sanitize_key( (string) ( $data['schema_type'] ?? 'categorical' ) );
        if ( ! Registry::schema_exists( $schema_type ) ) {
            return new WP_Error( 'viswiz_invalid_schema', __( 'Unsupported dataset schema.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $source_type = sanitize_key( (string) ( $data['source_type'] ?? 'manual' ) );
        $name        = sanitize_text_field( (string) ( $data['name'] ?? '' ) );
        if ( '' === $name ) {
            return new WP_Error( 'viswiz_missing_name', __( 'Dataset name is required.', 'viswiz' ), array( 'status' => 400 ) );
        }

        $wpdb->query( 'START TRANSACTION' );
        $now = current_time( 'mysql' );
        $ok  = $wpdb->insert(
            $table,
            array(
                'uuid'        => Support::uuid( (string) ( $data['uuid'] ?? '' ) ),
                'name'        => $name,
                'description' => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
                'schema_type' => $schema_type,
                'source_type' => $source_type ?: 'manual',
                'revision'    => 1,
                'settings'    => Support::json( Support::sanitize_meta( $data['settings'] ?? array() ) ),
                'created_by'  => get_current_user_id(),
                'created_at'  => $now,
                'updated_at'  => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
        );
        if ( false === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_dataset_create_failed', __( 'Could not create dataset.', 'viswiz' ), array( 'status' => 500 ) );
        }
        $dataset_id     = (int) $wpdb->insert_id;
        $initial_payload = 'graph' === $schema_type ? array( 'nodes' => array(), 'relations' => array() ) : array( 'rows' => array() );
        if ( ! $this->store_revision( $dataset_id, 1, $initial_payload, 'Dataset created' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $dataset_id;
    }

    public function update_metadata( int $dataset_id, array $data, ?int $expected_revision = null ) {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $dataset = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $dataset ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $dataset;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $dataset['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $dataset );
        }

        $schema = sanitize_key( (string) ( $data['schema_type'] ?? $dataset['schema_type'] ) );
        if ( $schema !== $dataset['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_schema_locked', __( 'Dataset schema is immutable after creation. Duplicate the dataset to use another schema.', 'viswiz' ), array( 'status' => 409 ) );
        }
        if ( ! Registry::schema_exists( $schema ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_invalid_schema', __( 'Unsupported dataset schema.', 'viswiz' ), array( 'status' => 400 ) );
        }

        $table = Support::table( 'datasets' );
        $ok    = $wpdb->update(
            $table,
            array(
                'name'        => sanitize_text_field( (string) ( $data['name'] ?? $dataset['name'] ) ),
                'description' => sanitize_textarea_field( (string) ( $data['description'] ?? $dataset['description'] ) ),
                'schema_type' => $schema,
                'source_type' => sanitize_key( (string) ( $data['source_type'] ?? $dataset['source_type'] ) ),
                'settings'    => Support::json( Support::sanitize_meta( $data['settings'] ?? Support::json_decode_array( $dataset['settings'] ?? '' ) ) ),
                'updated_at'  => current_time( 'mysql' ),
            ),
            array( 'id' => $dataset_id ),
            array( '%s', '%s', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
        if ( false === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_dataset_update_failed', __( 'Could not update dataset.', 'viswiz' ), array( 'status' => 500 ) );
        }

        $wpdb->query( 'COMMIT' );
        return $this->get( $dataset_id );
    }

    public function has_v2_data( int $dataset_id ): bool {
        global $wpdb;
        foreach ( array( 'rows', 'nodes', 'edges' ) as $suffix ) {
            $table = Support::table( $suffix );
            if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE dataset_id=%d", $dataset_id ) ) > 0 ) {
                return true;
            }
        }
        return false;
    }

    public function get_payload( int $dataset_id ): array {
        $dataset = $this->get( $dataset_id );
        if ( ! $dataset ) {
            return array();
        }
        if ( 'graph' === $dataset['schema_type'] ) {
            return array(
                'nodes'     => $this->get_nodes( $dataset_id ),
                'relations' => $this->get_edges( $dataset_id ),
            );
        }
        return array( 'rows' => $this->get_rows( $dataset_id ) );
    }

    public function get_rows( int $dataset_id ): array {
        global $wpdb;
        $table = Support::table( 'rows' );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE dataset_id=%d ORDER BY sort_order ASC,id ASC", $dataset_id ), ARRAY_A );
        return array_map( array( $this, 'normalize_row_from_db' ), $rows ?: array() );
    }

    public function get_nodes( int $dataset_id ): array {
        global $wpdb;
        $table = Support::table( 'nodes' );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE dataset_id=%d ORDER BY sort_order ASC,id ASC", $dataset_id ), ARRAY_A );
        return array_map( array( $this, 'normalize_node_from_db' ), $rows ?: array() );
    }

    public function get_edges( int $dataset_id ): array {
        global $wpdb;
        $table = Support::table( 'edges' );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE dataset_id=%d ORDER BY sort_order ASC,id ASC", $dataset_id ), ARRAY_A );
        return array_map( array( $this, 'normalize_edge_from_db' ), $rows ?: array() );
    }

    public function replace_payload( int $dataset_id, array $payload, ?int $expected_revision = null, string $note = 'Dataset replaced' ) {
        $dataset = $this->get( $dataset_id );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $dataset['revision'] ) {
            return $this->conflict_error( $dataset );
        }

        if ( 'graph' === $dataset['schema_type'] ) {
            $payload = $this->sanitize_graph_payload( $payload );
            $errors  = GraphValidator::validate( $payload, Registry::node_types(), Registry::relation_types() );
            $fatal   = array_values( array_filter( $errors, static fn( array $issue ): bool => 'error' === ( $issue['severity'] ?? 'error' ) ) );
            if ( $fatal ) {
                return new WP_Error( 'viswiz_invalid_graph', __( 'The graph contains integrity errors.', 'viswiz' ), array( 'status' => 422, 'issues' => $errors ) );
            }
        } else {
            $payload = array( 'rows' => $this->sanitize_rows_payload( $payload['rows'] ?? $payload ) );
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $locked;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( (string) $locked['schema_type'] !== (string) $dataset['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_schema_changed', __( 'The dataset schema changed while this payload was being prepared.', 'viswiz' ), array( 'status' => 409 ) );
        }

        $rows_table  = Support::table( 'rows' );
        $nodes_table = Support::table( 'nodes' );
        $edges_table = Support::table( 'edges' );
        if ( false === $wpdb->delete( $rows_table, array( 'dataset_id' => $dataset_id ), array( '%d' ) )
            || false === $wpdb->delete( $edges_table, array( 'dataset_id' => $dataset_id ), array( '%d' ) )
            || false === $wpdb->delete( $nodes_table, array( 'dataset_id' => $dataset_id ), array( '%d' ) ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }

        $now = current_time( 'mysql' );
        if ( 'graph' === $locked['schema_type'] ) {
            foreach ( $payload['nodes'] as $index => $node ) {
                if ( ! $this->insert_node( $dataset_id, $node, $index, $now ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return $this->db_error();
                }
            }
            foreach ( $payload['relations'] as $index => $edge ) {
                if ( ! $this->insert_edge( $dataset_id, $edge, $index, $now ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return $this->db_error();
                }
            }
        } else {
            foreach ( $payload['rows'] as $index => $row ) {
                if ( ! $this->insert_row( $dataset_id, $row, $index, $now ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return $this->db_error();
                }
            }
        }

        $new_revision = $this->bump_revision( $dataset_id, (int) $locked['revision'] );
        if ( ! $new_revision ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $snapshot = 'graph' === $locked['schema_type']
            ? array( 'nodes' => $this->get_nodes( $dataset_id ), 'relations' => $this->get_edges( $dataset_id ) )
            : array( 'rows' => $this->get_rows( $dataset_id ) );
        if ( ! $this->store_revision( $dataset_id, $new_revision, $snapshot, $note ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $this->response( $dataset_id );
    }

    public function save_node( int $dataset_id, array $node, ?int $expected_revision = null ) {
        $dataset = $this->get( $dataset_id );
        if ( ! $dataset || 'graph' !== $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_graph_dataset_required', __( 'A graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $raw_uuid = strtolower( trim( (string) ( $node['uuid'] ?? '' ) ) );
        if ( '' !== $raw_uuid && ! Support::is_uuid( $raw_uuid ) ) {
            return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid node UUID.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $node = $this->sanitize_node( $node );
        if ( '' === $node['title'] || '' === $node['node_type'] ) {
            return new WP_Error( 'viswiz_invalid_node', __( 'Node title and type are required.', 'viswiz' ), array( 'status' => 422 ) );
        }
        $node_types = Registry::node_types();
        if ( ! isset( $node_types[ $node['node_type'] ] ) ) {
            return new WP_Error( 'viswiz_unknown_node_type', __( 'The selected node type is not registered in the global schema.', 'viswiz' ), array( 'status' => 422 ) );
        }
        if ( '' !== $node['node_subtype'] && ! isset( $node_types[ $node['node_type'] ]['subtypes'][ $node['node_subtype'] ] ) ) {
            return new WP_Error( 'viswiz_unknown_node_subtype', __( 'The selected node subtype is not registered for this node type.', 'viswiz' ), array( 'status' => 422 ) );
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $locked;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( 'graph' !== (string) $locked['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_graph_dataset_required', __( 'A graph dataset is required.', 'viswiz' ), array( 'status' => 409 ) );
        }
        $table = Support::table( 'nodes' );
        $id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dataset_id=%d AND uuid=%s", $dataset_id, $node['uuid'] ) );
        $dupe  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dataset_id=%d AND slug=%s AND uuid<>%s", $dataset_id, $node['slug'], $node['uuid'] ) );
        if ( $dupe ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_duplicate_node_slug', __( 'Another node already uses this slug.', 'viswiz' ), array( 'status' => 409 ) );
        }
        $now   = current_time( 'mysql' );
        $data  = $this->node_db_data( $dataset_id, $node, $now );
        if ( $id ) {
            unset( $data['created_at'] );
            $ok = $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $data['sort_order'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$table} WHERE dataset_id=%d", $dataset_id ) );
            $ok                 = $wpdb->insert( $table, $data );
        }
        if ( false === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $revision = $this->bump_revision( $dataset_id, (int) $locked['revision'] );
        $snapshot = array( 'nodes' => $this->get_nodes( $dataset_id ), 'relations' => $this->get_edges( $dataset_id ) );
        if ( ! $revision || ! $this->store_revision( $dataset_id, $revision, $snapshot, $id ? 'Node updated' : 'Node created' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $this->response( $dataset_id );
    }

    public function delete_node( int $dataset_id, string $uuid, ?int $expected_revision = null ) {
        global $wpdb;
        $uuid = strtolower( trim( $uuid ) );
        if ( ! Support::is_uuid( $uuid ) ) {
            return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid node UUID.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $locked;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( 'graph' !== (string) $locked['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_graph_dataset_required', __( 'A graph dataset is required.', 'viswiz' ), array( 'status' => 409 ) );
        }
        $nodes = Support::table( 'nodes' );
        $edges = Support::table( 'edges' );
        $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$nodes} WHERE dataset_id=%d AND uuid=%s", $dataset_id, $uuid ) );
        if ( ! $exists ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_node_not_found', __( 'Node not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( false === $wpdb->delete( $edges, array( 'dataset_id' => $dataset_id, 'from_node_uuid' => $uuid ) )
            || false === $wpdb->delete( $edges, array( 'dataset_id' => $dataset_id, 'to_node_uuid' => $uuid ) ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $ok = $wpdb->delete( $nodes, array( 'dataset_id' => $dataset_id, 'uuid' => $uuid ) );
        if ( 1 !== $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return false === $ok ? $this->db_error() : new WP_Error( 'viswiz_node_not_found', __( 'Node not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        $revision = $this->bump_revision( $dataset_id, (int) $locked['revision'] );
        $snapshot = array( 'nodes' => $this->get_nodes( $dataset_id ), 'relations' => $this->get_edges( $dataset_id ) );
        if ( ! $revision || ! $this->store_revision( $dataset_id, $revision, $snapshot, 'Node deleted' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $this->response( $dataset_id );
    }

    public function save_edge( int $dataset_id, array $edge, ?int $expected_revision = null ) {
        $raw_uuid = strtolower( trim( (string) ( $edge['uuid'] ?? '' ) ) );
        if ( '' !== $raw_uuid && ! Support::is_uuid( $raw_uuid ) ) {
            return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid relation UUID.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $edge = $this->sanitize_edge( $edge );
        if ( '' === $edge['from_node_uuid'] || '' === $edge['to_node_uuid'] || ! Support::is_uuid( $edge['from_node_uuid'] ) || ! Support::is_uuid( $edge['to_node_uuid'] ) ) {
            return new WP_Error( 'viswiz_invalid_relation', __( 'Both relation endpoints must be valid node UUIDs.', 'viswiz' ), array( 'status' => 422 ) );
        }
        if ( '' !== $edge['relation_type'] && ! isset( Registry::relation_types()[ $edge['relation_type'] ] ) ) {
            return new WP_Error( 'viswiz_unknown_relation_type', __( 'The selected relation type is not registered in the global schema.', 'viswiz' ), array( 'status' => 422 ) );
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $locked;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( 'graph' !== (string) $locked['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_graph_dataset_required', __( 'A graph dataset is required.', 'viswiz' ), array( 'status' => 409 ) );
        }

        $nodes = Support::table( 'nodes' );
        foreach ( array( $edge['from_node_uuid'], $edge['to_node_uuid'] ) as $node_uuid ) {
            $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$nodes} WHERE dataset_id=%d AND uuid=%s", $dataset_id, $node_uuid ) );
            if ( ! $exists ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'viswiz_relation_endpoint_missing', __( 'A relation endpoint does not exist in this dataset.', 'viswiz' ), array( 'status' => 422 ) );
            }
        }

        $table = Support::table( 'edges' );
        $id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dataset_id=%d AND uuid=%s", $dataset_id, $edge['uuid'] ) );
        $now   = current_time( 'mysql' );
        $data  = $this->edge_db_data( $dataset_id, $edge, $now );
        if ( $id ) {
            unset( $data['created_at'] );
            $ok = $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $data['sort_order'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$table} WHERE dataset_id=%d", $dataset_id ) );
            $ok                 = $wpdb->insert( $table, $data );
        }
        if ( false === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $revision = $this->bump_revision( $dataset_id, (int) $locked['revision'] );
        $snapshot = array( 'nodes' => $this->get_nodes( $dataset_id ), 'relations' => $this->get_edges( $dataset_id ) );
        if ( ! $revision || ! $this->store_revision( $dataset_id, $revision, $snapshot, $id ? 'Relation updated' : 'Relation created' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $this->response( $dataset_id );
    }

    public function delete_edge( int $dataset_id, string $uuid, ?int $expected_revision = null ) {
        global $wpdb;
        $uuid = strtolower( trim( $uuid ) );
        if ( ! Support::is_uuid( $uuid ) ) {
            return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid relation UUID.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $locked;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( 'graph' !== (string) $locked['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_graph_dataset_required', __( 'A graph dataset is required.', 'viswiz' ), array( 'status' => 409 ) );
        }
        $ok = $wpdb->delete( Support::table( 'edges' ), array( 'dataset_id' => $dataset_id, 'uuid' => $uuid ) );
        if ( false === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        if ( 0 === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_relation_not_found', __( 'Relation not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        $revision = $this->bump_revision( $dataset_id, (int) $locked['revision'] );
        $snapshot = array( 'nodes' => $this->get_nodes( $dataset_id ), 'relations' => $this->get_edges( $dataset_id ) );
        if ( ! $revision || ! $this->store_revision( $dataset_id, $revision, $snapshot, 'Relation deleted' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $this->response( $dataset_id );
    }

    public function save_row( int $dataset_id, array $row, ?int $expected_revision = null ) {
        $dataset = $this->get( $dataset_id );
        if ( ! $dataset || 'graph' === $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $raw_uuid = strtolower( trim( (string) ( $row['uuid'] ?? '' ) ) );
        if ( '' !== $raw_uuid && ! Support::is_uuid( $raw_uuid ) ) {
            return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid row UUID.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $row = $this->sanitize_row( $row );
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $locked;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( 'graph' === (string) $locked['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 409 ) );
        }
        $table = Support::table( 'rows' );
        $id    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE dataset_id=%d AND uuid=%s", $dataset_id, $row['uuid'] ) );
        $now   = current_time( 'mysql' );
        $data  = $this->row_db_data( $dataset_id, $row, $now );
        if ( $id ) {
            unset( $data['created_at'] );
            $ok = $wpdb->update( $table, $data, array( 'id' => $id ) );
        } else {
            $data['sort_order'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$table} WHERE dataset_id=%d", $dataset_id ) );
            $ok                 = $wpdb->insert( $table, $data );
        }
        if ( false === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $revision = $this->bump_revision( $dataset_id, (int) $locked['revision'] );
        $snapshot = array( 'rows' => $this->get_rows( $dataset_id ) );
        if ( ! $revision || ! $this->store_revision( $dataset_id, $revision, $snapshot, $id ? 'Row updated' : 'Row created' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $this->response( $dataset_id );
    }

    public function delete_row( int $dataset_id, string $uuid, ?int $expected_revision = null ) {
        global $wpdb;
        $uuid = strtolower( trim( $uuid ) );
        if ( ! Support::is_uuid( $uuid ) ) {
            return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid row UUID.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $locked;
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( 'graph' === (string) $locked['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 409 ) );
        }
        $ok = $wpdb->delete( Support::table( 'rows' ), array( 'dataset_id' => $dataset_id, 'uuid' => $uuid ) );
        if ( false === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        if ( 0 === $ok ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_row_not_found', __( 'Row not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        $revision = $this->bump_revision( $dataset_id, (int) $locked['revision'] );
        $snapshot = array( 'rows' => $this->get_rows( $dataset_id ) );
        if ( ! $revision || ! $this->store_revision( $dataset_id, $revision, $snapshot, 'Row deleted' ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }
        $wpdb->query( 'COMMIT' );
        return $this->response( $dataset_id );
    }

    public function duplicate( int $dataset_id ) {
        $source = $this->get( $dataset_id );
        if ( ! $source ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        $new_id = $this->create(
            array(
                'name'        => $source['name'] . ' copy',
                'description' => $source['description'],
                'schema_type' => $source['schema_type'],
                'source_type' => $source['source_type'],
                'settings'    => $source['settings'],
            )
        );
        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }
        $result = $this->replace_payload( (int) $new_id, $this->get_payload( $dataset_id ), 1, 'Duplicated from dataset #' . $dataset_id );
        if ( is_wp_error( $result ) ) {
            $this->delete_with_usage_cleanup( (int) $new_id );
            return $result;
        }
        return (int) $new_id;
    }

    public function delete_with_usage_cleanup( int $dataset_id ): bool {
        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $locked = $this->lock_dataset( $dataset_id );
        if ( is_wp_error( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
        $post_ids = array_map(
            'absint',
            $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_viswiz_dataset_id' AND CAST(meta_value AS UNSIGNED)=%d",
                    $dataset_id
                )
            ) ?: array()
        );
        $postmeta_deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->postmeta} WHERE meta_key='_viswiz_dataset_id' AND CAST(meta_value AS UNSIGNED)=%d",
                $dataset_id
            )
        );
        if ( false === $postmeta_deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
        foreach ( array( 'rows', 'edges', 'nodes', 'dataset_revisions' ) as $suffix ) {
            if ( false === $wpdb->delete( Support::table( $suffix ), array( 'dataset_id' => $dataset_id ), array( '%d' ) ) ) {
                $wpdb->query( 'ROLLBACK' );
                return false;
            }
        }
        $deleted = $wpdb->delete( Support::table( 'datasets' ), array( 'id' => $dataset_id ), array( '%d' ) );
        if ( 1 !== $deleted ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
        $wpdb->query( 'COMMIT' );
        foreach ( $post_ids as $post_id ) {
            clean_post_cache( $post_id );
        }
        return true;
    }

    public function revisions( int $dataset_id, int $limit = 30 ): array {
        global $wpdb;
        $table = Support::table( 'dataset_revisions' );
        $limit = min( 100, max( 1, $limit ) );
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT id,dataset_id,revision,actor_user_id,note,created_at FROM {$table} WHERE dataset_id=%d ORDER BY revision DESC LIMIT %d", $dataset_id, $limit ),
            ARRAY_A
        ) ?: array();
    }

    public function restore_revision( int $dataset_id, int $revision, ?int $expected_revision = null ) {
        global $wpdb;
        $table    = Support::table( 'dataset_revisions' );
        $snapshot = $wpdb->get_var( $wpdb->prepare( "SELECT snapshot FROM {$table} WHERE dataset_id=%d AND revision=%d", $dataset_id, $revision ) );
        if ( ! is_string( $snapshot ) || '' === $snapshot ) {
            return new WP_Error( 'viswiz_revision_not_found', __( 'Dataset revision not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        return $this->replace_payload( $dataset_id, Support::json_decode_array( $snapshot ), $expected_revision, 'Restored revision ' . $revision );
    }

    public function response( int $dataset_id ): array {
        $dataset = $this->get( $dataset_id );
        return array(
            'dataset'    => $dataset,
            'revision'   => (int) ( $dataset['revision'] ?? 0 ),
            'payload'    => $this->get_payload( $dataset_id ),
            'validation' => $dataset && 'graph' === $dataset['schema_type'] ? GraphValidator::validate( $this->get_payload( $dataset_id ), Registry::node_types(), Registry::relation_types() ) : array(),
        );
    }

    private function lock_dataset( int $dataset_id ) {
        global $wpdb;
        $table = Support::table( 'datasets' );
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $dataset_id ), ARRAY_A );
        return is_array( $row ) ? $row : new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
    }

    private function bump_revision( int $dataset_id, int $current ): int {
        global $wpdb;
        $table = Support::table( 'datasets' );
        $next  = $current + 1;
        $ok    = $wpdb->update(
            $table,
            array( 'revision' => $next, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $dataset_id, 'revision' => $current ),
            array( '%d', '%s' ),
            array( '%d', '%d' )
        );
        return 1 === $ok ? $next : 0;
    }

    private function store_revision( int $dataset_id, int $revision, array $payload, string $note ): bool {
        global $wpdb;
        $table = Support::table( 'dataset_revisions' );
        $ok    = $wpdb->replace(
            $table,
            array(
                'dataset_id'    => $dataset_id,
                'revision'      => $revision,
                'snapshot'      => Support::json( $payload ),
                'actor_user_id' => get_current_user_id(),
                'note'          => sanitize_text_field( $note ),
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' )
        );
        return false !== $ok;
    }

    private function sanitize_rows_payload( mixed $rows ): array {
        $out = array();
        foreach ( (array) $rows as $row ) {
            if ( is_array( $row ) ) {
                $out[] = $this->sanitize_row( $row );
            }
        }
        return $out;
    }

    private function sanitize_graph_payload( array $payload ): array {
        $nodes = array();
        foreach ( (array) ( $payload['nodes'] ?? array() ) as $node ) {
            if ( is_array( $node ) ) {
                $nodes[] = $this->sanitize_node( $node );
            }
        }
        $edges = array();
        foreach ( (array) ( $payload['relations'] ?? $payload['links'] ?? array() ) as $edge ) {
            if ( ! is_array( $edge ) ) {
                continue;
            }
            if ( empty( $edge['from_node_uuid'] ) && ! empty( $edge['from'] ) ) {
                $edge['from_node_uuid'] = $this->resolve_node_ref( $nodes, (string) $edge['from'] );
            }
            if ( empty( $edge['to_node_uuid'] ) && ! empty( $edge['to'] ) ) {
                $edge['to_node_uuid'] = $this->resolve_node_ref( $nodes, (string) $edge['to'] );
            }
            $edges[] = $this->sanitize_edge( $edge );
        }
        return array( 'nodes' => $nodes, 'relations' => $edges );
    }

    private function resolve_node_ref( array $nodes, string $ref ): string {
        foreach ( $nodes as $node ) {
            if ( $ref === $node['uuid'] || $ref === $node['slug'] || $ref === $node['title'] || $ref === $node['label'] ) {
                return $node['uuid'];
            }
        }
        return '';
    }

    private function sanitize_row( array $row ): array {
        $value = array_key_exists( 'value', $row ) && '' !== $row['value'] && null !== $row['value'] ? (float) $row['value'] : null;
        return array(
            'uuid'      => Support::uuid( (string) ( $row['uuid'] ?? '' ) ),
            'row_key'   => sanitize_key( (string) ( $row['row_key'] ?? $row['key'] ?? '' ) ),
            'label'     => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
            'value'     => $value,
            'x_value'   => sanitize_text_field( (string) ( $row['x_value'] ?? $row['x'] ?? '' ) ),
            'x_numeric' => isset( $row['x_numeric'] ) && '' !== $row['x_numeric'] ? (float) $row['x_numeric'] : ( is_numeric( $row['x'] ?? null ) ? (float) $row['x'] : null ),
            'y_value'   => isset( $row['y_value'] ) && '' !== $row['y_value'] ? (float) $row['y_value'] : ( isset( $row['y'] ) && '' !== $row['y'] ? (float) $row['y'] : null ),
            'latitude'  => isset( $row['latitude'] ) && '' !== $row['latitude'] ? max( -90, min( 90, (float) $row['latitude'] ) ) : ( isset( $row['lat'] ) ? max( -90, min( 90, (float) $row['lat'] ) ) : null ),
            'longitude' => isset( $row['longitude'] ) && '' !== $row['longitude'] ? max( -180, min( 180, (float) $row['longitude'] ) ) : ( isset( $row['lng'] ) ? max( -180, min( 180, (float) $row['lng'] ) ) : null ),
            'color'     => Support::sanitize_color( $row['color'] ?? '' ),
            'meta'      => Support::sanitize_meta( $row['meta'] ?? array() ),
        );
    }

    private function sanitize_node( array $node ): array {
        $title = sanitize_text_field( (string) ( $node['title'] ?? $node['label'] ?? '' ) );
        $slug  = sanitize_title( (string) ( $node['slug'] ?? $node['id'] ?? $title ) );
        if ( '' === $slug ) {
            $slug = 'node-' . substr( str_replace( '-', '', Support::uuid() ), 0, 12 );
        }
        return array(
            'uuid'            => Support::uuid( (string) ( $node['uuid'] ?? '' ) ),
            'slug'            => $slug,
            'title'           => $title,
            'label'           => sanitize_text_field( (string) ( $node['label'] ?? $title ) ),
            'node_type'       => sanitize_key( (string) ( $node['node_type'] ?? '' ) ),
            'node_subtype'    => sanitize_key( (string) ( $node['node_subtype'] ?? '' ) ),
            'description'     => Support::sanitize_html( $node['description'] ?? $node['description_html'] ?? '' ),
            'main_image_id'   => absint( $node['main_image_id'] ?? $node['main_image'] ?? 0 ),
            'other_image_ids' => Support::int_list( $node['other_image_ids'] ?? $node['other_images'] ?? array() ),
            'meta'            => $this->sanitize_node_meta( $node['meta'] ?? array() ),
        );
    }

    private function sanitize_node_meta( mixed $value ): array {
        $meta = Support::json_decode_array( $value );
        $public_fields = (array) ( $meta['public_fields'] ?? array() );
        unset( $meta['public_fields'] );
        $meta = Support::sanitize_recursive( $meta );
        $safe_fields = array();
        foreach ( $public_fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }
            $type = sanitize_key( (string) ( $field['type'] ?? 'short' ) );
            if ( ! in_array( $type, array( 'short', 'long', 'url', 'formatted' ), true ) ) {
                $type = 'short';
            }
            $raw = (string) ( $field['value'] ?? '' );
            $safe = 'url' === $type ? esc_url_raw( $raw ) : ( 'formatted' === $type ? wp_kses_post( $raw ) : sanitize_textarea_field( $raw ) );
            if ( '' === $safe ) {
                continue;
            }
            $safe_fields[] = array(
                'label' => sanitize_text_field( (string) ( $field['label'] ?? $field['key'] ?? '' ) ),
                'type'  => $type,
                'value' => $safe,
            );
        }
        if ( $safe_fields ) {
            $meta['public_fields'] = $safe_fields;
        }
        return is_array( $meta ) ? $meta : array();
    }

    private function sanitize_edge( array $edge ): array {
        $direction = sanitize_key( (string) ( $edge['direction'] ?? 'directed' ) );
        if ( ! in_array( $direction, array( 'directed', 'bidirectional', 'undirected' ), true ) ) {
            $direction = 'directed';
        }
        return array(
            'uuid'           => Support::uuid( (string) ( $edge['uuid'] ?? '' ) ),
            'from_node_uuid' => (string) ( $edge['from_node_uuid'] ?? '' ),
            'to_node_uuid'   => (string) ( $edge['to_node_uuid'] ?? '' ),
            'relation_type'  => sanitize_key( (string) ( $edge['relation_type'] ?? '' ) ),
            'label'          => sanitize_text_field( (string) ( $edge['label'] ?? '' ) ),
            'inverse_label'  => sanitize_text_field( (string) ( $edge['inverse_label'] ?? '' ) ),
            'direction'      => $direction,
            'intensity'      => max( 0.1, min( 20, (float) ( $edge['intensity'] ?? 1 ) ) ),
            'meta'           => Support::sanitize_meta( $edge['meta'] ?? array() ),
        );
    }

    private function insert_row( int $dataset_id, array $row, int $sort_order, string $now ): bool {
        global $wpdb;
        $data               = $this->row_db_data( $dataset_id, $row, $now );
        $data['sort_order'] = $sort_order;
        return false !== $wpdb->insert( Support::table( 'rows' ), $data );
    }

    private function row_db_data( int $dataset_id, array $row, string $now ): array {
        return array(
            'uuid'       => $row['uuid'],
            'dataset_id' => $dataset_id,
            'row_key'    => $row['row_key'],
            'label'      => $row['label'],
            'value'      => $row['value'],
            'x_value'    => $row['x_value'],
            'x_numeric'  => $row['x_numeric'],
            'y_value'    => $row['y_value'],
            'latitude'   => $row['latitude'],
            'longitude'  => $row['longitude'],
            'color'      => $row['color'],
            'meta'       => Support::json( $row['meta'] ),
            'created_at' => $now,
            'updated_at' => $now,
        );
    }

    private function insert_node( int $dataset_id, array $node, int $sort_order, string $now ): bool {
        global $wpdb;
        $data               = $this->node_db_data( $dataset_id, $node, $now );
        $data['sort_order'] = $sort_order;
        return false !== $wpdb->insert( Support::table( 'nodes' ), $data );
    }

    private function node_db_data( int $dataset_id, array $node, string $now ): array {
        return array(
            'uuid'            => $node['uuid'],
            'dataset_id'      => $dataset_id,
            'slug'            => $node['slug'],
            'title'           => $node['title'],
            'label'           => $node['label'],
            'node_type'       => $node['node_type'],
            'node_subtype'    => $node['node_subtype'],
            'description'     => $node['description'],
            'main_image_id'   => $node['main_image_id'],
            'other_image_ids' => implode( ',', $node['other_image_ids'] ),
            'meta'            => Support::json( $node['meta'] ),
            'created_at'      => $now,
            'updated_at'      => $now,
        );
    }

    private function insert_edge( int $dataset_id, array $edge, int $sort_order, string $now ): bool {
        global $wpdb;
        $data               = $this->edge_db_data( $dataset_id, $edge, $now );
        $data['sort_order'] = $sort_order;
        return false !== $wpdb->insert( Support::table( 'edges' ), $data );
    }

    private function edge_db_data( int $dataset_id, array $edge, string $now ): array {
        return array(
            'uuid'           => $edge['uuid'],
            'dataset_id'     => $dataset_id,
            'from_node_uuid' => $edge['from_node_uuid'],
            'to_node_uuid'   => $edge['to_node_uuid'],
            'relation_type'  => $edge['relation_type'],
            'label'          => $edge['label'],
            'inverse_label'  => $edge['inverse_label'],
            'direction'      => $edge['direction'],
            'intensity'      => $edge['intensity'],
            'meta'           => Support::json( $edge['meta'] ),
            'created_at'     => $now,
            'updated_at'     => $now,
        );
    }

    private function normalize_row_from_db( array $row ): array {
        return array(
            'uuid'      => (string) $row['uuid'],
            'row_key'   => (string) $row['row_key'],
            'label'     => (string) $row['label'],
            'value'     => null === $row['value'] ? null : (float) $row['value'],
            'x_value'   => (string) $row['x_value'],
            'x_numeric' => null === $row['x_numeric'] ? null : (float) $row['x_numeric'],
            'y_value'   => null === $row['y_value'] ? null : (float) $row['y_value'],
            'latitude'  => null === $row['latitude'] ? null : (float) $row['latitude'],
            'longitude' => null === $row['longitude'] ? null : (float) $row['longitude'],
            'color'     => (string) $row['color'],
            'meta'      => Support::json_decode_array( $row['meta'] ?? '' ),
        );
    }

    private function normalize_node_from_db( array $row ): array {
        $main_image_id = (int) $row['main_image_id'];
        $other_ids     = Support::int_list( $row['other_image_ids'] ?? '' );
        $images        = array();
        foreach ( array_values( array_unique( array_filter( array_merge( array( $main_image_id ), $other_ids ) ) ) ) as $image_id ) {
            $url = wp_get_attachment_image_url( $image_id, 'large' );
            if ( $url ) {
                $images[] = array(
                    'id'       => $image_id,
                    'url'      => esc_url_raw( $url ),
                    'thumb'    => esc_url_raw( wp_get_attachment_image_url( $image_id, 'medium' ) ?: $url ),
                    'alt'      => sanitize_text_field( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ),
                    'caption'  => sanitize_text_field( (string) wp_get_attachment_caption( $image_id ) ),
                    'featured' => $image_id === $main_image_id,
                );
            }
        }
        return array(
            'uuid'            => (string) $row['uuid'],
            'slug'            => (string) $row['slug'],
            'id'              => (string) $row['slug'],
            'title'           => (string) $row['title'],
            'label'           => (string) $row['label'],
            'node_type'       => (string) $row['node_type'],
            'node_subtype'    => (string) $row['node_subtype'],
            'description'     => (string) $row['description'],
            'description_html'=> (string) $row['description'],
            'main_image_id'   => $main_image_id,
            'other_image_ids' => $other_ids,
            'image_gallery'   => $images,
            'meta'            => Support::json_decode_array( $row['meta'] ?? '' ),
        );
    }

    private function normalize_edge_from_db( array $row ): array {
        return array(
            'uuid'           => (string) $row['uuid'],
            'from_node_uuid' => (string) $row['from_node_uuid'],
            'to_node_uuid'   => (string) $row['to_node_uuid'],
            'relation_type'  => (string) $row['relation_type'],
            'label'          => (string) $row['label'],
            'inverse_label'  => (string) $row['inverse_label'],
            'direction'      => (string) $row['direction'],
            'intensity'      => (float) $row['intensity'],
            'meta'           => Support::json_decode_array( $row['meta'] ?? '' ),
        );
    }

    private function conflict_error( array $dataset ): WP_Error {
        return new WP_Error(
            'viswiz_revision_conflict',
            __( 'This dataset changed after the editor was opened. Reload it before saving to avoid overwriting newer work.', 'viswiz' ),
            array( 'status' => 409, 'current_revision' => (int) ( $dataset['revision'] ?? 0 ) )
        );
    }

    private function db_error(): WP_Error {
        global $wpdb;
        return new WP_Error( 'viswiz_database_error', __( 'The dataset could not be saved.', 'viswiz' ), array( 'status' => 500, 'detail' => sanitize_text_field( $wpdb->last_error ) ) );
    }
}
