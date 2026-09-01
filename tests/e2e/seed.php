<?php
use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\Registry;

function viswiz_e2e_fail( string $message ): void {
    throw new RuntimeException( $message );
}

function viswiz_e2e_revision( $result, string $label ): int {
    if ( is_wp_error( $result ) ) {
        viswiz_e2e_fail( $label . ': ' . $result->get_error_code() . ' — ' . $result->get_error_message() );
    }
    return (int) ( $result['revision'] ?? 0 );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) {
    viswiz_e2e_fail( 'No administrator exists in the E2E WordPress install.' );
}
wp_set_current_user( (int) $admins[0]->ID );

$node_types = Registry::default_node_types();
$node_types['person']['subtypes'] = array(
    'journalist' => 'Journalist',
    'editor'     => 'Editor',
);
$node_types['organization']['subtypes'] = array(
    'newsroom' => 'Newsroom',
    'funder'   => 'Funder',
);
update_option( 'viswiz_node_type_schema_v2', $node_types, false );
update_option( 'viswiz_relation_type_schema_v2', Registry::default_relation_types(), false );

$repo = new DatasetRepository();
$graph_id = $repo->create( array( 'name' => 'E2E graph dataset', 'schema_type' => 'graph' ) );
if ( is_wp_error( $graph_id ) ) {
    viswiz_e2e_fail( 'Could not create E2E graph dataset: ' . $graph_id->get_error_message() );
}
$graph_id = (int) $graph_id;
$revision = 1;

$nodes = array(
    'alice' => array(
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'slug' => 'alice-reporter',
        'title' => 'Alice Reporter',
        'label' => 'Alice',
        'node_type' => 'person',
        'node_subtype' => 'journalist',
        'description' => '<p>Alice <strong>investigates</strong> public records.</p>',
        'meta' => array(
            'public_fields' => array(
                array( 'label' => 'Desk', 'type' => 'short', 'value' => 'Investigations' ),
            ),
        ),
    ),
    'bob' => array(
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'slug' => 'bob-editor',
        'title' => 'Bob Editor',
        'label' => 'Bob',
        'node_type' => 'person',
        'node_subtype' => 'editor',
        'description' => '<p>Bob edits the investigations desk.</p>',
        'meta' => array(),
    ),
    'organization' => array(
        'uuid' => '33333333-3333-4333-8333-333333333333',
        'slug' => 'organization-alpha',
        'title' => 'Organization Alpha',
        'label' => 'Alpha',
        'node_type' => 'organization',
        'node_subtype' => 'newsroom',
        'description' => '<p>Primary newsroom.</p>',
        'meta' => array(),
    ),
    'foundation' => array(
        'uuid' => '44444444-4444-4444-8444-444444444444',
        'slug' => 'foundation-beta',
        'title' => 'Foundation Beta',
        'label' => 'Beta',
        'node_type' => 'organization',
        'node_subtype' => 'funder',
        'description' => '<p>Independent foundation.</p>',
        'meta' => array(),
    ),
    'event' => array(
        'uuid' => '55555555-5555-4555-8555-555555555555',
        'slug' => 'event-gamma',
        'title' => 'Event Gamma',
        'label' => 'Gamma',
        'node_type' => 'event',
        'node_subtype' => '',
        'description' => '<p>Annual public event.</p>',
        'meta' => array(),
    ),
);

foreach ( $nodes as $node ) {
    $revision = viswiz_e2e_revision( $repo->save_node( $graph_id, $node, $revision ), 'Could not seed graph node' );
}

$relations = array(
    array(
        'uuid' => '61111111-1111-4111-8111-111111111111',
        'from_node_uuid' => $nodes['alice']['uuid'],
        'to_node_uuid' => $nodes['organization']['uuid'],
        'relation_type' => 'member_of',
        'label' => 'Member of',
        'inverse_label' => 'Has member',
        'direction' => 'directed',
        'intensity' => 1,
    ),
    array(
        'uuid' => '62222222-2222-4222-8222-222222222222',
        'from_node_uuid' => $nodes['bob']['uuid'],
        'to_node_uuid' => $nodes['organization']['uuid'],
        'relation_type' => 'leader_of',
        'label' => 'Leader of',
        'inverse_label' => 'Led by',
        'direction' => 'directed',
        'intensity' => 1,
    ),
    array(
        'uuid' => '63333333-3333-4333-8333-333333333333',
        'from_node_uuid' => $nodes['organization']['uuid'],
        'to_node_uuid' => $nodes['foundation']['uuid'],
        'relation_type' => 'connected_to',
        'label' => 'Connected to',
        'inverse_label' => 'Connected to',
        'direction' => 'undirected',
        'intensity' => 1,
    ),
    array(
        'uuid' => '64444444-4444-4444-8444-444444444444',
        'from_node_uuid' => $nodes['alice']['uuid'],
        'to_node_uuid' => $nodes['event']['uuid'],
        'relation_type' => 'participated_in',
        'label' => 'Participated in',
        'inverse_label' => 'Had participant',
        'direction' => 'directed',
        'intensity' => 1,
    ),
    array(
        'uuid' => '65555555-5555-4555-8555-555555555555',
        'from_node_uuid' => $nodes['bob']['uuid'],
        'to_node_uuid' => $nodes['event']['uuid'],
        'relation_type' => 'participated_in',
        'label' => 'Participated in',
        'inverse_label' => 'Had participant',
        'direction' => 'directed',
        'intensity' => 1,
    ),
    array(
        'uuid' => '66666666-6666-4666-8666-666666666666',
        'from_node_uuid' => $nodes['foundation']['uuid'],
        'to_node_uuid' => $nodes['event']['uuid'],
        'relation_type' => 'connected_to',
        'label' => 'Connected to',
        'inverse_label' => 'Connected to',
        'direction' => 'undirected',
        'intensity' => 1,
    ),
);

