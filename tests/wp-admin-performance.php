<?php
/**
 * Isolated CI benchmark of existing administrator REST collections.
 * Run after tests/wp-smoke.php on disposable WordPress + MySQL:
 *   wp eval-file "$GITHUB_WORKSPACE/tests/wp-admin-performance.php" --path=/tmp/wordpress --allow-root
 *
 * The dataset payloads are inserted in bounded SQL batches only to produce realistic
 * table sizes; this does not benchmark the product's importer or mutation APIs.
 */
use VisWiz\Database\DatasetRepository;
use VisWiz\Admin\DatasetEditorPage;
use VisWiz\Support;

function viswiz_perf_assert( bool $condition, string $message ): void {
    if ( ! $condition ) {
        throw new RuntimeException( 'Performance benchmark: ' . $message );
    }
}

function viswiz_perf_uuid( int $number ): string {
    return sprintf( '%08x-0000-4000-8000-%012x', $number, $number );
}

function viswiz_perf_dataset( string $schema, int $size ) {
    $repo = new DatasetRepository();
    $id = $repo->create( array(
        'name'        => 'CI performance ' . $schema . ' ' . $size,
        'schema_type' => $schema,
    ) );
    viswiz_perf_assert( ! is_wp_error( $id ), 'Failed to create dataset.' );
    return (int) $id;
}

