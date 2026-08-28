<?php
namespace VisWiz\Database;

use VisWiz\Support;

final class DatasetCollectionRepository {
    public const MAX_PER_PAGE = 100;

    public function rows( int $dataset_id, int $page = 1, int $per_page = 100, string $search = '' ): array {
        global $wpdb;
        $table = Support::table( 'rows' );
        $where = array( 'dataset_id = %d' );
        $params = array( $dataset_id );
        $search = sanitize_text_field( $search );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(uuid LIKE %s OR row_key LIKE %s OR label LIKE %s OR x_value LIKE %s OR meta LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like );
        }
        return $this->page_query(
            $table,
            implode( ' AND ', $where ),
            $params,
            $page,
            $per_page,
            'sort_order ASC,id ASC',
            static function ( array $row ): array {
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
        );
    }

    public function nodes( int $dataset_id, int $page = 1, int $per_page = 100, string $search = '' ): array {
        global $wpdb;
        $nodes = Support::table( 'nodes' );
        $edges = Support::table( 'edges' );
        $where = array( 'n.dataset_id = %d' );
        $params = array( $dataset_id );
        $search = sanitize_text_field( $search );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(n.uuid LIKE %s OR n.slug LIKE %s OR n.title LIKE %s OR n.label LIKE %s OR n.node_type LIKE %s OR n.node_subtype LIKE %s OR n.description LIKE %s OR n.meta LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like, $like, $like, $like );
        }

        $page = max( 1, $page );
        $per_page = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
        $offset = ( $page - 1 ) * $per_page;
        $where_sql = implode( ' AND ', $where );
        $count_sql = "SELECT COUNT(*) FROM {$nodes} n WHERE {$where_sql}";
        $count_query = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $select_sql = "SELECT n.*,
                (SELECT COUNT(*) FROM {$edges} ef WHERE ef.dataset_id=n.dataset_id AND ef.from_node_uuid=n.uuid)
              + (SELECT COUNT(*) FROM {$edges} et WHERE et.dataset_id=n.dataset_id AND et.to_node_uuid=n.uuid) AS degree
            FROM {$nodes} n
            WHERE {$where_sql}
            ORDER BY n.sort_order ASC,n.id ASC
            LIMIT %d OFFSET %d";
        $select_params = array_merge( $params, array( $per_page, $offset ) );
        $query = $wpdb->prepare( $select_sql, $select_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $query, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = array_map( array( $this, 'normalize_node' ), $rows );
        foreach ( $items as $index => &$item ) {
            $item['degree'] = (int) ( $rows[ $index ]['degree'] ?? 0 );
        }
        unset( $item );
        return $this->page_result( $items, $total, $page, $per_page );
    }

    public function relations( int $dataset_id, int $page = 1, int $per_page = 100, string $search = '' ): array {
        global $wpdb;
        $edges = Support::table( 'edges' );
        $nodes = Support::table( 'nodes' );
        $where = array( 'e.dataset_id = %d' );
        $params = array( $dataset_id );
        $search = sanitize_text_field( $search );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(e.uuid LIKE %s OR e.relation_type LIKE %s OR e.label LIKE %s OR e.inverse_label LIKE %s OR e.direction LIKE %s OR e.meta LIKE %s OR nf.title LIKE %s OR nf.slug LIKE %s OR nt.title LIKE %s OR nt.slug LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like );
        }
        $page = max( 1, $page );
        $per_page = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
        $offset = ( $page - 1 ) * $per_page;
        $where_sql = implode( ' AND ', $where );
        $joins = " LEFT JOIN {$nodes} nf ON nf.dataset_id=e.dataset_id AND nf.uuid=e.from_node_uuid
                   LEFT JOIN {$nodes} nt ON nt.dataset_id=e.dataset_id AND nt.uuid=e.to_node_uuid";
        $count_sql = "SELECT COUNT(*) FROM {$edges} e {$joins} WHERE {$where_sql}";
        $count_query = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $select_sql = "SELECT e.*, nf.title AS from_title, nf.slug AS from_slug, nt.title AS to_title, nt.slug AS to_slug
            FROM {$edges} e {$joins}
            WHERE {$where_sql}
            ORDER BY e.sort_order ASC,e.id ASC
            LIMIT %d OFFSET %d";
        $query = $wpdb->prepare( $select_sql, array_merge( $params, array( $per_page, $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $query, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = array_map(
            static function ( array $row ): array {
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
                    'from_title'     => (string) ( $row['from_title'] ?? '' ),
                    'from_slug'      => (string) ( $row['from_slug'] ?? '' ),
                    'to_title'       => (string) ( $row['to_title'] ?? '' ),
                    'to_slug'        => (string) ( $row['to_slug'] ?? '' ),
                );
            },
            $rows
        );
        return $this->page_result( $items, $total, $page, $per_page );
    }

    public function node_options( int $dataset_id, string $search = '', int $per_page = 20 ): array {
        global $wpdb;
        $table = Support::table( 'nodes' );
        $per_page = min( 50, max( 1, $per_page ) );
        $where = array( 'dataset_id = %d' );
        $params = array( $dataset_id );
        $search = sanitize_text_field( $search );
        if ( '' !== $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where[] = '(uuid LIKE %s OR slug LIKE %s OR title LIKE %s OR label LIKE %s OR node_type LIKE %s OR node_subtype LIKE %s)';
            array_push( $params, $like, $like, $like, $like, $like, $like );
        }
        $sql = "SELECT uuid,slug,title,label,node_type,node_subtype FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY title ASC,slug ASC LIMIT %d';
        $params[] = $per_page;
        $query = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $query, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return array_map(
            static fn( array $row ): array => array(
                'uuid'         => (string) $row['uuid'],
                'slug'         => (string) $row['slug'],
                'title'        => (string) $row['title'],
                'label'        => (string) $row['label'],
                'node_type'    => (string) $row['node_type'],
                'node_subtype' => (string) $row['node_subtype'],
            ),
            $rows
        );
    }

    public function graph_orphan_count( int $dataset_id ): int {
        global $wpdb;
        $edges = Support::table( 'edges' );
        $nodes = Support::table( 'nodes' );
        $sql = "SELECT COUNT(*) FROM {$edges} e
            LEFT JOIN {$nodes} nf ON nf.dataset_id=e.dataset_id AND nf.uuid=e.from_node_uuid
            LEFT JOIN {$nodes} nt ON nt.dataset_id=e.dataset_id AND nt.uuid=e.to_node_uuid
            WHERE e.dataset_id=%d AND (nf.id IS NULL OR nt.id IS NULL)";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $dataset_id ) );
    }

    private function page_query( string $table, string $where_sql, array $params, int $page, int $per_page, string $order_by, callable $normalize ): array {
        global $wpdb;
        $page = max( 1, $page );
        $per_page = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
        $offset = ( $page - 1 ) * $per_page;
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $count_query = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $select_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d";
        $query = $wpdb->prepare( $select_sql, array_merge( $params, array( $per_page, $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $query, ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $this->page_result( array_map( $normalize, $rows ), $total, $page, $per_page );
    }

    private function page_result( array $items, int $total, int $page, int $per_page ): array {
        return array(
            'items'       => $items,
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
        );
    }

    private function normalize_node( array $row ): array {
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
            'main_image_id'   => (int) $row['main_image_id'],
            'other_image_ids' => Support::int_list( $row['other_image_ids'] ?? '' ),
            'meta'            => Support::json_decode_array( $row['meta'] ?? '' ),
        );
    }
}