foreach ( $relations as $relation ) {
    $revision = viswiz_e2e_revision( $repo->save_edge( $graph_id, $relation, $revision ), 'Could not seed graph relation' );
}

$row_id = $repo->create( array( 'name' => 'E2E categorical dataset', 'schema_type' => 'categorical' ) );
if ( is_wp_error( $row_id ) ) {
    viswiz_e2e_fail( 'Could not create E2E row dataset: ' . $row_id->get_error_message() );
}
$row_id = (int) $row_id;
$row_revision = 1;
$row_revision = viswiz_e2e_revision(
    $repo->save_row(
        $row_id,
        array(
            'uuid' => '71111111-1111-4111-8111-111111111111',
            'row_key' => 'alpha',
            'label' => 'Alpha row',
            'value' => 10,
            'color' => '#2563eb',
            'meta' => array(),
        ),
        $row_revision
    ),
    'Could not seed row Alpha'
);
$row_revision = viswiz_e2e_revision(
    $repo->save_row(
        $row_id,
        array(
            'uuid' => '72222222-2222-4222-8222-222222222222',
            'row_key' => 'beta',
            'label' => 'Beta row',
            'value' => 20,
            'color' => '#7c3aed',
            'meta' => array(),
        ),
        $row_revision
    ),
    'Could not seed row Beta'
);

$visualization_id = wp_insert_post(
    array(
        'post_type' => 'viswiz_visualization',
        'post_title' => 'E2E graph visualization',
        'post_status' => 'publish',
    ),
    true
);
if ( is_wp_error( $visualization_id ) ) {
    viswiz_e2e_fail( 'Could not create E2E visualization: ' . $visualization_id->get_error_message() );
}
$visualization_id = (int) $visualization_id;
update_post_meta( $visualization_id, '_viswiz_renderer', 'graph' );
update_post_meta( $visualization_id, '_viswiz_source_type', 'dataset' );
update_post_meta( $visualization_id, '_viswiz_dataset_id', $graph_id );
update_post_meta(
    $visualization_id,
    '_viswiz_settings',
    array(
        'title' => 'E2E Graph',
        'full_screen' => true,
        'show_graph_toolbar' => true,
        'show_graph_search' => true,
        'show_graph_filters' => true,
        'show_graph_zoom' => true,
        'show_node_images' => true,
        'show_type_badges' => true,
        'show_relation_labels' => true,
        'node_modal_related_heading' => 'Connections',
    )
);

$page_id = wp_insert_post(
    array(
        'post_type' => 'page',
        'post_title' => 'VisWiz E2E public page',
        'post_status' => 'publish',
        'post_content' => sprintf(
            '<h1>VisWiz E2E public page</h1>\n[viswiz_visualization id="%1$d"]\n<hr>\n[viswiz_visualization id="%1$d"]',
            $visualization_id
        ),
    ),
    true
);
if ( is_wp_error( $page_id ) ) {
    viswiz_e2e_fail( 'Could not create E2E page: ' . $page_id->get_error_message() );
}
$page_id = (int) $page_id;

$block_page_content = sprintf(
    '<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">\n<!-- wp:heading {"level":2} -->\n<h2 class="wp-block-heading">VisWiz Gutenberg block fixture</h2>\n<!-- /wp:heading -->\n<!-- wp:viswiz/visualization {"visualizationId":%1$d} /-->\n<!-- wp:separator -->\n<hr class="wp-block-separator has-alpha-channel-opacity"/>\n<!-- /wp:separator -->\n<!-- wp:viswiz/visualization {"visualizationId":%1$d} /-->\n</div>\n<!-- /wp:group -->',
    $visualization_id
);
$block_page_id = wp_insert_post(
    array(
        'post_type'    => 'page',
        'post_title'   => 'VisWiz E2E Gutenberg page',
        'post_status'  => 'publish',
        'post_content' => $block_page_content,
    ),
    true
);
if ( is_wp_error( $block_page_id ) ) {
    viswiz_e2e_fail( 'Could not create E2E Gutenberg page: ' . $block_page_id->get_error_message() );
}
$block_page_id = (int) $block_page_id;

$fixture = array(
    'graphDatasetId' => $graph_id,
    'rowDatasetId' => $row_id,
    'visualizationId' => $visualization_id,
    'pageId' => $page_id,
    'blockPageId' => $block_page_id,
    'graphRevision' => $revision,
    'rowRevision' => $row_revision,
    'nodeUuids' => array(
        'alice' => $nodes['alice']['uuid'],
        'bob' => $nodes['bob']['uuid'],
        'organization' => $nodes['organization']['uuid'],
        'foundation' => $nodes['foundation']['uuid'],
        'event' => $nodes['event']['uuid'],
    ),
    'counts' => array(
        'nodes' => count( $nodes ),
        'relations' => count( $relations ),
        'rows' => 2,
    ),
);

$fixture_path = getenv( 'VISWIZ_E2E_FIXTURE' ) ?: '/tmp/viswiz-e2e-fixture.json';
if ( false === file_put_contents( $fixture_path, wp_json_encode( $fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) ) {
    viswiz_e2e_fail( 'Could not write E2E fixture file.' );
}

echo 'VisWiz E2E fixture written to ' . $fixture_path . "\n";