function viswiz_perf_insert( string $table, array $columns, array $formats, array $records ): void {
    global $wpdb;
    viswiz_perf_assert( count( $columns ) === count( $formats ), 'Invalid insert definition.' );
    foreach ( array_chunk( $records, 250 ) as $chunk ) {
        $placeholders = array();
        $values = array();
        foreach ( $chunk as $record ) {
            viswiz_perf_assert( count( $record ) === count( $columns ), 'Invalid record width.' );
            $placeholders[] = '(' . implode( ',', $formats ) . ')';
            array_push( $values, ...$record );
        }
        $sql = 'INSERT INTO ' . $table . ' (' . implode( ',', $columns ) . ') VALUES ' . implode( ',', $placeholders );
        $inserted = $wpdb->query( $wpdb->prepare( $sql, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        viswiz_perf_assert( (int) $inserted === count( $chunk ), 'Bulk seed failed: ' . $wpdb->last_error );
    }
}

function viswiz_perf_seed_rows( int $dataset, int $size ): void {
    $now = current_time( 'mysql', true );
    $table = Support::table( 'rows' );
    $columns = array( 'dataset_id', 'uuid', 'row_key', 'label', 'value', 'sort_order', 'created_at', 'updated_at' );
    $formats = array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
    for ( $start = 1; $start <= $size; $start += 250 ) {
        $rows = array();
        for ( $i = $start; $i <= min( $size, $start + 249 ); $i++ ) {
            $rows[] = array( $dataset, viswiz_perf_uuid( $i ), 'key-' . $i,
                'Benchmark Row ' . $i, $i % 500, $i, $now, $now );
        }
        viswiz_perf_insert( $table, $columns, $formats, $rows );
    }
}

function viswiz_perf_seed_graph( int $dataset, int $size ): void {
    $now = current_time( 'mysql', true );
    $node_table = Support::table( 'nodes' );
    $node_columns = array( 'dataset_id', 'uuid', 'slug', 'title', 'node_type', 'sort_order', 'created_at', 'updated_at' );
    $node_formats = array( '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
    for ( $start = 1; $start <= $size; $start += 250 ) {
        $records = array();
        for ( $i = $start; $i <= min( $size, $start + 249 ); $i++ ) {
            $records[] = array( $dataset, viswiz_perf_uuid( $i ), 'node-' . $i,
                'Benchmark Node ' . $i, ( $i % 2 ? 'person' : 'organization' ), $i, $now, $now );
        }
        viswiz_perf_insert( $node_table, $node_columns, $node_formats, $records );
    }

    $edge_table = Support::table( 'edges' );
    $edge_columns = array( 'dataset_id', 'uuid', 'from_node_uuid', 'to_node_uuid', 'relation_type', 'label', 'sort_order', 'created_at', 'updated_at' );
    $edge_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
    $edge_count = (int) floor( $size * 1.5 );
    for ( $start = 1; $start <= $edge_count; $start += 250 ) {
        $records = array();
        for ( $i = $start; $i <= min( $edge_count, $start + 249 ); $i++ ) {
            $from = ( ( $i - 1 ) % $size ) + 1;
            $to = ( $i % $size ) + 1;
            $records[] = array( $dataset, viswiz_perf_uuid( 1000000 + $i ),
                viswiz_perf_uuid( $from ), viswiz_perf_uuid( $to ),
                'connected_to', 'Connects ' . $from, $i, $now, $now );
        }
        viswiz_perf_insert( $edge_table, $edge_columns, $edge_formats, $records );
    }
}

function viswiz_perf_request( int $dataset, string $collection, array $args, int $expected_total, int $max_items ): array {
    $url = '/viswiz/v2/datasets/' . $dataset . '/' . $collection;
    $times = array();
    $size_bytes = 0;
    $count = 0;
    $peak_used_bytes = 0;
    $headers = array();
    for ( $sample = 0; $sample < 3; $sample++ ) {
        $request = new WP_REST_Request( 'GET', $url );
        foreach ( $args as $key => $value ) {
            $request->set_param( $key, $value );
        }
        $memory_before = memory_get_usage( false );
        $start = hrtime( true );
        $response = rest_do_request( $request );
        $times[] = round( ( hrtime( true ) - $start ) / 1000000, 2 );
        viswiz_perf_assert( 200 === $response->get_status(), 'REST ' . $collection . ' failed: ' . $response->get_status() );
        $items = $response->get_data();
        viswiz_perf_assert( is_array( $items ), 'Expected list response from ' . $collection );
        $headers = $response->get_headers();
        viswiz_perf_assert( (int) ( $headers['X-WP-Total'] ?? -1 ) === $expected_total, $collection . ' total count mismatch' );
        viswiz_perf_assert( count( $items ) <= $max_items, $collection . ' exceeded the server page limit' );
        $count = count( $items );
        $size_bytes = strlen( wp_json_encode( $items ) );
        $peak_used_bytes = max( $peak_used_bytes, memory_get_usage( false ), $memory_before );
        unset( $items, $response, $request );
    }
    sort( $times, SORT_NUMERIC );
    return array(
        'operation' => $collection,
        'args' => $args,
        'p50_ms' => $times[1],
        'max_ms' => $times[2],
        'response_bytes' => $size_bytes,
        'items' => $count,
        'memory_used_mib' => round( $peak_used_bytes / 1048576, 2 ),
    );
}

function viswiz_perf_admin_page( int $dataset ): array {
    $old_get = $_GET;
    $_GET['page'] = 'viswiz-datasets';
    $_GET['dataset_id'] = (string) $dataset;
    $start = hrtime( true );
    ob_start();
    try {
        DatasetEditorPage::page();
        $html = (string) ob_get_contents();
    } finally {
        ob_end_clean();
        $_GET = $old_get;
    }
    viswiz_perf_assert( str_contains( $html, 'data-viswiz-server-editor' ), 'Admin page missing server-side pagination marker.' );
    viswiz_perf_assert( ! str_contains( $html, 'Benchmark Node 5000' ) && ! str_contains( $html, 'Benchmark Row 50000' ), 'Full payload embedded in admin page.' );
    return array( 'operation' => 'admin_page', 'ms' => round( ( hrtime( true ) - $start ) / 1000000, 2 ), 'html_bytes' => strlen( $html ) );
}

$admin_users = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
viswiz_perf_assert( ! empty( $admin_users ), 'No administrator.' );
wp_set_current_user( (int) $admin_users[0]->ID );
$results = array();

foreach ( array( 1000, 10000, 50000 ) as $size ) {
    $dataset = viswiz_perf_dataset( 'categorical', $size );
    $start = hrtime( true );
    viswiz_perf_seed_rows( $dataset, $size );
    $seed_ms = round( ( hrtime( true ) - $start ) / 1000000, 2 );
    $results[] = array(
        'kind' => 'rows', 'n' => $size, 'seed_ms' => $seed_ms,
        'page' => viswiz_perf_request( $dataset, 'rows', array( 'page' => 1, 'per_page' => 100 ), $size, 100 ),
        'last_page' => viswiz_perf_request( $dataset, 'rows', array( 'page' => (int) ceil( $size / 100 ), 'per_page' => 100 ), $size, 100 ),
        'search' => viswiz_perf_request( $dataset, 'rows', array( 'page' => 1, 'per_page' => 100, 'search' => 'Benchmark Row ' . $size ), 1, 100 ),
        'admin' => viswiz_perf_admin_page( $dataset ),
    );
}

foreach ( array( 100, 500, 1000, 5000 ) as $size ) {
    $dataset = viswiz_perf_dataset( 'graph', $size );
    $start = hrtime( true );
    viswiz_perf_seed_graph( $dataset, $size );
    $seed_ms = round( ( hrtime( true ) - $start ) / 1000000, 2 );
    $results[] = array(
        'kind' => 'graph', 'n' => $size, 'edges' => (int) floor( $size * 1.5 ), 'seed_ms' => $seed_ms,
        'nodes' => viswiz_perf_request( $dataset, 'nodes', array( 'page' => 1, 'per_page' => 100 ), $size, 100 ),
        'node_search' => viswiz_perf_request( $dataset, 'nodes', array( 'page' => 1, 'per_page' => 100, 'search' => 'Benchmark Node ' . $size ), 1, 100 ),
        'relations' => viswiz_perf_request( $dataset, 'relations', array( 'page' => 1, 'per_page' => 100 ), (int) floor( $size * 1.5 ), 100 ),
        'relation_search' => viswiz_perf_request( $dataset, 'relations', array( 'page' => 1, 'per_page' => 100, 'search' => 'Connects ' . $size ), $size >= 2 ? 1 : 0, 100 ),
        'options' => viswiz_perf_request( $dataset, 'nodes/options', array( 'per_page' => 20, 'search' => 'Benchmark Node ' . $size ), 0, 20 ),
        'admin' => viswiz_perf_admin_page( $dataset ),
    );
}

// The node/options endpoint returns a bounded array but not a total-count header;
// it is intentionally validated separately in the main CI suite.
echo 'VISWIZ_ADMIN_PERFORMANCE_RESULTS=' . wp_json_encode( $results ) . PHP_EOL;
echo "VisWiz admin scale benchmark passed.\n";
