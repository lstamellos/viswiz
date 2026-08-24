<?php
/**
 * Plugin Name: VisWiz WooCommerce Visualizer
 * Description: Real-time progress bars, charts, diagrams, and graph visualizations based on WooCommerce sales, custom datasets, or manual inputs.
 * Version: 1.7.5
 * Author: cremedia.studio
 * Update URI: https://github.com/lstamellos/viswiz
 * Text Domain: viswiz
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const VISWIZ_VERSION = '1.7.5';
const VISWIZ_OPTION_TARGET = 'viswiz_sales_target';
const VISWIZ_OPTION_PROGRESS_MANUAL = 'viswiz_manual_progress';
const VISWIZ_OPTION_PIE_MANUAL = 'viswiz_manual_pie';
const VISWIZ_OPTION_DIAGRAM = 'viswiz_diagram_data';
const VISWIZ_OPTION_GRAPH = 'viswiz_graph_data';
const VISWIZ_OPTION_SALES_SCOPE = 'viswiz_sales_scope';
const VISWIZ_OPTION_SALES_PERIOD_VALUE = 'viswiz_sales_period_value';
const VISWIZ_OPTION_SALES_PERIOD_UNIT = 'viswiz_sales_period_unit';
const VISWIZ_OPTION_SALES_PERIOD_MODE = 'viswiz_sales_period_mode';
const VISWIZ_OPTION_SALES_PERIOD_START = 'viswiz_sales_period_start';
const VISWIZ_OPTION_SALES_PRODUCTS = 'viswiz_sales_product_ids';
const VISWIZ_OPTION_CURRENCY = 'viswiz_currency_code';
const VISWIZ_OPTION_SALES_CATEGORIES = 'viswiz_sales_category_ids';
const VISWIZ_OPTION_NODE_TYPE_SCHEMA = 'viswiz_node_type_schema';
const VISWIZ_OPTION_RELATION_TYPE_SCHEMA = 'viswiz_relation_type_schema';
const VISWIZ_OPTION_DISPLAY_DEFAULTS = 'viswiz_display_defaults';

require_once __DIR__ . '/includes/viswiz-updater.php';

register_activation_hook( __FILE__, 'viswiz_activate' );
add_action( 'plugins_loaded', 'viswiz_load_textdomain' );
add_action( 'init', 'viswiz_register_shortcodes' );
add_action( 'rest_api_init', 'viswiz_register_rest_routes' );
add_action( 'wp_enqueue_scripts', 'viswiz_enqueue_assets' );
add_action( 'admin_menu', 'viswiz_register_admin_menu' );
add_action( 'admin_post_viswiz_create_dataset', 'viswiz_admin_create_dataset' );
add_action( 'admin_post_viswiz_delete_dataset', 'viswiz_admin_delete_dataset' );
add_action( 'admin_post_viswiz_export_dataset', 'viswiz_admin_export_dataset' );
add_action( 'admin_post_viswiz_save_node_type_schema', 'viswiz_admin_save_node_type_schema' );
add_action( 'admin_post_viswiz_save_relation_type_schema', 'viswiz_admin_save_relation_type_schema' );
add_action( 'admin_init', 'viswiz_register_settings' );
add_action( 'admin_init', 'viswiz_maybe_upgrade_tables' );
add_action( 'init', 'viswiz_register_visualizations_cpt' );
add_action( 'init', 'viswiz_register_block_assets' );
add_action( 'add_meta_boxes', 'viswiz_register_visualization_meta_box' );
add_action( 'save_post_viswiz_visualization', 'viswiz_save_visualization_meta' );
add_filter( 'redirect_post_location', 'viswiz_redirect_to_active_visualization_tab', 10, 2 );
add_action( 'admin_enqueue_scripts', 'viswiz_enqueue_admin_assets' );
add_action( 'wp_ajax_viswiz_autosave_node_type', 'viswiz_ajax_autosave_node_type' );
add_action( 'wp_ajax_viswiz_autosave_graph_node', 'viswiz_ajax_autosave_graph_node' );
add_action( 'wp_ajax_viswiz_register_node_subtype', 'viswiz_ajax_register_node_subtype' );
add_filter( 'manage_viswiz_visualization_posts_columns', 'viswiz_add_visualization_columns' );
add_action( 'manage_viswiz_visualization_posts_custom_column', 'viswiz_render_visualization_columns', 10, 2 );



function viswiz_load_textdomain() {
    load_plugin_textdomain( 'viswiz', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}

function viswiz_activate() {
    viswiz_create_custom_tables();
    viswiz_register_visualizations_cpt();
    flush_rewrite_rules();
}

function viswiz_create_custom_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $charset_collate = $wpdb->get_charset_collate();
    $visualizations = $wpdb->prefix . 'viswiz_visualization_data';
    $datasets = $wpdb->prefix . 'viswiz_datasets';
    $points = $wpdb->prefix . 'viswiz_data_points';
    $relations = $wpdb->prefix . 'viswiz_relations';

    dbDelta( "CREATE TABLE $visualizations (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        post_id bigint(20) unsigned NOT NULL,
        visualization_type varchar(40) NOT NULL DEFAULT 'progress',
        dataset_id bigint(20) unsigned NOT NULL DEFAULT 0,
        legend longtext NULL,
        labels longtext NULL,
        theme longtext NULL,
        settings longtext NULL,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY post_id (post_id),
        KEY visualization_type (visualization_type),
        KEY dataset_id (dataset_id)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $datasets (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        name varchar(190) NOT NULL,
        description text NULL,
        data_type varchar(40) NOT NULL DEFAULT 'generic',
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY data_type (data_type)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $points (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        visualization_id bigint(20) unsigned NOT NULL DEFAULT 0,
        dataset_id bigint(20) unsigned NOT NULL DEFAULT 0,
        point_key varchar(190) NOT NULL DEFAULT '',
        label varchar(190) NOT NULL DEFAULT '',
        value double NOT NULL DEFAULT 0,
        color varchar(20) NOT NULL DEFAULT '',
        meta longtext NULL,
        sort_order int(11) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY visualization_id (visualization_id),
        KEY dataset_id (dataset_id),
        KEY point_key (point_key)
    ) $charset_collate;" );

    dbDelta( "CREATE TABLE $relations (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        visualization_id bigint(20) unsigned NOT NULL DEFAULT 0,
        dataset_id bigint(20) unsigned NOT NULL DEFAULT 0,
        from_key varchar(190) NOT NULL DEFAULT '',
        to_key varchar(190) NOT NULL DEFAULT '',
        label varchar(190) NOT NULL DEFAULT '',
        direction varchar(20) NOT NULL DEFAULT 'directed',
        intensity double NOT NULL DEFAULT 1,
        relation_type varchar(80) NOT NULL DEFAULT '',
        meta longtext NULL,
        sort_order int(11) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY visualization_id (visualization_id),
        KEY dataset_id (dataset_id),
        KEY from_key (from_key),
        KEY to_key (to_key)
    ) $charset_collate;" );

    update_option( 'viswiz_db_version', VISWIZ_VERSION );
}

function viswiz_get_table_name( $table ) {
    global $wpdb;
    return $wpdb->prefix . 'viswiz_' . $table;
}

function viswiz_get_supported_visualization_types() {
    return array(
        'pie' => __( 'Pie', 'viswiz' ),
        'bar' => __( 'Bar', 'viswiz' ),
        'column' => __( 'Column', 'viswiz' ),
        'line' => __( 'Line', 'viswiz' ),
        'area' => __( 'Area', 'viswiz' ),
        'scatter' => __( 'Scatter', 'viswiz' ),
        'progress' => __( 'Progress', 'viswiz' ),
        'counter' => __( 'Counter', 'viswiz' ),
        'timeline' => __( 'Timeline', 'viswiz' ),
        'graph' => __( 'Graph', 'viswiz' ),
        'flow_diagram' => __( 'Flow Diagram', 'viswiz' ),
        'org_chart' => __( 'Org Chart', 'viswiz' ),
        'map' => __( 'Map', 'viswiz' ),
        'diagram' => __( 'Diagram (legacy)', 'viswiz' ),
    );
}

function viswiz_get_chart_like_types() {
    return array( 'pie', 'bar', 'column', 'line', 'area', 'scatter', 'counter', 'timeline', 'map' );
}

function viswiz_get_diagram_like_types() {
    return array( 'diagram' );
}

function viswiz_is_chart_like_type( $type ) {
    return in_array( $type, viswiz_get_chart_like_types(), true );
}

function viswiz_is_diagram_like_type( $type ) {
    return in_array( $type, viswiz_get_diagram_like_types(), true );
}

function viswiz_is_graph_like_type( $type ) {
    return in_array( $type, array( 'graph', 'flow_diagram', 'org_chart' ), true );
}

function viswiz_maybe_upgrade_tables() {
    if ( get_option( 'viswiz_db_version' ) !== VISWIZ_VERSION ) {
        viswiz_create_custom_tables();
    }
}

function viswiz_register_shortcodes() {
    add_shortcode( 'viswiz_progress', 'viswiz_progress_shortcode' );
    add_shortcode( 'viswiz_pie', 'viswiz_pie_shortcode' );
    add_shortcode( 'viswiz_diagram', 'viswiz_diagram_shortcode' );
    add_shortcode( 'viswiz_graph', 'viswiz_graph_shortcode' );
    add_shortcode( 'viswiz_visualization', 'viswiz_visualization_shortcode' );
}

function viswiz_enqueue_assets() {
    wp_register_style(
        'viswiz-style',
        plugins_url( 'assets/viswiz.css', __FILE__ ),
        array(),
        VISWIZ_VERSION
    );
    viswiz_register_d3_script();
    wp_register_script(
        'viswiz-script',
        plugins_url( 'assets/viswiz.js', __FILE__ ),
        array( 'd3' ),
        VISWIZ_VERSION,
        true
    );

    wp_enqueue_style( 'viswiz-style' );
    wp_enqueue_script( 'viswiz-script' );

    wp_localize_script(
        'viswiz-script',
        'VisWizData',
        array(
            'restUrl' => esc_url_raw( rest_url( 'viswiz/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'target' => (float) get_option( VISWIZ_OPTION_TARGET, 0 ),
            'manualProgress' => viswiz_get_manual_progress(),
            'manualPie' => viswiz_get_manual_pie(),
            'diagramData' => viswiz_get_diagram_data(),
            'graphData' => viswiz_prepare_graph_data_for_display( viswiz_get_graph_data() ),
            'salesScope' => get_option( VISWIZ_OPTION_SALES_SCOPE, 'total' ),
            'salesPeriodValue' => (int) get_option( VISWIZ_OPTION_SALES_PERIOD_VALUE, 30 ),
            'salesPeriodUnit' => viswiz_get_period_unit_option(),
            'salesPeriodMode' => viswiz_get_period_mode_option(),
            'salesPeriodStart' => get_option( VISWIZ_OPTION_SALES_PERIOD_START, '' ),
            'currencyCode' => viswiz_get_currency_code(),
            'currencySymbol' => viswiz_get_currency_symbol(),
            'salesProduct' => viswiz_get_sales_product_ids(),
            'salesCategory' => viswiz_get_sales_category_ids(),
        )
    );
}

function viswiz_register_d3_script() {
    if ( wp_script_is( 'd3', 'registered' ) ) {
        return;
    }

    wp_register_script(
        'd3',
        'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js',
        array(),
        '7.9.0',
        true
    );
}

function viswiz_register_rest_routes() {
    register_rest_route(
        'viswiz/v1',
        '/sales',
        array(
            'methods' => 'GET',
            'callback' => 'viswiz_get_sales_data',
            'permission_callback' => '__return_true',
        )
    );
    register_rest_route(
        'viswiz/v1',
        '/sales-breakdown',
        array(
            'methods' => 'GET',
            'callback' => 'viswiz_get_sales_breakdown',
            'permission_callback' => '__return_true',
        )
    );
    register_rest_route(
        'viswiz/v1',
        '/sales-status',
        array(
            'methods' => 'GET',
            'callback' => 'viswiz_get_sales_status_data',
            'permission_callback' => '__return_true',
        )
    );
    register_rest_route(
        'viswiz/v1',
        '/visualizations',
        array(
            'methods' => 'GET',
            'callback' => 'viswiz_get_visualizations',
            'permission_callback' => 'viswiz_can_access_visualizations',
        )
    );
}

function viswiz_get_sales_data( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'viswiz_no_woocommerce', 'WooCommerce is not active.' );
    }

    $scope = sanitize_text_field( $request->get_param( 'scope' ) );
    if ( $scope === '' ) {
        $scope = get_option( VISWIZ_OPTION_SALES_SCOPE, 'total' );
    }

    $period_spec = viswiz_get_period_spec_from_request( $request );
    $period_mode = $period_spec[0];

    $product_ids = viswiz_parse_id_list( $request->get_param( 'product_ids' ) );
    if ( empty( $product_ids ) ) {
        $product_ids = viswiz_get_sales_product_ids();
    }

    $category_ids = viswiz_parse_id_list( $request->get_param( 'category_ids' ) );
    if ( empty( $category_ids ) ) {
        $category_ids = viswiz_get_sales_category_ids();
    }

    if ( $period_mode === 'fixed' ) {
        $orders = viswiz_get_orders_for_fixed_period( $period_spec[1] );
    } else {
        $orders = viswiz_get_orders_for_period( $period_spec[1], $period_spec[2] );
    }
    $total_sales = 0.0;
    $order_count = 0;

    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            continue;
        }

        if ( $scope === 'product' && ! empty( $product_ids ) ) {
            $total_sales += viswiz_get_order_total_for_products( $order, $product_ids );
            $order_count++;
            continue;
        }

        if ( $scope === 'category' && ! empty( $category_ids ) ) {
            $total_sales += viswiz_get_order_total_for_category( $order, $category_ids );
            $order_count++;
            continue;
        }

        $total_sales += (float) $order->get_total();
        $order_count++;
    }

    return array(
        'totalSales' => $total_sales,
        'orderCount' => $order_count,
    );
}

function viswiz_get_sales_status_data( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'viswiz_no_woocommerce', 'WooCommerce is not active.' );
    }

    $period_spec = viswiz_get_period_spec_from_request( $request );
    $period_mode = $period_spec[0];

    $statuses = wc_get_order_statuses();
    $counts = array();

    foreach ( $statuses as $status_key => $label ) {
        $date_created = $period_mode === 'fixed'
            ? viswiz_get_fixed_period_start_date( $period_spec[1] )
            : viswiz_get_period_start_date( $period_spec[1], $period_spec[2] );
        $orders = wc_get_orders(
            array(
                'status' => array( $status_key ),
                'limit' => -1,
                'date_created' => '>' . $date_created,
                'return' => 'ids',
            )
        );
        $counts[] = array(
            'label' => $label,
            'value' => count( $orders ),
        );
    }

    return array(
        'statusCounts' => $counts,
    );
}


function viswiz_get_public_display_data_attrs( $format_colors = null, $include_graph_attrs = false ) {
    $format = viswiz_sanitize_format_colors( is_array( $format_colors ) ? $format_colors : array(), viswiz_get_display_defaults() );
    $attrs = sprintf(
        'data-colors="%s" data-show-fullscreen-toggle="%s"',
        esc_attr( viswiz_json_encode( $format ) ),
        empty( $format['show_fullscreen_toggle'] ) ? '0' : '1'
    );
    if ( $include_graph_attrs ) {
        $attrs .= sprintf(
            ' data-node-radius="%s" data-link-distance="%s" data-charge-strength="%s" data-node-style="%s" data-node-label-style="%s" data-node-card-width="%s" data-scale-nodes-by-relations="%s" data-relation-size-step="%s" data-max-relation-size-boost="%s" data-show-node-images="%s" data-show-type-badges="%s" data-show-graph-toolbar="%s" data-show-graph-search="%s" data-show-graph-filters="%s" data-show-graph-zoom="%s" data-show-relation-labels="%s" data-graph-filter-mode="%s"',
            esc_attr( $format['node_radius'] ),
            esc_attr( $format['link_distance'] ),
            esc_attr( $format['charge_strength'] ),
            esc_attr( $format['node_style'] ),
            esc_attr( $format['node_label_style'] ),
            esc_attr( $format['node_card_width'] ),
            empty( $format['scale_nodes_by_relations'] ) ? '0' : '1',
            esc_attr( $format['relation_size_step'] ),
            esc_attr( $format['max_relation_size_boost'] ),
            empty( $format['show_node_images'] ) ? '0' : '1',
            empty( $format['show_type_badges'] ) ? '0' : '1',
            empty( $format['show_graph_toolbar'] ) ? '0' : '1',
            empty( $format['show_graph_search'] ) ? '0' : '1',
            empty( $format['show_graph_filters'] ) ? '0' : '1',
            empty( $format['show_graph_zoom'] ) ? '0' : '1',
            empty( $format['show_relation_labels'] ) ? '0' : '1',
            esc_attr( $format['graph_filter_mode'] )
        );
    }
    return $attrs;
}

function viswiz_progress_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'type' => 'auto',
            'label' => 'Sales Progress',
            'target' => '',
            'scope' => '',
            'period_mode' => '',
            'period_value' => '',
            'period_unit' => '',
            'period_start' => '',
            'product_ids' => '',
            'category_ids' => '',
        ),
        $atts,
        'viswiz_progress'
    );

    $target = $atts['target'] !== '' ? (float) $atts['target'] : (float) get_option( VISWIZ_OPTION_TARGET, 0 );

    return sprintf(
        '<div class="viswiz-progress" data-type="%s" data-label="%s" data-target="%s" data-scope="%s" data-period-mode="%s" data-period-value="%s" data-period-unit="%s" data-period-start="%s" data-product-ids="%s" data-category-ids="%s" %s></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['label'] ),
        esc_attr( $target ),
        esc_attr( $atts['scope'] ),
        esc_attr( $atts['period_mode'] ),
        esc_attr( $atts['period_value'] ),
        esc_attr( $atts['period_unit'] ),
        esc_attr( $atts['period_start'] ),
        esc_attr( $atts['product_ids'] ),
        esc_attr( $atts['category_ids'] ),
        viswiz_get_public_display_data_attrs()
    );
}

function viswiz_pie_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'type' => 'auto',
            'title' => 'Sales Breakdown',
            'scope' => '',
            'period_mode' => '',
            'period_value' => '',
            'period_unit' => '',
            'period_start' => '',
            'product_ids' => '',
            'category_ids' => '',
        ),
        $atts,
        'viswiz_pie'
    );

    return sprintf(
        '<div class="viswiz-pie" data-type="%s" data-title="%s" data-scope="%s" data-period-mode="%s" data-period-value="%s" data-period-unit="%s" data-period-start="%s" data-product-ids="%s" data-category-ids="%s" %s></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['title'] ),
        esc_attr( $atts['scope'] ),
        esc_attr( $atts['period_mode'] ),
        esc_attr( $atts['period_value'] ),
        esc_attr( $atts['period_unit'] ),
        esc_attr( $atts['period_start'] ),
        esc_attr( $atts['product_ids'] ),
        esc_attr( $atts['category_ids'] ),
        viswiz_get_public_display_data_attrs()
    );
}

function viswiz_diagram_shortcode() {
    return sprintf( '<div class="viswiz-diagram" %s></div>', viswiz_get_public_display_data_attrs() );
}

function viswiz_graph_shortcode() {
    return sprintf( '<div class="viswiz-graph" %s></div>', viswiz_get_public_display_data_attrs( null, true ) );
}

function viswiz_visualization_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'id' => '',
        ),
        $atts,
        'viswiz_visualization'
    );

    $post_id = (int) $atts['id'];
    if ( $post_id <= 0 ) {
        return '<div class="viswiz-message">No visualization selected.</div>';
    }

    return viswiz_render_visualization( $post_id );
}

function viswiz_register_admin_menu() {
    add_menu_page(
        'VisWiz',
        'VisWiz',
        'edit_posts',
        'viswiz',
        'viswiz_render_dashboard_page',
        'dashicons-chart-pie',
        56
    );

    add_submenu_page(
        'viswiz',
        'Dashboard',
        'Dashboard',
        'edit_posts',
        'viswiz',
        'viswiz_render_dashboard_page'
    );

    add_submenu_page(
        'viswiz',
        'Visualizations',
        'Visualizations',
        'edit_posts',
        'edit.php?post_type=viswiz_visualization'
    );

    add_submenu_page(
        'viswiz',
        'Add New Visualization',
        'Add New Visualization',
        'edit_posts',
        'post-new.php?post_type=viswiz_visualization'
    );

    add_submenu_page(
        'viswiz',
        'Datasets',
        'Datasets',
        'edit_posts',
        'viswiz-datasets',
        'viswiz_render_datasets_page'
    );

    add_submenu_page(
        'viswiz',
        'Node Types',
        'Node Types',
        'manage_options',
        'viswiz-node-types',
        'viswiz_render_node_types_page'
    );

    add_submenu_page(
        'viswiz',
        'Relation Types',
        'Relation Types',
        'manage_options',
        'viswiz-relation-types',
        'viswiz_render_relation_types_page'
    );

    add_submenu_page(
        'viswiz',
        'Settings',
        'Settings',
        'manage_options',
        'viswiz-settings',
        'viswiz_render_settings_page'
    );
}

function viswiz_get_admin_dataset_stats() {
    global $wpdb;
    viswiz_create_custom_tables();
    $datasets_table = viswiz_get_table_name( 'datasets' );
    $points_table = viswiz_get_table_name( 'data_points' );
    $relations_table = viswiz_get_table_name( 'relations' );
    $visualizations_table = viswiz_get_table_name( 'visualization_data' );

    return $wpdb->get_results(
        "SELECT d.*, 
            COUNT(DISTINCT p.id) AS point_count,
            COUNT(DISTINCT r.id) AS relation_count,
            COUNT(DISTINCT v.post_id) AS visualization_count
        FROM $datasets_table d
        LEFT JOIN $points_table p ON p.dataset_id = d.id
        LEFT JOIN $relations_table r ON r.dataset_id = d.id
        LEFT JOIN $visualizations_table v ON v.dataset_id = d.id
        GROUP BY d.id
        ORDER BY d.updated_at DESC, d.name ASC"
    );
}

function viswiz_render_dashboard_page() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'You do not have permission to access VisWiz.', 'viswiz' ) );
    }

    global $wpdb;
    viswiz_create_custom_tables();
    $dataset_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . viswiz_get_table_name( 'datasets' ) );
    $point_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . viswiz_get_table_name( 'data_points' ) );
    $relation_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . viswiz_get_table_name( 'relations' ) );
    $viz_counts = wp_count_posts( 'viswiz_visualization' );
    $published_viz = isset( $viz_counts->publish ) ? (int) $viz_counts->publish : 0;
    ?>
    <div class="wrap viswiz-admin-page viswiz-dashboard-page">
        <h1>VisWiz</h1>
        <p class="viswiz-page-intro">Build reusable datasets first, then map them to visualizations and publish them with blocks or shortcodes.</p>
        <div class="viswiz-admin-kpis">
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( $published_viz ); ?></strong><span>Published visualizations</span></div>
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( $dataset_count ); ?></strong><span>Reusable datasets</span></div>
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( $point_count ); ?></strong><span>Data points / nodes</span></div>
            <div class="viswiz-admin-kpi"><strong><?php echo esc_html( $relation_count ); ?></strong><span>Relations</span></div>
        </div>
        <div class="viswiz-admin-action-grid">
            <a class="viswiz-admin-action-card" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=viswiz_visualization' ) ); ?>">
                <strong>Create visualization</strong>
                <span>Choose a type, source, formatting, preview, and embed.</span>
            </a>
            <a class="viswiz-admin-action-card" href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>">
                <strong>Manage datasets</strong>
                <span>Review reusable datasets, point counts, relation counts, and exports.</span>
            </a>
            <a class="viswiz-admin-action-card" href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-node-types' ) ); ?>">
                <strong>Configure node types</strong>
                <span>Maintain the editorial schema used by graph visualizations.</span>
            </a>
            <a class="viswiz-admin-action-card" href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-relation-types' ) ); ?>">
                <strong>Configure relation types</strong>
                <span>Keep relation labels consistent across investigations and datasets.</span>
            </a>
        </div>
    </div>
    <?php
}

function viswiz_render_datasets_page() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'You do not have permission to manage VisWiz datasets.', 'viswiz' ) );
    }

    $datasets = viswiz_get_admin_dataset_stats();
    $created = isset( $_GET['created'] );
    $deleted = isset( $_GET['deleted'] );
    ?>
    <div class="wrap viswiz-admin-page viswiz-datasets-page">
        <h1>Datasets</h1>
        <p class="viswiz-page-intro">Datasets are reusable data stores. A dataset can feed one or more visualizations, including graphs with node and relation records.</p>
        <?php if ( $created ) : ?><div class="notice notice-success is-dismissible"><p>Dataset created.</p></div><?php endif; ?>
        <?php if ( $deleted ) : ?><div class="notice notice-success is-dismissible"><p>Dataset deleted.</p></div><?php endif; ?>
        <div class="viswiz-admin-two-column">
            <div class="viswiz-admin-panel">
                <h2>Existing datasets</h2>
                <table class="widefat striped viswiz-dataset-table">
                    <thead><tr><th>Name</th><th>Type</th><th>Points / nodes</th><th>Relations</th><th>Used by</th><th>Updated</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if ( empty( $datasets ) ) : ?>
                        <tr><td colspan="7">No reusable datasets yet. Create one here or from a visualization editor.</td></tr>
                    <?php else : ?>
                        <?php foreach ( $datasets as $dataset ) : ?>
                            <?php
                            $export_url = wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_export_dataset&dataset_id=' . absint( $dataset->id ) ), 'viswiz_export_dataset_' . absint( $dataset->id ) );
                            $delete_url = wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_delete_dataset&dataset_id=' . absint( $dataset->id ) ), 'viswiz_delete_dataset_' . absint( $dataset->id ) );
                            ?>
                            <tr>
                                <td><strong><?php echo esc_html( $dataset->name ); ?></strong><br /><span class="description">#<?php echo esc_html( $dataset->id ); ?> <?php echo $dataset->description ? ' · ' . esc_html( wp_trim_words( $dataset->description, 14 ) ) : ''; ?></span></td>
                                <td><code><?php echo esc_html( $dataset->data_type ); ?></code></td>
                                <td><?php echo esc_html( (int) $dataset->point_count ); ?></td>
                                <td><?php echo esc_html( (int) $dataset->relation_count ); ?></td>
                                <td><?php echo esc_html( (int) $dataset->visualization_count ); ?></td>
                                <td><?php echo esc_html( $dataset->updated_at ); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=viswiz_visualization&viswiz_dataset_id=' . absint( $dataset->id ) ) ); ?>">Use</a>
                                    <a class="button button-small" href="<?php echo esc_url( $export_url ); ?>">Export JSON</a>
                                    <a class="button button-small button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('Delete this dataset and its stored points/relations? Visualizations using it will fall back to visualization-specific data.');">Delete</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="viswiz-admin-panel viswiz-admin-side-panel">
                <h2>Create dataset</h2>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="viswiz_create_dataset" />
                    <?php wp_nonce_field( 'viswiz_create_dataset' ); ?>
                    <p><label for="viswiz_dataset_name">Name</label><input type="text" id="viswiz_dataset_name" name="dataset_name" class="regular-text" required /></p>
                    <p><label for="viswiz_dataset_type">Data type</label>
                        <select id="viswiz_dataset_type" name="dataset_type">
                            <?php foreach ( viswiz_get_supported_visualization_types() as $type_key => $type_label ) : ?>
                                <option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
                            <?php endforeach; ?>
                            <option value="generic">Generic</option>
                        </select>
                    </p>
                    <p><label for="viswiz_dataset_description">Description</label><textarea id="viswiz_dataset_description" name="dataset_description" class="large-text" rows="4"></textarea></p>
                    <?php submit_button( 'Create dataset', 'primary', 'submit', false ); ?>
                </form>
                <hr />
                <h2>Recommended workflow</h2>
                <ol>
                    <li>Create or select a reusable dataset.</li>
                    <li>Add a visualization and connect it to that dataset.</li>
                    <li>Edit rows, nodes, and relations from the visualization builder.</li>
                    <li>Export the dataset JSON before major editorial revisions.</li>
                </ol>
            </div>
        </div>
    </div>
    <?php
}

function viswiz_admin_create_dataset() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    check_admin_referer( 'viswiz_create_dataset' );
    global $wpdb;
    viswiz_create_custom_tables();
    $now = current_time( 'mysql' );
    $name = sanitize_text_field( wp_unslash( $_POST['dataset_name'] ?? '' ) );
    if ( $name === '' ) {
        wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets' ) );
        exit;
    }
    $wpdb->insert(
        viswiz_get_table_name( 'datasets' ),
        array(
            'name' => $name,
            'description' => sanitize_textarea_field( wp_unslash( $_POST['dataset_description'] ?? '' ) ),
            'data_type' => sanitize_key( wp_unslash( $_POST['dataset_type'] ?? 'generic' ) ),
            'created_at' => $now,
            'updated_at' => $now,
        ),
        array( '%s', '%s', '%s', '%s', '%s' )
    );
    wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&created=1' ) );
    exit;
}

function viswiz_admin_delete_dataset() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    $dataset_id = absint( $_GET['dataset_id'] ?? 0 );
    check_admin_referer( 'viswiz_delete_dataset_' . $dataset_id );
    if ( $dataset_id ) {
        global $wpdb;
        $wpdb->delete( viswiz_get_table_name( 'datasets' ), array( 'id' => $dataset_id ), array( '%d' ) );
        $wpdb->delete( viswiz_get_table_name( 'data_points' ), array( 'dataset_id' => $dataset_id ), array( '%d' ) );
        $wpdb->delete( viswiz_get_table_name( 'relations' ), array( 'dataset_id' => $dataset_id ), array( '%d' ) );
        $wpdb->query( $wpdb->prepare( "UPDATE " . viswiz_get_table_name( 'visualization_data' ) . " SET dataset_id = 0 WHERE dataset_id = %d", $dataset_id ) );
    }
    wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&deleted=1' ) );
    exit;
}

function viswiz_admin_export_dataset() {
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    $dataset_id = absint( $_GET['dataset_id'] ?? 0 );
    check_admin_referer( 'viswiz_export_dataset_' . $dataset_id );
    global $wpdb;
    $dataset = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . viswiz_get_table_name( 'datasets' ) . " WHERE id = %d", $dataset_id ), ARRAY_A );
    if ( ! $dataset ) {
        wp_die( esc_html__( 'Dataset not found.', 'viswiz' ) );
    }
    $payload = array(
        'dataset' => $dataset,
        'points' => $wpdb->get_results( $wpdb->prepare( "SELECT point_key, label, value, color, meta, sort_order FROM " . viswiz_get_table_name( 'data_points' ) . " WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC", $dataset_id ), ARRAY_A ),
        'relations' => $wpdb->get_results( $wpdb->prepare( "SELECT from_key, to_key, label, direction, intensity, relation_type, meta, sort_order FROM " . viswiz_get_table_name( 'relations' ) . " WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC", $dataset_id ), ARRAY_A ),
        'exported_at' => current_time( 'mysql' ),
        'version' => VISWIZ_VERSION,
    );
    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="viswiz-dataset-' . $dataset_id . '.json"' );
    echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
}

function viswiz_get_default_node_type_schema() {
    return array(
        'person' => array(
            'label' => 'Person',
            'description' => 'Individual person, witness, editor, official, party member, or public figure.',
            'icon' => 'admin-users',
            'color' => '#2563eb',
            'subtypes' => array(
                'journalist' => 'Journalist',
                'witness' => 'Witness',
                'defendant' => 'Defendant',
                'lawyer' => 'Lawyer',
                'judge' => 'Judge',
                'prosecutor' => 'Prosecutor',
                'politician' => 'Politician',
                'official' => 'Official',
                'activist' => 'Activist',
            ),
        ),
        'organization' => array(
            'label' => 'Organization',
            'description' => 'Formal or informal organization, party, group, company, collective, or media outlet.',
            'icon' => 'building',
            'color' => '#7c3aed',
            'subtypes' => array(
                'political_party' => 'Political party',
                'informal_group' => 'Informal group',
                'street_group' => 'Street group',
                'publishing_house' => 'Publishing house',
                'paramilitary_group' => 'Paramilitary group',
                'media_outlet' => 'Media outlet',
                'collective' => 'Collective',
                'company' => 'Company',
            ),
        ),
        'event' => array(
            'label' => 'Event',
            'description' => 'Rally, attack, meeting, hearing, vote, publication, trial session, or founding event.',
            'icon' => 'calendar-alt',
            'color' => '#dc2626',
            'subtypes' => array(
                'rally' => 'Rally',
                'attack' => 'Attack',
                'trial_session' => 'Trial session',
                'trial' => 'Trial',
                'election' => 'Election',
                'founding_event' => 'Founding event',
                'meeting' => 'Meeting',
                'hearing' => 'Hearing',
            ),
        ),
        'place' => array(
            'label' => 'Place',
            'description' => 'Location, venue, city, courtroom, prison, or area.',
            'icon' => 'location-alt',
            'color' => '#059669',
            'subtypes' => array(
                'city' => 'City',
                'neighborhood' => 'Neighborhood',
                'courtroom' => 'Courtroom',
                'prison' => 'Prison',
                'venue' => 'Venue',
                'public_space' => 'Public space',
            ),
        ),
        'publication' => array(
            'label' => 'Publication',
            'description' => 'Article, video, manifesto, book, court document, webpage, or media item.',
            'icon' => 'media-document',
            'color' => '#d97706',
            'subtypes' => array(
                'article' => 'Article',
                'video' => 'Video',
                'book' => 'Book',
                'manifesto' => 'Manifesto',
                'court_document' => 'Court document',
                'webpage' => 'Webpage',
                'social_post' => 'Social post',
            ),
        ),
        'legal_case' => array(
            'label' => 'Legal case',
            'description' => 'Case, indictment, procedure, charge grouping, or court file.',
            'icon' => 'portfolio',
            'color' => '#334155',
            'subtypes' => array(
                'criminal_case' => 'Criminal case',
                'appeal' => 'Appeal',
                'indictment' => 'Indictment',
                'charge' => 'Charge',
                'court_file' => 'Court file',
            ),
        ),
        'state_body' => array(
            'label' => 'State body',
            'description' => 'Court, ministry, police unit, public authority, agency, or institutional body.',
            'icon' => 'bank',
            'color' => '#0f766e',
            'subtypes' => array(
                'court' => 'Court',
                'ministry' => 'Ministry',
                'police_unit' => 'Police unit',
                'agency' => 'Agency',
                'authority' => 'Authority',
            ),
        ),
        'symbol' => array(
            'label' => 'Symbol',
            'description' => 'Symbol, slogan, insignia, logo, or visual marker.',
            'icon' => 'star-filled',
            'color' => '#ca8a04',
            'subtypes' => array(
                'slogan' => 'Slogan',
                'logo' => 'Logo',
                'insignia' => 'Insignia',
                'gesture' => 'Gesture',
            ),
        ),
        'concept' => array(
            'label' => 'Concept',
            'description' => 'Ideological concept, pattern, allegation category, theme, or analytical tag.',
            'icon' => 'lightbulb',
            'color' => '#9333ea',
            'subtypes' => array(
                'ideology' => 'Ideology',
                'theme' => 'Theme',
                'allegation' => 'Allegation',
                'pattern' => 'Pattern',
            ),
        ),
        'asset' => array(
            'label' => 'Asset',
            'description' => 'Website, domain, account, venue, channel, repository, or operational asset.',
            'icon' => 'admin-site-alt3',
            'color' => '#0891b2',
            'subtypes' => array(
                'website' => 'Website',
                'social_media_account' => 'Social media account',
                'domain' => 'Domain',
                'channel' => 'Channel',
                'repository' => 'Repository',
            ),
        ),
    );
}

function viswiz_parse_schema_subtypes_text( $text ) {
    $subtypes = array();
    $lines = preg_split( '/\r\n|\r|\n/', (string) $text );
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( $line === '' ) {
            continue;
        }
        $parts = preg_split( '/\s*[|:,]\s*/', $line, 2 );
        if ( count( $parts ) === 1 ) {
            $label = sanitize_text_field( $parts[0] );
            $slug = sanitize_key( remove_accents( $label ) );
        } else {
            $slug = sanitize_key( $parts[0] );
            $label = sanitize_text_field( $parts[1] );
        }
        if ( $slug !== '' && $label !== '' ) {
            $subtypes[ $slug ] = $label;
        }
    }
    return $subtypes;
}

function viswiz_normalize_node_type_schema( $schema ) {
    $defaults = viswiz_get_default_node_type_schema();
    if ( ! is_array( $schema ) || empty( $schema ) ) {
        $schema = $defaults;
    }
    foreach ( $defaults as $slug => $row ) {
        if ( ! isset( $schema[ $slug ] ) || ! is_array( $schema[ $slug ] ) ) {
            $schema[ $slug ] = $row;
            continue;
        }
        foreach ( array( 'label', 'description', 'icon', 'color' ) as $key ) {
            if ( empty( $schema[ $slug ][ $key ] ) ) {
                $schema[ $slug ][ $key ] = $row[ $key ] ?? '';
            }
        }
        if ( empty( $schema[ $slug ]['subtypes'] ) || ! is_array( $schema[ $slug ]['subtypes'] ) ) {
            $schema[ $slug ]['subtypes'] = $row['subtypes'] ?? array();
        }
    }
    foreach ( $schema as $slug => $row ) {
        if ( ! is_array( $row ) ) {
            unset( $schema[ $slug ] );
            continue;
        }
        $subtypes = $row['subtypes'] ?? array();
        if ( is_string( $subtypes ) ) {
            $subtypes = viswiz_parse_schema_subtypes_text( $subtypes );
        }
        $clean_subtypes = array();
        if ( is_array( $subtypes ) ) {
            foreach ( $subtypes as $sub_slug => $sub_label ) {
                if ( is_array( $sub_label ) ) {
                    $sub_label = $sub_label['label'] ?? $sub_slug;
                }
                $sub_slug = sanitize_key( $sub_slug );
                $sub_label = sanitize_text_field( $sub_label );
                if ( $sub_slug !== '' && $sub_label !== '' ) {
                    $clean_subtypes[ $sub_slug ] = $sub_label;
                }
            }
        }
        $schema[ $slug ]['subtypes'] = $clean_subtypes;
    }
    return $schema;
}

function viswiz_get_node_type_schema() {
    $schema = get_option( VISWIZ_OPTION_NODE_TYPE_SCHEMA, array() );
    return viswiz_normalize_node_type_schema( $schema );
}

function viswiz_get_default_relation_type_schema() {
    return array(
        'member_of' => array( 'label' => 'Member of', 'description' => 'Person or organization is a member of another group.', 'direction' => 'directed', 'inverse_label' => 'Has member', 'default_intensity' => 1, 'source_type' => 'person', 'target_type' => 'organization' ),
        'leader_of' => array( 'label' => 'Leader of', 'description' => 'Person leads or formally heads an organization or event.', 'direction' => 'directed', 'inverse_label' => 'Led by', 'default_intensity' => 1, 'source_type' => 'person', 'target_type' => 'organization' ),
        'participated_in' => array( 'label' => 'Participated in', 'description' => 'Entity participated in an event, publication, or action.', 'direction' => 'directed', 'inverse_label' => 'Had participant', 'default_intensity' => 1, 'source_type' => '', 'target_type' => 'event' ),
        'organized' => array( 'label' => 'Organized', 'description' => 'Entity organized an event, action, publication, or campaign.', 'direction' => 'directed', 'inverse_label' => 'Organized by', 'default_intensity' => 1, 'source_type' => '', 'target_type' => 'event' ),
        'funded' => array( 'label' => 'Funded', 'description' => 'Entity provided financial support, resources, or sponsorship.', 'direction' => 'directed', 'inverse_label' => 'Funded by', 'default_intensity' => 1, 'source_type' => '', 'target_type' => '' ),
        'published' => array( 'label' => 'Published', 'description' => 'Entity published, hosted, or distributed a publication.', 'direction' => 'directed', 'inverse_label' => 'Published by', 'default_intensity' => 1, 'source_type' => '', 'target_type' => 'publication' ),
        'accused_in' => array( 'label' => 'Accused in', 'description' => 'Entity is accused in a legal case or allegation grouping.', 'direction' => 'directed', 'inverse_label' => 'Has accused', 'default_intensity' => 1, 'source_type' => 'person', 'target_type' => 'legal_case' ),
        'witness_in' => array( 'label' => 'Witness in', 'description' => 'Entity appears as a witness or source in a legal or reporting event.', 'direction' => 'directed', 'inverse_label' => 'Has witness', 'default_intensity' => 1, 'source_type' => 'person', 'target_type' => 'legal_case' ),
        'located_at' => array( 'label' => 'Located at', 'description' => 'Entity or event is associated with a place.', 'direction' => 'directed', 'inverse_label' => 'Hosts', 'default_intensity' => 1, 'source_type' => '', 'target_type' => 'place' ),
        'connected_to' => array( 'label' => 'Connected to', 'description' => 'General association when a more specific relation type is not yet approved.', 'direction' => 'undirected', 'inverse_label' => 'Connected to', 'default_intensity' => 1, 'source_type' => '', 'target_type' => '' ),
    );
}

function viswiz_get_relation_type_schema() {
    $schema = get_option( VISWIZ_OPTION_RELATION_TYPE_SCHEMA, array() );
    if ( ! is_array( $schema ) || empty( $schema ) ) {
        $schema = viswiz_get_default_relation_type_schema();
    }
    return $schema;
}

function viswiz_render_node_types_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    $schema = viswiz_get_node_type_schema();
    $saved = isset( $_GET['saved'] );
    ?>
    <div class="wrap viswiz-admin-page">
        <h1>Node Types</h1>
        <p class="viswiz-page-intro">Maintain the canonical graph schema. Subtypes saved here are the source of truth for node editor dropdowns, connected-node creation, quick add, filters, validation, frontend badges, and exports.</p>
        <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p>Node type schema saved.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="viswiz-schema-form viswiz-node-schema-form">
            <input type="hidden" name="action" value="viswiz_save_node_type_schema" />
            <?php wp_nonce_field( 'viswiz_save_node_type_schema' ); ?>
            <div class="viswiz-schema-grid viswiz-node-schema-grid" data-viswiz-node-schema-rows>
                <?php foreach ( $schema as $slug => $row ) : ?>
                    <?php $subtype_lines = array(); foreach ( $row['subtypes'] ?? array() as $sub_slug => $sub_label ) { $subtype_lines[] = $sub_slug . ' | ' . $sub_label; } ?>
                    <section class="viswiz-schema-card viswiz-node-schema-card">
                        <h2><code><?php echo esc_html( $slug ); ?></code></h2>
                        <p><label>Slug <input type="text" name="node_schema[slug][]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" /></label></p>
                        <p><label>Label <input type="text" name="node_schema[label][]" value="<?php echo esc_attr( $row['label'] ?? $slug ); ?>" class="regular-text" /></label></p>
                        <p><label>Description <textarea name="node_schema[description][]" rows="3" class="large-text"><?php echo esc_textarea( $row['description'] ?? '' ); ?></textarea></label></p>
                        <p><label>Dashicon <input type="text" name="node_schema[icon][]" value="<?php echo esc_attr( $row['icon'] ?? '' ); ?>" class="regular-text" /></label></p>
                        <p><label>Default color <input type="color" name="node_schema[color][]" value="<?php echo esc_attr( $row['color'] ?? '#2563eb' ); ?>" /></label></p>
                        <p><label>Editable subtypes <textarea name="node_schema[subtypes][]" rows="8" class="large-text code" placeholder="political_party | Political party&#10;informal_group | Informal group"><?php echo esc_textarea( implode( "\n", $subtype_lines ) ); ?></textarea></label></p>
                        <p class="description">One subtype per line, as <code>slug | Label</code>. Labels may be edited freely; slugs are stored in nodes and exports.</p>
                    </section>
                <?php endforeach; ?>
                <section class="viswiz-schema-card viswiz-node-schema-card viswiz-schema-card-new">
                    <h2>New node type</h2>
                    <p><label>Slug <input type="text" name="node_schema[slug][]" value="" class="regular-text" placeholder="custom_type" /></label></p>
                    <p><label>Label <input type="text" name="node_schema[label][]" value="" class="regular-text" placeholder="Custom type" /></label></p>
                    <p><label>Description <textarea name="node_schema[description][]" rows="3" class="large-text"></textarea></label></p>
                    <p><label>Dashicon <input type="text" name="node_schema[icon][]" value="marker" class="regular-text" /></label></p>
                    <p><label>Default color <input type="color" name="node_schema[color][]" value="#2563eb" /></label></p>
                    <p><label>Editable subtypes <textarea name="node_schema[subtypes][]" rows="8" class="large-text code" placeholder="subtype_slug | Subtype label"></textarea></label></p>
                </section>
            </div>
            <?php submit_button( 'Save node types and subtypes' ); ?>
        </form>
    </div>
    <?php
}

function viswiz_render_schema_node_type_select( $name, $selected = '', $include_empty = true ) {
    $types = viswiz_get_graph_node_types();
    echo '<select name="' . esc_attr( $name ) . '">';
    if ( $include_empty ) {
        echo '<option value="">Any type</option>';
    }
    foreach ( $types as $slug => $label ) {
        echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $selected, $slug, false ) . '>' . esc_html( $label ) . '</option>';
    }
    if ( $selected && ! isset( $types[ $selected ] ) ) {
        echo '<option value="' . esc_attr( $selected ) . '" selected>' . esc_html( $selected ) . '</option>';
    }
    echo '</select>';
}

function viswiz_render_schema_node_subtype_select( $name, $selected_type = '', $selected_subtype = '' ) {
    $all_subtypes = viswiz_get_graph_node_subtypes();
    echo '<select name="' . esc_attr( $name ) . '" data-viswiz-schema-subtype-select>';
    echo '<option value="">Any subtype</option>';
    foreach ( $all_subtypes as $type => $subtypes ) {
        $type_label = viswiz_get_graph_node_types()[ $type ] ?? $type;
        foreach ( $subtypes as $slug => $label ) {
            $value = $type . ':' . $slug;
            $is_selected = ( $selected_subtype === $slug && ( $selected_type === '' || $selected_type === $type ) );
            echo '<option value="' . esc_attr( $value ) . '" data-node-type="' . esc_attr( $type ) . '" ' . selected( $is_selected, true, false ) . '>' . esc_html( $type_label . ' / ' . $label ) . '</option>';
        }
    }
    echo '</select>';
}

function viswiz_render_relation_types_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    $schema = viswiz_get_relation_type_schema();
    $saved = isset( $_GET['saved'] );
    ?>
    <div class="wrap viswiz-admin-page">
        <h1>Relation Types</h1>
        <p class="viswiz-page-intro">Use controlled relation types to keep graph lines consistent across editors and datasets. Source/target constraints now use the canonical node schema so mismatch warnings are reliable.</p>
        <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p>Relation type schema saved.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="viswiz-schema-form">
            <input type="hidden" name="action" value="viswiz_save_relation_type_schema" />
            <?php wp_nonce_field( 'viswiz_save_relation_type_schema' ); ?>
            <table class="widefat striped viswiz-relation-type-table">
                <thead><tr><th>Slug</th><th>Label</th><th>Inverse label</th><th>Default direction</th><th>Default intensity</th><th>Usual source</th><th>Usual target</th><th>Description</th></tr></thead>
                <tbody data-viswiz-relation-type-rows>
                    <?php foreach ( $schema as $slug => $row ) : ?>
                        <tr>
                            <td><input type="text" name="relation_schema[slug][]" value="<?php echo esc_attr( $slug ); ?>" class="regular-text" /></td>
                            <td><input type="text" name="relation_schema[label][]" value="<?php echo esc_attr( $row['label'] ?? $slug ); ?>" class="regular-text" /></td>
                            <td><input type="text" name="relation_schema[inverse_label][]" value="<?php echo esc_attr( $row['inverse_label'] ?? '' ); ?>" class="regular-text" /></td>
                            <td><select name="relation_schema[direction][]"><option value="directed" <?php selected( $row['direction'] ?? 'directed', 'directed' ); ?>>Directed</option><option value="undirected" <?php selected( $row['direction'] ?? '', 'undirected' ); ?>>Undirected</option><option value="bidirectional" <?php selected( $row['direction'] ?? '', 'bidirectional' ); ?>>Bidirectional</option></select></td>
                            <td><input type="number" name="relation_schema[default_intensity][]" value="<?php echo esc_attr( $row['default_intensity'] ?? 1 ); ?>" min="0" step="0.01" class="small-text" /></td>
                            <td><div class="viswiz-schema-type-pair"><?php viswiz_render_schema_node_type_select( 'relation_schema[source_type][]', $row['source_type'] ?? '' ); ?><?php viswiz_render_schema_node_subtype_select( 'relation_schema[source_subtype][]', $row['source_type'] ?? '', $row['source_subtype'] ?? '' ); ?></div></td>
                            <td><div class="viswiz-schema-type-pair"><?php viswiz_render_schema_node_type_select( 'relation_schema[target_type][]', $row['target_type'] ?? '' ); ?><?php viswiz_render_schema_node_subtype_select( 'relation_schema[target_subtype][]', $row['target_type'] ?? '', $row['target_subtype'] ?? '' ); ?></div></td>
                            <td><textarea name="relation_schema[description][]" rows="2" class="large-text"><?php echo esc_textarea( $row['description'] ?? '' ); ?></textarea></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="viswiz-relation-type-empty-row">
                        <td><input type="text" name="relation_schema[slug][]" value="" class="regular-text" placeholder="new_relation_slug" /></td>
                        <td><input type="text" name="relation_schema[label][]" value="" class="regular-text" placeholder="New relation label" /></td>
                        <td><input type="text" name="relation_schema[inverse_label][]" value="" class="regular-text" /></td>
                        <td><select name="relation_schema[direction][]"><option value="directed">Directed</option><option value="undirected">Undirected</option><option value="bidirectional">Bidirectional</option></select></td>
                        <td><input type="number" name="relation_schema[default_intensity][]" value="1" min="0" step="0.01" class="small-text" /></td>
                        <td><div class="viswiz-schema-type-pair"><?php viswiz_render_schema_node_type_select( 'relation_schema[source_type][]', '' ); ?><?php viswiz_render_schema_node_subtype_select( 'relation_schema[source_subtype][]', '', '' ); ?></div></td>
                        <td><div class="viswiz-schema-type-pair"><?php viswiz_render_schema_node_type_select( 'relation_schema[target_type][]', '' ); ?><?php viswiz_render_schema_node_subtype_select( 'relation_schema[target_subtype][]', '', '' ); ?></div></td>
                        <td><textarea name="relation_schema[description][]" rows="2" class="large-text"></textarea></td>
                    </tr>
                </tbody>
            </table>
            <p class="description">Add a new relation type in the empty row, then save. Leave a slug empty to ignore that row.</p>
            <?php submit_button( 'Save relation types' ); ?>
        </form>
    </div>
    <?php
}

function viswiz_admin_save_node_type_schema() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    check_admin_referer( 'viswiz_save_node_type_schema' );
    $raw = wp_unslash( $_POST['node_schema'] ?? array() );
    $schema = array();
    $slugs = isset( $raw['slug'] ) && is_array( $raw['slug'] ) ? $raw['slug'] : array();
    foreach ( $slugs as $i => $slug ) {
        $slug = sanitize_key( $slug );
        if ( $slug === '' ) {
            continue;
        }
        $schema[ $slug ] = array(
            'label' => sanitize_text_field( $raw['label'][ $i ] ?? $slug ),
            'description' => sanitize_textarea_field( $raw['description'][ $i ] ?? '' ),
            'icon' => sanitize_key( $raw['icon'][ $i ] ?? '' ),
            'color' => sanitize_hex_color( $raw['color'][ $i ] ?? '' ) ?: '#2563eb',
            'subtypes' => viswiz_parse_schema_subtypes_text( $raw['subtypes'][ $i ] ?? '' ),
        );
    }
    update_option( VISWIZ_OPTION_NODE_TYPE_SCHEMA, viswiz_normalize_node_type_schema( $schema ?: viswiz_get_default_node_type_schema() ) );
    wp_safe_redirect( admin_url( 'admin.php?page=viswiz-node-types&saved=1' ) );
    exit;
}

function viswiz_extract_schema_subtype_value( $value, $expected_type = '' ) {
    $value = sanitize_text_field( $value );
    if ( $value === '' ) {
        return '';
    }
    if ( strpos( $value, ':' ) !== false ) {
        list( $type, $subtype ) = array_map( 'sanitize_key', explode( ':', $value, 2 ) );
        if ( $expected_type && $type && $type !== $expected_type ) {
            return '';
        }
        return $subtype;
    }
    return sanitize_key( $value );
}

function viswiz_admin_save_relation_type_schema() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
    }
    check_admin_referer( 'viswiz_save_relation_type_schema' );
    $raw = wp_unslash( $_POST['relation_schema'] ?? array() );
    $schema = array();
    $slugs = isset( $raw['slug'] ) && is_array( $raw['slug'] ) ? $raw['slug'] : array();
    foreach ( $slugs as $i => $slug ) {
        $slug = sanitize_key( $slug );
        if ( $slug === '' ) {
            continue;
        }
        $direction = sanitize_key( $raw['direction'][ $i ] ?? 'directed' );
        if ( ! in_array( $direction, array( 'directed', 'undirected', 'bidirectional' ), true ) ) {
            $direction = 'directed';
        }
        $schema[ $slug ] = array(
            'label' => sanitize_text_field( $raw['label'][ $i ] ?? $slug ),
            'description' => sanitize_textarea_field( $raw['description'][ $i ] ?? '' ),
            'direction' => $direction,
            'inverse_label' => sanitize_text_field( $raw['inverse_label'][ $i ] ?? '' ),
            'default_intensity' => isset( $raw['default_intensity'][ $i ] ) ? (float) $raw['default_intensity'][ $i ] : 1,
            'source_type' => sanitize_key( $raw['source_type'][ $i ] ?? '' ),
            'source_subtype' => viswiz_extract_schema_subtype_value( $raw['source_subtype'][ $i ] ?? '', sanitize_key( $raw['source_type'][ $i ] ?? '' ) ),
            'target_type' => sanitize_key( $raw['target_type'][ $i ] ?? '' ),
            'target_subtype' => viswiz_extract_schema_subtype_value( $raw['target_subtype'][ $i ] ?? '', sanitize_key( $raw['target_type'][ $i ] ?? '' ) ),
        );
    }
    update_option( VISWIZ_OPTION_RELATION_TYPE_SCHEMA, $schema ?: viswiz_get_default_relation_type_schema() );
    wp_safe_redirect( admin_url( 'admin.php?page=viswiz-relation-types&saved=1' ) );
    exit;
}

function viswiz_relation_type_options_for_script() {
    $options = array();
    foreach ( viswiz_get_relation_type_schema() as $slug => $row ) {
        $options[] = array(
            'slug' => $slug,
            'label' => $row['label'] ?? $slug,
            'direction' => $row['direction'] ?? 'directed',
            'inverse_label' => $row['inverse_label'] ?? '',
            'description' => $row['description'] ?? '',
            'default_intensity' => isset( $row['default_intensity'] ) ? (float) $row['default_intensity'] : 1,
            'source_type' => $row['source_type'] ?? '',
            'source_subtype' => $row['source_subtype'] ?? '',
            'target_type' => $row['target_type'] ?? '',
            'target_subtype' => $row['target_subtype'] ?? '',
        );
    }
    return $options;
}

function viswiz_node_type_options_for_script() {
    $options = array();
    foreach ( viswiz_get_node_type_schema() as $slug => $row ) {
        $options[] = array(
            'slug' => $slug,
            'label' => $row['label'] ?? $slug,
            'description' => $row['description'] ?? '',
            'color' => $row['color'] ?? '',
            'subtypes' => $row['subtypes'] ?? array(),
        );
    }
    return $options;
}

function viswiz_render_relation_type_field( $name, $value = '' ) {
    $schema = viswiz_get_relation_type_schema();
    $value = sanitize_key( $value );
    $html = sprintf( '<select name="%s" class="regular-text" data-viswiz-relation-type-select data-viswiz-smart-search="relation-type" data-viswiz-smart-placeholder="Search relation type…" data-viswiz-smart-empty="No matching relation types.">', esc_attr( $name ) );
    $html .= '<option value="">Select relation type</option>';
    foreach ( $schema as $slug => $row ) {
        $html .= sprintf(
            '<option value="%s" %s data-relation-label="%s" data-relation-description="%s" data-relation-direction="%s" data-relation-intensity="%s" data-relation-source="%s" data-relation-source-subtype="%s" data-relation-target="%s" data-relation-target-subtype="%s">%s</option>',
            esc_attr( $slug ),
            selected( $value, $slug, false ),
            esc_attr( $row['label'] ?? $slug ),
            esc_attr( $row['description'] ?? '' ),
            esc_attr( $row['direction'] ?? '' ),
            esc_attr( $row['default_intensity'] ?? '' ),
            esc_attr( $row['source_type'] ?? '' ),
            esc_attr( $row['source_subtype'] ?? '' ),
            esc_attr( $row['target_type'] ?? '' ),
            esc_attr( $row['target_subtype'] ?? '' ),
            esc_html( $row['label'] ?? $slug )
        );
    }
    if ( $value && ! isset( $schema[ $value ] ) ) {
        $html .= sprintf( '<option value="%s" selected>%s</option>', esc_attr( $value ), esc_html( $value ) );
    }
    $html .= '</select>';
    return $html;
}

function viswiz_register_settings() {
    register_setting( 'viswiz_settings', VISWIZ_OPTION_TARGET, array( 'type' => 'number', 'sanitize_callback' => 'floatval' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PROGRESS_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_progress_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PIE_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_pie_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_DIAGRAM, array( 'sanitize_callback' => 'viswiz_sanitize_diagram_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_GRAPH, array( 'sanitize_callback' => 'viswiz_sanitize_graph_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_SCOPE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_PERIOD_VALUE, array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_PERIOD_UNIT, array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_PERIOD_MODE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_PERIOD_START, array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_PRODUCTS, array( 'sanitize_callback' => 'viswiz_sanitize_id_array' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_CURRENCY, array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_CATEGORIES, array( 'sanitize_callback' => 'viswiz_sanitize_id_array' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_DISPLAY_DEFAULTS, array( 'sanitize_callback' => 'viswiz_sanitize_display_defaults' ) );
}

function viswiz_render_settings_page() {
    $progress_items = viswiz_get_manual_progress();
    $pie_items = viswiz_get_manual_pie();
    $diagram_sections = viswiz_get_diagram_data();
    $graph_data = viswiz_get_graph_data();
    $sales_scope = get_option( VISWIZ_OPTION_SALES_SCOPE, 'total' );
    $sales_period_mode = viswiz_get_period_mode_option();
    $sales_period_value = (int) get_option( VISWIZ_OPTION_SALES_PERIOD_VALUE, 0 );
    $sales_period_unit = viswiz_get_period_unit_option();
    $sales_period_start = get_option( VISWIZ_OPTION_SALES_PERIOD_START, '' );
    $currency_code = viswiz_get_currency_code();
    $display_defaults = viswiz_get_display_defaults();
    if ( $sales_period_value <= 0 ) {
        $sales_period_value = 30;
        $sales_period_unit = 'day';
    }
    $sales_product_ids = viswiz_get_sales_product_ids();
    $sales_category_ids = viswiz_get_sales_category_ids();
    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
    if ( 'sales' === $active_tab ) {
        $active_tab = 'commerce';
    }
    if ( in_array( $active_tab, array( 'progress', 'pie' ), true ) ) {
        $active_tab = 'manual';
    }
    if ( ! in_array( $active_tab, array( 'overview', 'commerce', 'display', 'manual', 'diagram', 'graph' ), true ) ) {
        $active_tab = 'overview';
    }
    $settings_url = admin_url( 'admin.php?page=viswiz-settings' );
    ?>
    <div class="wrap viswiz-admin-page viswiz-settings-page">
        <h1>VisWiz Settings</h1>
        <p class="viswiz-page-intro">Use this screen for global defaults and fallback data. Day-to-day work should happen in <strong>Visualizations</strong>, <strong>Datasets</strong>, <strong>Node Types</strong>, and <strong>Relation Types</strong>.</p>
        <div class="viswiz-settings-toolbar">
            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=viswiz_visualization' ) ); ?>" class="button button-primary">Add New Visualization</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>" class="button">Datasets</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-node-types' ) ); ?>" class="button">Node Types</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-relation-types' ) ); ?>" class="button">Relation Types</a>
        </div>
        <h2 class="nav-tab-wrapper viswiz-settings-tabs">
            <a href="<?php echo esc_url( $settings_url . '&tab=overview' ); ?>" class="nav-tab <?php echo $active_tab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
            <a href="<?php echo esc_url( $settings_url . '&tab=commerce' ); ?>" class="nav-tab <?php echo $active_tab === 'commerce' ? 'nav-tab-active' : ''; ?>">Commerce Defaults</a>
            <a href="<?php echo esc_url( $settings_url . '&tab=display' ); ?>" class="nav-tab <?php echo $active_tab === 'display' ? 'nav-tab-active' : ''; ?>">Display Defaults</a>
            <a href="<?php echo esc_url( $settings_url . '&tab=manual' ); ?>" class="nav-tab <?php echo $active_tab === 'manual' ? 'nav-tab-active' : ''; ?>">Manual Fallback Data</a>
            <a href="<?php echo esc_url( $settings_url . '&tab=diagram' ); ?>" class="nav-tab <?php echo $active_tab === 'diagram' ? 'nav-tab-active' : ''; ?>">Legacy Diagram Defaults</a>
            <a href="<?php echo esc_url( $settings_url . '&tab=graph' ); ?>" class="nav-tab <?php echo $active_tab === 'graph' ? 'nav-tab-active' : ''; ?>">Legacy Graph Defaults</a>
        </h2>

        <?php if ( 'overview' === $active_tab ) : ?>
            <div class="viswiz-settings-overview-grid">
                <section class="viswiz-settings-card">
                    <h2>Where to work</h2>
                    <p>Use the dedicated builder screens for normal editorial work. These global settings are only defaults and reusable fallbacks.</p>
                    <ul class="viswiz-settings-link-list">
                        <li><a href="<?php echo esc_url( admin_url( 'edit.php?post_type=viswiz_visualization' ) ); ?>">Visualizations</a> — build and embed charts, graphs, timelines, and diagrams.</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>">Datasets</a> — manage reusable datasets, import/export, and usage.</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-node-types' ) ); ?>">Node Types</a> — edit canonical node schema and subtypes.</li>
                        <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-relation-types' ) ); ?>">Relation Types</a> — edit relation vocabulary, direction defaults, and source/target expectations.</li>
                    </ul>
                </section>
                <section class="viswiz-settings-card">
                    <h2>Global defaults</h2>
                    <p>These tabs affect fallback behavior when a visualization or shortcode does not provide its own data/settings.</p>
                    <div class="viswiz-settings-section-links">
                        <a href="<?php echo esc_url( $settings_url . '&tab=commerce' ); ?>">Commerce defaults</a>
                        <a href="<?php echo esc_url( $settings_url . '&tab=display' ); ?>">Display defaults</a>
                        <a href="<?php echo esc_url( $settings_url . '&tab=manual' ); ?>">Manual fallback data</a>
                        <a href="<?php echo esc_url( $settings_url . '&tab=diagram' ); ?>">Legacy diagram defaults</a>
                        <a href="<?php echo esc_url( $settings_url . '&tab=graph' ); ?>">Legacy graph defaults</a>
                    </div>
                </section>
                <section class="viswiz-settings-card viswiz-settings-card-warning">
                    <h2>Recommended workflow</h2>
                    <p>Prefer saving data inside datasets and individual visualizations. Keep fallback data here only for old shortcodes, tests, or site-wide defaults.</p>
                </section>
            </div>
        <?php else : ?>
            <form method="post" action="options.php" class="viswiz-settings-form">
                <?php settings_fields( 'viswiz_settings' ); ?>
                <?php if ( 'commerce' === $active_tab ) : ?>
                    <div class="viswiz-settings-grid">
                        <section class="viswiz-settings-card">
                            <h2>Commerce source</h2>
                            <p class="description">Fallback WooCommerce scope used when a shortcode or saved visualization does not define its own source.</p>
                            <label for="viswiz_sales_scope">Default sales scope</label>
                            <select name="viswiz_sales_scope" id="viswiz_sales_scope">
                                <option value="total" <?php selected( $sales_scope, 'total' ); ?>>All sales (total)</option>
                                <option value="product" <?php selected( $sales_scope, 'product' ); ?>>Specific product</option>
                                <option value="category" <?php selected( $sales_scope, 'category' ); ?>>Specific category</option>
                            </select>
                            <p class="description">Equivalent shortcode hint: <code>scope="product"</code> or <code>scope="category"</code>.</p>
                            <label for="viswiz_currency_code">Currency display</label>
                            <select name="viswiz_currency_code" id="viswiz_currency_code">
                                <?php foreach ( viswiz_get_currency_options() as $code => $label ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $currency_code, $code ); ?>>
                                        <?php echo esc_html( sprintf( '%s (%s)', $label, viswiz_get_currency_symbol_for_code( $code ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Controls amount labels in commerce charts and progress bars.</p>
                        </section>
                        <section class="viswiz-settings-card">
                            <h2>Default period</h2>
                            <p class="description">Used for commerce visualizations that do not define their own period.</p>
                            <label for="viswiz_sales_period_mode">Period mode</label>
                            <select name="viswiz_sales_period_mode" id="viswiz_sales_period_mode">
                                <option value="relative" <?php selected( $sales_period_mode, 'relative' ); ?>>Relative period</option>
                                <option value="fixed" <?php selected( $sales_period_mode, 'fixed' ); ?>>From a fixed date until now</option>
                            </select>
                            <div class="viswiz-period-group" data-viswiz-period="relative">
                                <label for="viswiz_sales_period_value">Relative period</label>
                                <div class="viswiz-inline-fields">
                                    <input type="number" name="viswiz_sales_period_value" id="viswiz_sales_period_value" value="<?php echo esc_attr( $sales_period_value ); ?>" min="1" class="small-text" />
                                    <select name="viswiz_sales_period_unit" id="viswiz_sales_period_unit">
                                        <option value="day" <?php selected( $sales_period_unit, 'day' ); ?>>day(s)</option>
                                        <option value="month" <?php selected( $sales_period_unit, 'month' ); ?>>month(s)</option>
                                        <option value="year" <?php selected( $sales_period_unit, 'year' ); ?>>year(s)</option>
                                    </select>
                                </div>
                                <p class="description">Example: last 3 months.</p>
                            </div>
                            <div class="viswiz-period-group" data-viswiz-period="fixed">
                                <label for="viswiz_sales_period_start">Start date/time</label>
                                <input type="datetime-local" name="viswiz_sales_period_start" id="viswiz_sales_period_start" value="<?php echo esc_attr( $sales_period_start ); ?>" />
                                <p class="description">Example: from 2024-01-01T00:00 until now.</p>
                            </div>
                        </section>
                        <section class="viswiz-settings-card">
                            <h2>Product/category filters</h2>
                            <p class="description">Only used when the default scope is product or category.</p>
                            <label for="viswiz_sales_product_id">Default product(s)</label>
                            <?php echo viswiz_render_product_search_field( 'viswiz_sales_product_ids[]', $sales_product_ids, true ); ?>
                            <p class="description">Used for product-scope charts. Example shortcode: <code>product_ids="123,456"</code>.</p>
                            <label for="viswiz_sales_category_id">Default category/categories</label>
                            <?php echo viswiz_render_category_select_field( 'viswiz_sales_category_ids[]', $sales_category_ids, 'viswiz_sales_category_id', true ); ?>
                            <p class="description">Used for category-scope charts. Example shortcode: <code>category_ids="45,67"</code>.</p>
                        </section>
                        <section class="viswiz-settings-card">
                            <h2>Progress target</h2>
                            <p class="description">Default target for auto progress bars when a visualization does not override it.</p>
                            <label for="viswiz_sales_target">Sales target</label>
                            <input type="number" name="viswiz_sales_target" id="viswiz_sales_target" value="<?php echo esc_attr( get_option( VISWIZ_OPTION_TARGET, 0 ) ); ?>" step="0.01" class="regular-text" />
                            <p class="description">Example: target 10000 for a monthly revenue goal.</p>
                        </section>
                    </div>
                <?php endif; ?>

                <?php if ( 'display' === $active_tab ) : ?>
                    <div class="viswiz-settings-grid">
                        <section class="viswiz-settings-card">
                            <h2>Shared visual defaults</h2>
                            <p class="description">Used as defaults for new or unspecific visualizations. Individual visualizations can still override these in their Display tab.</p>
                            <label for="viswiz_display_primary">Primary color</label>
                            <input type="color" name="viswiz_display_defaults[primary]" id="viswiz_display_primary" value="<?php echo esc_attr( $display_defaults['primary'] ); ?>" />
                            <label for="viswiz_display_secondary">Secondary color</label>
                            <input type="color" name="viswiz_display_defaults[secondary]" id="viswiz_display_secondary" value="<?php echo esc_attr( $display_defaults['secondary'] ); ?>" />
                            <label for="viswiz_display_accent">Accent color</label>
                            <input type="color" name="viswiz_display_defaults[accent]" id="viswiz_display_accent" value="<?php echo esc_attr( $display_defaults['accent'] ); ?>" />
                            <label for="viswiz_display_background">Background color</label>
                            <input type="color" name="viswiz_display_defaults[background]" id="viswiz_display_background" value="<?php echo esc_attr( $display_defaults['background'] ); ?>" />
                            <label for="viswiz_display_text">Text color</label>
                            <input type="color" name="viswiz_display_defaults[text]" id="viswiz_display_text" value="<?php echo esc_attr( $display_defaults['text'] ); ?>" />
                            <p><label><input type="hidden" name="viswiz_display_defaults[show_fullscreen_toggle]" value="0" /><input type="checkbox" name="viswiz_display_defaults[show_fullscreen_toggle]" value="1" <?php checked( $display_defaults['show_fullscreen_toggle'], 1 ); ?> /> Show full screen toggle on public visualizations</label></p>
                        </section>
                        <section class="viswiz-settings-card">
                            <h2>Graph viewer defaults</h2>
                            <p class="description">These control the public graph exploration tools shown to visitors.</p>
                            <label for="viswiz_display_node_style">Default node appearance</label>
                            <select name="viswiz_display_defaults[node_style]" id="viswiz_display_node_style">
                                <option value="card" <?php selected( $display_defaults['node_style'], 'card' ); ?>>Basic info cards</option>
                                <option value="compact" <?php selected( $display_defaults['node_style'], 'compact' ); ?>>Compact labels</option>
                                <option value="round" <?php selected( $display_defaults['node_style'], 'round' ); ?>>Round labels</option>
                            </select>
                            <label for="viswiz_display_node_radius">Base round/compact size</label>
                            <input type="number" name="viswiz_display_defaults[node_radius]" id="viswiz_display_node_radius" value="<?php echo esc_attr( $display_defaults['node_radius'] ); ?>" min="10" max="50" step="1" class="small-text" />
                            <label for="viswiz_display_node_card_width">Base card width</label>
                            <input type="number" name="viswiz_display_defaults[node_card_width]" id="viswiz_display_node_card_width" value="<?php echo esc_attr( $display_defaults['node_card_width'] ); ?>" min="90" max="260" step="10" class="small-text" />
                            <p><label><input type="hidden" name="viswiz_display_defaults[scale_nodes_by_relations]" value="0" /><input type="checkbox" name="viswiz_display_defaults[scale_nodes_by_relations]" value="1" <?php checked( $display_defaults['scale_nodes_by_relations'], 1 ); ?> /> Scale node size by relation count</label></p>
                            <label for="viswiz_display_relation_size_step">Size increase per relation</label>
                            <input type="number" name="viswiz_display_defaults[relation_size_step]" id="viswiz_display_relation_size_step" value="<?php echo esc_attr( $display_defaults['relation_size_step'] ); ?>" min="0" max="20" step="1" class="small-text" />
                            <label for="viswiz_display_max_relation_size_boost">Maximum relation size increase</label>
                            <input type="number" name="viswiz_display_defaults[max_relation_size_boost]" id="viswiz_display_max_relation_size_boost" value="<?php echo esc_attr( $display_defaults['max_relation_size_boost'] ); ?>" min="0" max="120" step="5" class="small-text" />
                            <label for="viswiz_display_filter_mode">Filter behaviour</label>
                            <select name="viswiz_display_defaults[graph_filter_mode]" id="viswiz_display_filter_mode">
                                <option value="fade" <?php selected( $display_defaults['graph_filter_mode'], 'fade' ); ?>>Fade non-matching items</option>
                                <option value="hide" <?php selected( $display_defaults['graph_filter_mode'], 'hide' ); ?>>Hide non-matching items</option>
                            </select>
                            <p><label><input type="hidden" name="viswiz_display_defaults[show_graph_toolbar]" value="0" /><input type="checkbox" name="viswiz_display_defaults[show_graph_toolbar]" value="1" <?php checked( $display_defaults['show_graph_toolbar'], 1 ); ?> /> Show visitor exploration toolbar</label></p>
                            <p><label><input type="hidden" name="viswiz_display_defaults[show_graph_search]" value="0" /><input type="checkbox" name="viswiz_display_defaults[show_graph_search]" value="1" <?php checked( $display_defaults['show_graph_search'], 1 ); ?> /> Include text search in graph toolbar</label></p>
                            <p><label><input type="hidden" name="viswiz_display_defaults[show_graph_filters]" value="0" /><input type="checkbox" name="viswiz_display_defaults[show_graph_filters]" value="1" <?php checked( $display_defaults['show_graph_filters'], 1 ); ?> /> Include type and relation filters in graph toolbar</label></p>
                            <p><label><input type="hidden" name="viswiz_display_defaults[show_graph_zoom]" value="0" /><input type="checkbox" name="viswiz_display_defaults[show_graph_zoom]" value="1" <?php checked( $display_defaults['show_graph_zoom'], 1 ); ?> /> Show zoom buttons</label></p>
                            <p><label><input type="hidden" name="viswiz_display_defaults[show_relation_labels]" value="0" /><input type="checkbox" name="viswiz_display_defaults[show_relation_labels]" value="1" <?php checked( $display_defaults['show_relation_labels'], 1 ); ?> /> Show relation labels on graph edges</label></p>
                        </section>
                        <section class="viswiz-settings-card viswiz-settings-card-wide">
                            <h2>Full info modal text defaults</h2>
                            <?php viswiz_render_graph_modal_label_inputs( $display_defaults, 'viswiz_display_defaults', 'viswiz_display_default' ); ?>
                        </section>
                    </div>
                <?php endif; ?>


                <?php if ( 'manual' === $active_tab ) : ?>
                    <div class="viswiz-settings-grid">
                        <section class="viswiz-settings-card viswiz-settings-card-wide">
                            <h2>Manual progress fallback rows</h2>
                            <p class="description">Fallback rows for manual progress visualizations that do not have their own dataset or saved row data.</p>
                            <div id="viswiz-progress-rows" class="viswiz-repeatable viswiz-settings-repeatable">
                                <?php if ( empty( $progress_items ) ) : ?>
                                    <?php $progress_items = array( array( 'label' => '', 'value' => '', 'target' => '' ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $progress_items as $progress_index => $progress_item ) : ?>
                                    <div class="viswiz-row" data-progress-index="<?php echo esc_attr( $progress_index ); ?>">
                                        <?php $progress_targets = $progress_item['targets'] ?? array(); ?>
                                        <?php if ( empty( $progress_targets ) && isset( $progress_item['target'] ) ) : ?>
                                            <?php $progress_targets = array( array( 'name' => 'Target', 'value' => $progress_item['target'] ) ); ?>
                                        <?php endif; ?>
                                        <?php if ( empty( $progress_targets ) ) : ?>
                                            <?php $progress_targets = array( array( 'name' => '', 'value' => '' ) ); ?>
                                        <?php endif; ?>
                                        <input type="text" name="viswiz_manual_progress[label][]" placeholder="Label" value="<?php echo esc_attr( $progress_item['label'] ?? '' ); ?>" class="regular-text" />
                                        <input type="number" name="viswiz_manual_progress[value][]" placeholder="Value" value="<?php echo esc_attr( $progress_item['value'] ?? '' ); ?>" step="0.01" />
                                        <div class="viswiz-targets" data-name-prefix="viswiz_manual_progress">
                                            <?php foreach ( $progress_targets as $target ) : ?>
                                                <div class="viswiz-target-row">
                                                    <input type="text" name="viswiz_manual_progress[targets][name][<?php echo esc_attr( $progress_index ); ?>][]" placeholder="Target name" value="<?php echo esc_attr( $target['name'] ?? '' ); ?>" class="regular-text" />
                                                    <input type="number" name="viswiz_manual_progress[targets][value][<?php echo esc_attr( $progress_index ); ?>][]" placeholder="Target value" value="<?php echo esc_attr( $target['value'] ?? '' ); ?>" step="0.01" />
                                                    <button type="button" class="button viswiz-remove-target">Remove target</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="button viswiz-add-target" data-target-scope="settings">Add target</button>
                                        <button type="button" class="button viswiz-remove-row">Remove row</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="progress">Add progress row</button>
                        </section>
                        <section class="viswiz-settings-card viswiz-settings-card-wide">
                            <h2>Manual chart fallback rows</h2>
                            <p class="description">Fallback label/value/color rows for simple manual charts. Prefer a reusable dataset for new work.</p>
                            <div id="viswiz-pie-rows" class="viswiz-repeatable viswiz-settings-repeatable">
                                <?php if ( empty( $pie_items ) ) : ?>
                                    <?php $pie_items = array( array( 'label' => '', 'value' => '', 'color' => '' ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $pie_items as $pie_item ) : ?>
                                    <div class="viswiz-row">
                                        <input type="text" name="viswiz_manual_pie[label][]" placeholder="Label" value="<?php echo esc_attr( $pie_item['label'] ?? '' ); ?>" class="regular-text" />
                                        <input type="number" name="viswiz_manual_pie[value][]" placeholder="Value" value="<?php echo esc_attr( $pie_item['value'] ?? '' ); ?>" step="0.01" />
                                        <input type="color" name="viswiz_manual_pie[color][]" value="<?php echo esc_attr( $pie_item['color'] ?? '' ); ?>" />
                                        <button type="button" class="button viswiz-remove-row">Remove row</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="pie">Add chart row</button>
                        </section>
                    </div>
                <?php endif; ?>

                <?php if ( 'diagram' === $active_tab ) : ?>
                    <section class="viswiz-settings-card viswiz-settings-card-wide">
                        <h2>Legacy diagram fallback data</h2>
                        <p class="description">Used only by the legacy diagram fallback. For graph-like structures, use datasets, nodes, and relations instead.</p>
                        <div id="viswiz-diagram-sections" class="viswiz-repeatable viswiz-settings-repeatable">
                            <?php if ( empty( $diagram_sections ) ) : ?>
                                <?php $diagram_sections = array( array( 'title' => '', 'items' => array( '' ) ) ); ?>
                            <?php endif; ?>
                            <?php foreach ( $diagram_sections as $section_index => $diagram_section ) : ?>
                                <div class="viswiz-section" data-section-index="<?php echo esc_attr( $section_index ); ?>">
                                    <input type="text" name="viswiz_diagram_data[title][]" placeholder="Section title" value="<?php echo esc_attr( $diagram_section['title'] ?? '' ); ?>" class="regular-text" />
                                    <div class="viswiz-items">
                                        <?php $items = $diagram_section['items'] ?? array( '' ); ?>
                                        <?php if ( empty( $items ) ) : ?>
                                            <?php $items = array( '' ); ?>
                                        <?php endif; ?>
                                        <?php foreach ( $items as $item_value ) : ?>
                                            <div class="viswiz-item-row">
                                                <input type="text" name="viswiz_diagram_data[items][<?php echo esc_attr( $section_index ); ?>][]" placeholder="Item" value="<?php echo esc_attr( $item_value ); ?>" class="regular-text" />
                                                <button type="button" class="button viswiz-remove-item">Remove item</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="button viswiz-add-item">Add item</button>
                                    <button type="button" class="button viswiz-remove-section">Remove section</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" data-viswiz-add="diagram">Add diagram section</button>
                    </section>
                <?php endif; ?>

                <?php if ( 'graph' === $active_tab ) : ?>
                    <section class="viswiz-settings-card viswiz-settings-card-wide">
                        <h2>Legacy graph fallback data</h2>
                        <p class="description">Fallback graph data for old shortcodes only. For new work, use a Dataset and edit graph data inside a Visualization.</p>
                        <div class="viswiz-graph viswiz-settings-legacy-graph">
                            <?php $dataset_label = viswiz_get_graph_dataset_label( 0 ); ?>
                            <h3>Fallback nodes <span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span></h3>
                            <div id="viswiz-graph-nodes" class="viswiz-repeatable viswiz-card-list">
                                <?php $nodes = $graph_data['nodes'] ?? array(); ?>
                                <?php if ( empty( $nodes ) ) : ?>
                                    <?php $nodes = array( array( 'id' => '', 'label' => '', 'title' => '' ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $nodes as $node_index => $node ) : ?>
                                    <?php viswiz_render_graph_node_row( 'viswiz_graph_data[nodes]', $node, $node_index, $dataset_label ); ?>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="graph-node">Add fallback node</button>
                            <h3>Fallback relations <span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span></h3>
                            <?php viswiz_render_graph_node_datalist( $nodes, 'viswiz_visual_relation_nodes' ); ?>
                            <div id="viswiz-graph-links" class="viswiz-repeatable viswiz-card-list">
                                <?php $links = $graph_data['links'] ?? array(); ?>
                                <?php if ( empty( $links ) ) : ?>
                                    <?php $links = array( array( 'from' => '', 'to' => '', 'label' => '' ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $links as $link_index => $link ) : ?>
                                    <?php viswiz_render_graph_link_row( 'viswiz_graph_data[links]', $link, $link_index, $dataset_label, 'viswiz_visual_relation_nodes', $nodes ); ?>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="graph-link">Add fallback relation</button>
                        </div>
                    </section>
                <?php endif; ?>
                <?php submit_button(); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

function viswiz_get_graph_dataset_label( $dataset_id ) {
    if ( $dataset_id ) {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare( "SELECT name FROM " . viswiz_get_table_name( 'datasets' ) . " WHERE id = %d", absint( $dataset_id ) ) );
        if ( $name ) {
            return sprintf( '%s (#%d)', $name, $dataset_id );
        }
    }
    return 'Visualization-specific data';
}

function viswiz_get_graph_node_types() {
    $types = array();
    foreach ( viswiz_get_node_type_schema() as $slug => $row ) {
        $types[ $slug ] = $row['label'] ?? $slug;
    }
    return $types;
}

function viswiz_get_graph_node_subtypes() {
    $subtypes = array();
    foreach ( viswiz_get_node_type_schema() as $slug => $row ) {
        $subtypes[ $slug ] = is_array( $row['subtypes'] ?? null ) ? $row['subtypes'] : array();
    }
    return $subtypes;
}

function viswiz_get_graph_entity_types() {
    return viswiz_get_graph_node_types();
}

function viswiz_get_legacy_graph_node_type_mapping() {
    return array(
        'party' => array( 'organization', 'political_party' ),
        'movement' => array( 'organization', 'informal_group' ),
        'media' => array( 'organization', 'publishing_house' ),
    );
}

function viswiz_get_graph_node_subtype_options( $node_type ) {
    $subtypes = viswiz_get_graph_node_subtypes();
    return $subtypes[ $node_type ] ?? array();
}

function viswiz_normalize_graph_node_type( $node ) {
    $node_type = sanitize_key( $node['node_type'] ?? ( $node['entity_type'] ?? '' ) );
    $node_subtype = sanitize_key( $node['node_subtype'] ?? '' );
    $legacy_mapping = viswiz_get_legacy_graph_node_type_mapping();
    if ( isset( $legacy_mapping[ $node_type ] ) ) {
        list( $node_type, $mapped_subtype ) = $legacy_mapping[ $node_type ];
        if ( $node_subtype === '' ) {
            $node_subtype = $mapped_subtype;
        }
    }

    return array( $node_type, $node_subtype );
}

function viswiz_get_node_auto_id( $node, $index ) {
    $id = $node['id'] ?? '';
    if ( $id === '' ) {
        $id = 'node-' . ( (int) $index + 1 );
    }
    return sanitize_key( $id );
}


function viswiz_render_graph_node_image_gallery( $main_image, $other_images ) {
    $other_image_ids = array_filter( array_map( 'absint', explode( ',', (string) $other_images ) ) );
    $image_ids = array_values( array_unique( array_filter( array_merge( array( absint( $main_image ) ), $other_image_ids ) ) ) );
    ?>
    <div class="viswiz-node-image-gallery" data-viswiz-node-image-gallery aria-live="polite">
        <strong>Node images</strong>
        <?php if ( empty( $image_ids ) ) : ?>
            <p class="description" data-viswiz-node-image-empty>No images attached to this node.</p>
        <?php else : ?>
            <div class="viswiz-node-image-gallery-grid">
                <?php foreach ( $image_ids as $image_id ) : ?>
                    <?php $is_featured = ( absint( $main_image ) === $image_id ); ?>
                    <figure class="viswiz-node-image-thumb<?php echo $is_featured ? ' is-featured' : ''; ?>" data-viswiz-node-image-id="<?php echo esc_attr( $image_id ); ?>">
                        <?php echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'class' => 'viswiz-node-image-thumb-img' ) ); ?>
                        <figcaption><?php echo $is_featured ? esc_html__( 'Featured image', 'viswiz' ) : esc_html__( 'Attached image', 'viswiz' ); ?> <span>#<?php echo esc_html( $image_id ); ?></span></figcaption>
                        <div class="viswiz-node-image-actions">
                            <button type="button" class="button button-small" data-viswiz-node-image-replace="<?php echo esc_attr( $image_id ); ?>"><?php esc_html_e( 'Replace', 'viswiz' ); ?></button>
                            <button type="button" class="button button-small" data-viswiz-node-image-edit="<?php echo esc_attr( $image_id ); ?>"><?php esc_html_e( 'Edit', 'viswiz' ); ?></button>
                            <button type="button" class="button button-small button-link-delete" data-viswiz-node-image-remove="<?php echo esc_attr( $image_id ); ?>"><?php esc_html_e( 'Remove', 'viswiz' ); ?></button>
                        </div>
                    </figure>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

function viswiz_render_graph_node_row( $name_prefix, $node = array(), $index = 0, $dataset_label = '' ) {
    $id = viswiz_get_node_auto_id( $node, $index );
    $title = $node['title'] ?? ( $node['label'] ?? '' );
    $description = $node['description'] ?? '';
    list( $node_type, $node_subtype ) = viswiz_normalize_graph_node_type( $node );
    $node_types = viswiz_get_graph_node_types();
    $node_subtypes = viswiz_get_graph_node_subtype_options( $node_type );
    $is_proposed_subtype = ( $node_subtype === 'proposed' );
    $main_image = absint( $node['main_image'] ?? 0 );
    $other_images = is_array( $node['other_images'] ?? null ) ? implode( ',', array_map( 'absint', $node['other_images'] ) ) : sanitize_text_field( $node['other_images'] ?? '' );
    $custom_labels = is_array( $node['custom_labels'] ?? null ) ? $node['custom_labels'] : array();
    if ( empty( $custom_labels ) ) {
        $custom_labels = array( array( 'key' => '', 'type' => 'short', 'value' => '' ) );
    }
    $search_text = strtolower( wp_strip_all_tags( implode( ' ', array( $title, $node['label'] ?? '', $description ) ) ) );
    ?>
    <details class="viswiz-node-card viswiz-sortable-card" data-viswiz-node-card data-node-index="<?php echo esc_attr( $index ); ?>" data-viswiz-node-search-text="<?php echo esc_attr( $search_text ); ?>" data-viswiz-node-type-value="<?php echo esc_attr( $node_type ); ?>" data-viswiz-node-subtype-value="<?php echo esc_attr( $node_subtype ); ?>">
        <summary>
            <strong><?php echo esc_html( $title ?: 'New node' ); ?></strong>
            <span class="viswiz-node-card-summary-meta"><?php echo esc_html( $node_types[ $node_type ] ?? 'No type' ); ?><?php echo $node_subtype ? esc_html( ' / ' . ( $node_subtypes[ $node_subtype ] ?? $node_subtype ) ) : ''; ?></span>
            <code data-viswiz-node-id-display><?php echo esc_html( $id ); ?></code>
            <?php if ( $dataset_label ) : ?><span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span><?php endif; ?>
        </summary>
        <div class="viswiz-node-card-media">
            <?php if ( $main_image ) : ?>
                <?php echo wp_get_attachment_image( $main_image, 'thumbnail', false, array( 'class' => 'viswiz-node-card-image' ) ); ?>
            <?php else : ?>
                <span class="viswiz-node-card-image-placeholder" aria-hidden="true">No image</span>
            <?php endif; ?>
        </div>
        <input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[id][]" value="<?php echo esc_attr( $id ); ?>" data-viswiz-node-id />
        <div class="viswiz-node-grid">
            <label>Title <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[title][]" placeholder="Node title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" data-viswiz-node-title /></label>
            <label>Short label <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[label][]" placeholder="Optional short label" value="<?php echo esc_attr( $node['label'] ?? '' ); ?>" class="regular-text" /></label>
            <label>Node type <select name="<?php echo esc_attr( $name_prefix ); ?>[node_type][]" data-viswiz-node-type><option value="">Select node type</option><?php foreach ( $node_types as $type_key => $type_label ) : ?><option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $node_type, $type_key ); ?>><?php echo esc_html( $type_label ); ?></option><?php endforeach; ?></select></label>
            <label>Node subtype <select name="<?php echo esc_attr( $name_prefix ); ?>[node_subtype][]" data-viswiz-node-subtype><option value="">No subtype</option><?php foreach ( $node_subtypes as $subtype_key => $subtype_label ) : ?><option value="<?php echo esc_attr( $subtype_key ); ?>" <?php selected( $node_subtype, $subtype_key ); ?>><?php echo esc_html( $subtype_label ); ?></option><?php endforeach; ?><option value="proposed" <?php selected( $node_subtype, 'proposed' ); ?>>Other / proposed subtype</option></select></label>
            <label>Main image <span class="viswiz-media-field"><input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[main_image][]" value="<?php echo esc_attr( $main_image ); ?>" data-viswiz-media-value data-viswiz-main-image-value /><button type="button" class="button" data-viswiz-media-select="single">Select/upload</button><span data-viswiz-media-label><?php echo $main_image ? esc_html( '#' . $main_image ) : 'No image selected'; ?></span></span></label>
            <label>Other images <span class="viswiz-media-field"><input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[other_images][]" value="<?php echo esc_attr( $other_images ); ?>" data-viswiz-media-value data-viswiz-other-images-value /><button type="button" class="button" data-viswiz-media-select="multiple">Select/upload</button><span data-viswiz-media-label><?php echo $other_images ? esc_html( $other_images ) : 'No images selected'; ?></span></span></label>
        </div>
        <?php viswiz_render_graph_node_image_gallery( $main_image, $other_images ); ?>
        <div class="viswiz-proposed-subtype" data-viswiz-proposed-subtype <?php echo $is_proposed_subtype ? '' : 'hidden'; ?>>
            <strong>Proposed subtype workflow</strong>
            <p class="description">Editors should propose new subtypes instead of adding top-level node types for routine work. Admins and superadmins can approve, merge, rename, or reject proposals.</p>
            <label>Proposed label <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[proposed_subtype_label][]" value="<?php echo esc_attr( $node['proposed_subtype_label'] ?? '' ); ?>" class="regular-text" /></label>
            <label>Reason <textarea name="<?php echo esc_attr( $name_prefix ); ?>[proposed_subtype_reason][]" rows="2"><?php echo esc_textarea( $node['proposed_subtype_reason'] ?? '' ); ?></textarea></label>
            <label>Example entity <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[proposed_subtype_example][]" value="<?php echo esc_attr( $node['proposed_subtype_example'] ?? '' ); ?>" class="regular-text" /></label>
            <label>Why existing types do not fit <textarea name="<?php echo esc_attr( $name_prefix ); ?>[proposed_subtype_gap][]" rows="2"><?php echo esc_textarea( $node['proposed_subtype_gap'] ?? '' ); ?></textarea></label>
            <label>Review status <select name="<?php echo esc_attr( $name_prefix ); ?>[proposed_subtype_status][]"><option value="proposed" <?php selected( $node['proposed_subtype_status'] ?? 'proposed', 'proposed' ); ?>>Proposed</option><option value="approved" <?php selected( $node['proposed_subtype_status'] ?? '', 'approved' ); ?>>Approved</option><option value="merged" <?php selected( $node['proposed_subtype_status'] ?? '', 'merged' ); ?>>Merged</option><option value="renamed" <?php selected( $node['proposed_subtype_status'] ?? '', 'renamed' ); ?>>Renamed</option><option value="rejected" <?php selected( $node['proposed_subtype_status'] ?? '', 'rejected' ); ?>>Rejected</option></select></label>
        </div>
        <div class="viswiz-full-field viswiz-rich-description-field" data-viswiz-rich-description-editor>
            <label>Formatted description</label>
            <div class="viswiz-rich-description-toolbar" aria-label="Description formatting tools">
                <button type="button" class="button button-small" data-viswiz-description-format="strong">Bold</button>
                <button type="button" class="button button-small" data-viswiz-description-format="em">Italic</button>
                <button type="button" class="button button-small" data-viswiz-description-format="link">Link</button>
                <button type="button" class="button button-small" data-viswiz-description-format="ul">Bullets</button>
                <button type="button" class="button button-small" data-viswiz-description-format="blockquote">Quote</button>
                <button type="button" class="button button-small" data-viswiz-description-format="p">Paragraph</button>
            </div>
            <textarea name="<?php echo esc_attr( $name_prefix ); ?>[description][]" rows="8" class="large-text viswiz-rich-description-textarea" data-viswiz-node-description><?php echo esc_textarea( wp_kses_post( $description ) ); ?></textarea>
            <p class="description">Uses a modal-safe WYSIWYG editor. Formatting is preserved in the public node detail modal. Keyboard: Tab moves fields, Ctrl/⌘+Enter saves and closes.</p>
        </div>
        <div class="viswiz-custom-labels">
            <strong>Custom labels</strong>
            <?php foreach ( $custom_labels as $custom ) : ?>
                <div class="viswiz-custom-label-row">
                    <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[custom_key][<?php echo esc_attr( $index ); ?>][]" placeholder="Label key" pattern="[A-Za-z0-9_-]+" value="<?php echo esc_attr( $custom['key'] ?? '' ); ?>" />
                    <select name="<?php echo esc_attr( $name_prefix ); ?>[custom_type][<?php echo esc_attr( $index ); ?>][]"><option value="short" <?php selected( $custom['type'] ?? '', 'short' ); ?>>Short text</option><option value="url" <?php selected( $custom['type'] ?? '', 'url' ); ?>>Hyperlink</option><option value="long" <?php selected( $custom['type'] ?? '', 'long' ); ?>>Long text</option><option value="formatted" <?php selected( $custom['type'] ?? '', 'formatted' ); ?>>Formatted text</option></select>
                    <textarea name="<?php echo esc_attr( $name_prefix ); ?>[custom_value][<?php echo esc_attr( $index ); ?>][]" placeholder="Value" rows="2"><?php echo esc_textarea( $custom['value'] ?? '' ); ?></textarea>
                    <button type="button" class="button viswiz-remove-custom-label">Remove</button>
                </div>
            <?php endforeach; ?>
            <button type="button" class="button viswiz-add-custom-label">Add custom label</button>
        </div>
        <div class="viswiz-node-relation-tools" data-viswiz-node-relation-tools>
            <strong>Relations for this node</strong>
            <div class="viswiz-node-relation-actions">
                <button type="button" class="button" data-viswiz-node-add-relation data-relation-mode="outgoing">Add outgoing relation</button>
                <button type="button" class="button" data-viswiz-node-add-relation data-relation-mode="incoming">Add incoming relation</button>
                <button type="button" class="button" data-viswiz-create-connected-node>Create connected node</button>
            </div>
            <div class="viswiz-node-relation-list" data-viswiz-node-relation-list></div>
            <div class="viswiz-node-relation-editor" data-viswiz-node-relation-editor hidden></div>
        </div>
        <div class="viswiz-node-validation" data-viswiz-node-validation></div>
        <p class="viswiz-node-actions"><button type="button" class="button button-primary" data-viswiz-save-node>Save node</button> <button type="button" class="button" data-viswiz-close-node>Save &amp; close</button> <button type="button" class="button" data-viswiz-save-node-add-relation>Add relation</button> <button type="button" class="button" data-viswiz-save-node-add-connected>Create connected node</button> <span class="description" data-viswiz-node-autosave-status></span> <button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button viswiz-remove-row">Remove node</button></p>
    </details>
    <?php
}

function viswiz_render_graph_node_datalist( $nodes, $datalist_id ) {
    ?>
    <datalist id="<?php echo esc_attr( $datalist_id ); ?>" data-viswiz-node-options>
        <?php foreach ( $nodes as $node_index => $node ) : ?>
            <?php $node_id = viswiz_get_node_auto_id( $node, $node_index ); ?>
            <?php $node_title = $node['title'] ?? ( $node['label'] ?? $node_id ); ?>
            <option value="<?php echo esc_attr( $node_title ); ?>" label="<?php echo esc_attr( $node_id ); ?>" data-node-id="<?php echo esc_attr( $node_id ); ?>" data-node-search="<?php echo esc_attr( strtolower( wp_strip_all_tags( implode( ' ', array( $node_title, $node['label'] ?? '', $node_id ) ) ) ) ); ?>"><?php echo esc_html( $node_id ); ?></option>
        <?php endforeach; ?>
    </datalist>
    <?php
}

function viswiz_get_graph_node_display_name( $node_key, $nodes = array() ) {
    $node_key = (string) $node_key;
    foreach ( $nodes as $node_index => $node ) {
        $node_id = viswiz_get_node_auto_id( $node, $node_index );
        if ( $node_key === $node_id ) {
            return $node['title'] ?? ( $node['label'] ?? $node_id );
        }
    }
    return $node_key;
}


function viswiz_render_graph_node_select( $name, $selected, $nodes = array(), $attributes = array() ) {
    $selected = (string) $selected;
    $selected_found = false;
    $attributes = array_merge(
        array(
            'data-viswiz-smart-search' => 'node',
            'data-viswiz-smart-placeholder' => __( 'Search existing node…', 'viswiz' ),
            'data-viswiz-smart-empty' => __( 'No matching nodes in this dataset.', 'viswiz' ),
        ),
        $attributes
    );
    ?>
    <select name="<?php echo esc_attr( $name ); ?>" class="regular-text" <?php foreach ( $attributes as $attr => $value ) : ?><?php echo esc_attr( $attr ); ?>="<?php echo esc_attr( $value ); ?>" <?php endforeach; ?>>
        <option value=""><?php esc_html_e( 'Select a node…', 'viswiz' ); ?></option>
        <?php foreach ( $nodes as $node_index => $node ) : ?>
            <?php
            $node_id = viswiz_get_node_auto_id( $node, $node_index );
            $node_title = $node['title'] ?? ( $node['label'] ?? $node_id );
            $option_label = $node_id && $node_id !== $node_title ? sprintf( '%s (%s)', $node_title, $node_id ) : $node_title;
            if ( $selected === $node_id ) {
                $selected_found = true;
            }
            ?>
            <option value="<?php echo esc_attr( $node_id ); ?>" data-node-title="<?php echo esc_attr( $node_title ); ?>" data-node-search="<?php echo esc_attr( strtolower( wp_strip_all_tags( implode( ' ', array( $node_title, $node['label'] ?? '', $node_id ) ) ) ) ); ?>" <?php selected( $selected, $node_id ); ?>><?php echo esc_html( $option_label ); ?></option>
        <?php endforeach; ?>
        <?php if ( $selected && ! $selected_found ) : ?>
            <option value="<?php echo esc_attr( $selected ); ?>" selected><?php echo esc_html( $selected ); ?></option>
        <?php endif; ?>
    </select>
    <?php
}

function viswiz_render_graph_link_row( $name_prefix, $link = array(), $index = 0, $dataset_label = '', $node_datalist_id = '', $nodes = array() ) {
    $from = $link['from'] ?? '';
    $to = $link['to'] ?? '';
    $from_display = viswiz_get_graph_node_display_name( $from, $nodes );
    $to_display = viswiz_get_graph_node_display_name( $to, $nodes );
    ?>
    <details class="viswiz-relation-card viswiz-sortable-card" data-viswiz-relation-card data-relation-index="<?php echo esc_attr( $index ); ?>" data-relation-from="<?php echo esc_attr( $from ); ?>" data-relation-to="<?php echo esc_attr( $to ); ?>">
        <summary><span class="viswiz-drag-handle" aria-hidden="true">↕</span><strong><?php echo esc_html( $link['label'] ?? 'Relation' ); ?></strong><span class="viswiz-relation-card-summary-meta"><?php echo esc_html( trim( $from_display . ' → ' . $to_display, ' →' ) ?: 'No endpoints' ); ?></span><?php if ( $dataset_label ) : ?><span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span><?php endif; ?></summary>
        <div class="viswiz-relation-grid">
            <label>From <?php viswiz_render_graph_node_select( $name_prefix . '[from][]', $from, $nodes, array( 'data-viswiz-relation-from' => '1' ) ); ?></label>
            <label>To <?php viswiz_render_graph_node_select( $name_prefix . '[to][]', $to, $nodes, array( 'data-viswiz-relation-to' => '1' ) ); ?></label>
            <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[label][]" placeholder="Relation label" value="<?php echo esc_attr( $link['label'] ?? '' ); ?>" class="regular-text" />
            <select name="<?php echo esc_attr( $name_prefix ); ?>[direction][]"><option value="directed" <?php selected( $link['direction'] ?? 'directed', 'directed' ); ?>>Directed</option><option value="undirected" <?php selected( $link['direction'] ?? '', 'undirected' ); ?>>Undirected</option><option value="bidirectional" <?php selected( $link['direction'] ?? '', 'bidirectional' ); ?>>Bidirectional</option></select>
            <input type="number" name="<?php echo esc_attr( $name_prefix ); ?>[intensity][]" placeholder="Intensity" value="<?php echo esc_attr( $link['intensity'] ?? '1' ); ?>" min="0" step="0.01" />
            <?php echo viswiz_render_relation_type_field( $name_prefix . '[relation_type][]', $link['relation_type'] ?? '' ); ?>
        </div>
        <div class="viswiz-relation-warning" data-viswiz-relation-warning></div>
        <p><button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button" data-viswiz-reverse-relation>Reverse relation</button> <button type="button" class="button viswiz-remove-row">Remove relation</button> <button type="button" class="button" data-viswiz-close-relation>Save &amp; close</button></p>
    </details>
    <?php
}
function viswiz_sanitize_json_option( $value ) {
    $decoded = json_decode( wp_unslash( $value ), true );
    if ( json_last_error() !== JSON_ERROR_NONE ) {
        add_settings_error( 'viswiz_settings', 'viswiz_invalid_json', 'Invalid JSON provided.' );
        return '[]';
    }

    return viswiz_json_encode( $decoded );
}

function viswiz_sanitize_id_array( $value ) {
    if ( ! is_array( $value ) ) {
        $value = array();
    }
    $value = array_map( 'absint', $value );
    return array_values( array_filter( $value ) );
}

function viswiz_sanitize_progress_option( $value ) {
    if ( ! is_array( $value ) ) {
        return viswiz_sanitize_json_option( $value );
    }

    $labels = $value['label'] ?? array();
    $values = $value['value'] ?? array();
    $targets = $value['target'] ?? array();
    $targets_name = $value['targets']['name'] ?? array();
    $targets_value = $value['targets']['value'] ?? array();
    $sanitized = array();

    foreach ( $labels as $index => $label ) {
        $label = sanitize_text_field( $label );
        $val = isset( $values[ $index ] ) ? (float) $values[ $index ] : 0;
        $target = isset( $targets[ $index ] ) ? (float) $targets[ $index ] : 0;
        $named_targets = array();
        $names = $targets_name[ $index ] ?? array();
        $values_for_targets = $targets_value[ $index ] ?? array();
        foreach ( $names as $target_index => $name ) {
            $name = sanitize_text_field( $name );
            $target_value = isset( $values_for_targets[ $target_index ] ) ? (float) $values_for_targets[ $target_index ] : 0;
            if ( $name === '' && $target_value === 0.0 ) {
                continue;
            }
            $named_targets[] = array(
                'name' => $name,
                'value' => $target_value,
            );
        }
        if ( $label === '' && $val === 0.0 && $target === 0.0 && empty( $named_targets ) ) {
            continue;
        }
        if ( empty( $named_targets ) && $target > 0 ) {
            $named_targets[] = array(
                'name' => 'Target',
                'value' => $target,
            );
        }
        $max_target = $target;
        foreach ( $named_targets as $named_target ) {
            if ( $named_target['value'] > $max_target ) {
                $max_target = $named_target['value'];
            }
        }
        $sanitized[] = array(
            'label' => $label,
            'value' => $val,
            'target' => $max_target,
            'targets' => $named_targets,
        );
    }

    return viswiz_json_encode( $sanitized );
}

function viswiz_sanitize_pie_option( $value ) {
    if ( ! is_array( $value ) ) {
        return viswiz_sanitize_json_option( $value );
    }

    $labels = $value['label'] ?? array();
    $values = $value['value'] ?? array();
    $colors = $value['color'] ?? array();
    $sanitized = array();

    foreach ( $labels as $index => $label ) {
        $label = sanitize_text_field( $label );
        $val = isset( $values[ $index ] ) ? (float) $values[ $index ] : 0;
        $color = isset( $colors[ $index ] ) ? sanitize_hex_color( $colors[ $index ] ) : '';
        if ( $label === '' && $val === 0.0 ) {
            continue;
        }
        $sanitized[] = array(
            'label' => $label,
            'value' => $val,
            'color' => $color ?: '',
        );
    }

    return viswiz_json_encode( $sanitized );
}

function viswiz_sanitize_diagram_option( $value ) {
    if ( ! is_array( $value ) ) {
        return viswiz_sanitize_json_option( $value );
    }

    $titles = $value['title'] ?? array();
    $items_by_section = $value['items'] ?? array();
    $sanitized = array();

    foreach ( $titles as $index => $title ) {
        $title = sanitize_text_field( $title );
        $items = $items_by_section[ $index ] ?? array();
        $clean_items = array();
        foreach ( $items as $item ) {
            $item = sanitize_text_field( $item );
            if ( $item !== '' ) {
                $clean_items[] = $item;
            }
        }
        if ( $title === '' && empty( $clean_items ) ) {
            continue;
        }
        $sanitized[] = array(
            'title' => $title,
            'items' => $clean_items,
        );
    }

    return viswiz_json_encode( $sanitized );
}

function viswiz_register_node_subtype_in_schema( $node_type, $label, $preferred_slug = '' ) {
    $node_type = sanitize_key( $node_type );
    $label = sanitize_text_field( $label );
    if ( $node_type === '' || $label === '' ) {
        return '';
    }
    $schema = viswiz_get_node_type_schema();
    if ( ! isset( $schema[ $node_type ] ) ) {
        return '';
    }
    $slug = sanitize_key( $preferred_slug ?: remove_accents( $label ) );
    if ( $slug === '' ) {
        $slug = 'custom_subtype';
    }
    $base = $slug;
    $i = 2;
    while ( isset( $schema[ $node_type ]['subtypes'][ $slug ] ) && strcasecmp( $schema[ $node_type ]['subtypes'][ $slug ], $label ) !== 0 ) {
        $slug = $base . '_' . $i;
        $i++;
    }
    $schema[ $node_type ]['subtypes'][ $slug ] = $label;
    update_option( VISWIZ_OPTION_NODE_TYPE_SCHEMA, viswiz_normalize_node_type_schema( $schema ) );
    return $slug;
}

function viswiz_maybe_approve_node_subtype_payload( $node_type, $node_subtype, $proposal_label, $proposal_status ) {
    $proposal_status = sanitize_key( $proposal_status );
    if ( in_array( $proposal_status, array( 'approved', 'renamed' ), true ) && $node_subtype === 'proposed' ) {
        $approved_slug = viswiz_register_node_subtype_in_schema( $node_type, $proposal_label );
        if ( $approved_slug ) {
            return $approved_slug;
        }
    }
    return $node_subtype;
}

function viswiz_sanitize_graph_option( $value ) {
    if ( ! is_array( $value ) ) {
        return viswiz_sanitize_json_option( $value );
    }

    $nodes = $value['nodes'] ?? array();
    $links = $value['links'] ?? array();
    $sanitized_nodes = array();
    $sanitized_links = array();

    $node_ids = $nodes['id'] ?? array();
    $node_labels = $nodes['label'] ?? array();
    $node_titles = $nodes['title'] ?? array();
    $node_descriptions = $nodes['description'] ?? array();
    $node_types = $nodes['node_type'] ?? ( $nodes['entity_type'] ?? array() );
    $node_subtypes = $nodes['node_subtype'] ?? array();
    $proposed_labels = $nodes['proposed_subtype_label'] ?? array();
    $proposed_reasons = $nodes['proposed_subtype_reason'] ?? array();
    $proposed_examples = $nodes['proposed_subtype_example'] ?? array();
    $proposed_gaps = $nodes['proposed_subtype_gap'] ?? array();
    $proposed_statuses = $nodes['proposed_subtype_status'] ?? array();
    $node_main_images = $nodes['main_image'] ?? array();
    $node_other_images = $nodes['other_images'] ?? array();
    $custom_keys = $nodes['custom_key'] ?? array();
    $custom_types = $nodes['custom_type'] ?? array();
    $custom_values = $nodes['custom_value'] ?? array();
    $node_count = max( count( $node_ids ), count( $node_titles ), count( $node_labels ) );
    for ( $index = 0; $index < $node_count; $index++ ) {
        $title = sanitize_text_field( $node_titles[ $index ] ?? '' );
        $label = sanitize_text_field( $node_labels[ $index ] ?? '' );
        $description = wp_kses_post( $node_descriptions[ $index ] ?? '' );
        $node_type = sanitize_key( $node_types[ $index ] ?? '' );
        $node_subtype = sanitize_key( $node_subtypes[ $index ] ?? '' );
        $legacy_mapping = viswiz_get_legacy_graph_node_type_mapping();
        if ( isset( $legacy_mapping[ $node_type ] ) ) {
            list( $node_type, $mapped_subtype ) = $legacy_mapping[ $node_type ];
            if ( $node_subtype === '' ) {
                $node_subtype = $mapped_subtype;
            }
        }
        list( $node_type, $node_subtype ) = viswiz_sanitize_node_type_payload( $node_type, $node_subtype );
        $proposed_status = sanitize_key( $proposed_statuses[ $index ] ?? 'proposed' );
        if ( ! in_array( $proposed_status, array( 'proposed', 'approved', 'merged', 'renamed', 'rejected' ), true ) ) {
            $proposed_status = 'proposed';
        }
        $node_subtype = viswiz_maybe_approve_node_subtype_payload( $node_type, $node_subtype, $proposed_labels[ $index ] ?? '', $proposed_status );
        list( $node_type, $node_subtype ) = viswiz_sanitize_node_type_payload( $node_type, $node_subtype );
        if ( $title === '' && $label === '' && $description === '' ) {
            continue;
        }
        $node_id = sanitize_key( $node_ids[ $index ] ?? '' );
        if ( $node_id === '' ) {
            $node_id = 'node-' . ( count( $sanitized_nodes ) + 1 );
        }
        $custom_labels = array();
        foreach ( $custom_keys[ $index ] ?? array() as $custom_index => $custom_key ) {
            $custom_key = sanitize_key( $custom_key );
            $custom_type = sanitize_key( $custom_types[ $index ][ $custom_index ] ?? 'short' );
            if ( ! in_array( $custom_type, array( 'short', 'url', 'long', 'formatted' ), true ) ) {
                $custom_type = 'short';
            }
            $custom_value = $custom_values[ $index ][ $custom_index ] ?? '';
            if ( $custom_type === 'url' ) {
                $custom_value = esc_url_raw( $custom_value );
            } elseif ( $custom_type === 'formatted' ) {
                $custom_value = wp_kses_post( $custom_value );
            } else {
                $custom_value = sanitize_textarea_field( $custom_value );
            }
            if ( $custom_key !== '' || $custom_value !== '' ) {
                $custom_labels[] = array( 'key' => $custom_key, 'type' => $custom_type, 'value' => $custom_value );
            }
        }
        $sanitized_nodes[] = array(
            'id' => $node_id,
            'label' => $label ?: $title,
            'title' => $title,
            'description' => $description,
            'node_type' => $node_type,
            'node_subtype' => $node_subtype,
            'entity_type' => $node_type,
            'proposed_subtype_label' => sanitize_text_field( $proposed_labels[ $index ] ?? '' ),
            'proposed_subtype_reason' => sanitize_textarea_field( $proposed_reasons[ $index ] ?? '' ),
            'proposed_subtype_example' => sanitize_text_field( $proposed_examples[ $index ] ?? '' ),
            'proposed_subtype_gap' => sanitize_textarea_field( $proposed_gaps[ $index ] ?? '' ),
            'proposed_subtype_status' => $proposed_status,
            'main_image' => absint( $node_main_images[ $index ] ?? 0 ),
            'other_images' => array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $node_other_images[ $index ] ?? '' ) ) ) ) ),
            'custom_labels' => $custom_labels,
        );
    }

    $node_lookup = array();
    foreach ( $sanitized_nodes as $node ) {
        $node_lookup[ strtolower( $node['id'] ) ] = $node['id'];
        $node_lookup[ strtolower( $node['title'] ) ] = $node['id'];
        $node_lookup[ strtolower( $node['label'] ) ] = $node['id'];
    }

    $link_from = $links['from'] ?? array();
    $link_to = $links['to'] ?? array();
    $link_label = $links['label'] ?? array();
    foreach ( $link_from as $index => $from ) {
        $from = sanitize_text_field( $from );
        $to = sanitize_text_field( $link_to[ $index ] ?? '' );
        $from = $node_lookup[ strtolower( $from ) ] ?? sanitize_key( $from );
        $to = $node_lookup[ strtolower( $to ) ] ?? sanitize_key( $to );
        $label = sanitize_text_field( $link_label[ $index ] ?? '' );
        if ( $from === '' && $to === '' && $label === '' ) {
            continue;
        }
        $directions = $links['direction'] ?? array();
        $intensities = $links['intensity'] ?? array();
        $relation_types = $links['relation_type'] ?? array();
        $direction = sanitize_key( $directions[ $index ] ?? 'directed' );
        if ( ! in_array( $direction, array( 'directed', 'undirected', 'bidirectional' ), true ) ) {
            $direction = 'directed';
        }
        $sanitized_links[] = array(
            'from' => $from,
            'to' => $to,
            'label' => $label,
            'direction' => $direction,
            'intensity' => isset( $intensities[ $index ] ) ? (float) $intensities[ $index ] : 1,
            'relation_type' => sanitize_key( $relation_types[ $index ] ?? '' ),
        );
    }

    return viswiz_json_encode(
        array(
            'nodes' => $sanitized_nodes,
            'links' => $sanitized_links,
        )
    );
}

function viswiz_get_manual_progress() {
    $raw = get_option( VISWIZ_OPTION_PROGRESS_MANUAL, '[]' );
    return json_decode( $raw, true ) ?: array();
}

function viswiz_get_manual_pie() {
    $raw = get_option( VISWIZ_OPTION_PIE_MANUAL, '[]' );
    return json_decode( $raw, true ) ?: array();
}

function viswiz_get_diagram_data() {
    $raw = get_option( VISWIZ_OPTION_DIAGRAM, '[]' );
    return json_decode( $raw, true ) ?: array();
}

function viswiz_get_graph_data() {
    $raw = get_option( VISWIZ_OPTION_GRAPH, '[]' );
    return json_decode( $raw, true ) ?: array();
}


function viswiz_get_graph_node_subtypes_for_script() {
    $subtypes = array();
    foreach ( viswiz_get_graph_node_subtypes() as $node_type => $options ) {
        $subtypes[ $node_type ] = array();
        foreach ( $options as $subtype_key => $subtype_label ) {
            $subtypes[ $node_type ][] = array(
                'value' => $subtype_key,
                'label' => $subtype_label,
            );
        }
    }

    return $subtypes;
}


function viswiz_graph_payload_has_meaningful_links( $graph_data ) {
    if ( ! is_array( $graph_data ) || ! isset( $graph_data['links'] ) || ! is_array( $graph_data['links'] ) ) {
        return false;
    }

    $links = $graph_data['links'];
    $fields = array( 'from', 'to', 'label', 'relation_type' );
    $max = 0;
    foreach ( $fields as $field ) {
        if ( isset( $links[ $field ] ) && is_array( $links[ $field ] ) ) {
            $max = max( $max, count( $links[ $field ] ) );
        }
    }

    for ( $index = 0; $index < $max; $index++ ) {
        foreach ( $fields as $field ) {
            $value = $links[ $field ][ $index ] ?? '';
            if ( trim( (string) $value ) !== '' ) {
                return true;
            }
        }
    }

    return false;
}

function viswiz_graph_links_rows_to_form_payload( $links ) {
    $payload = array(
        'from' => array(),
        'to' => array(),
        'label' => array(),
        'direction' => array(),
        'intensity' => array(),
        'relation_type' => array(),
    );

    if ( ! is_array( $links ) ) {
        return $payload;
    }

    foreach ( $links as $link ) {
        if ( ! is_array( $link ) ) {
            continue;
        }
        $payload['from'][] = $link['from'] ?? '';
        $payload['to'][] = $link['to'] ?? '';
        $payload['label'][] = $link['label'] ?? '';
        $payload['direction'][] = $link['direction'] ?? 'directed';
        $payload['intensity'][] = $link['intensity'] ?? 1;
        $payload['relation_type'][] = $link['relation_type'] ?? '';
    }

    return $payload;
}

function viswiz_get_existing_graph_data_for_post( $post_id ) {
    $existing_graph_data = json_decode( get_post_meta( $post_id, 'viswiz_graph_data', true ) ?: '[]', true );
    return is_array( $existing_graph_data ) ? $existing_graph_data : array();
}

function viswiz_get_table_graph_data_for_post( $post_id ) {
    global $wpdb;
    $post_id = absint( $post_id );
    if ( ! $post_id ) {
        return array();
    }

    viswiz_create_custom_tables();
    $visualization = $wpdb->get_row( $wpdb->prepare( "SELECT id, dataset_id FROM " . viswiz_get_table_name( 'visualization_data' ) . " WHERE post_id = %d", $post_id ), ARRAY_A );
    if ( ! $visualization ) {
        return array();
    }

    $visualization_id = absint( $visualization['id'] ?? 0 );
    $dataset_id = absint( $visualization['dataset_id'] ?? 0 );
    $where = $dataset_id ? $wpdb->prepare( 'dataset_id = %d', $dataset_id ) : $wpdb->prepare( 'visualization_id = %d', $visualization_id );

    $points = $wpdb->get_results( "SELECT * FROM " . viswiz_get_table_name( 'data_points' ) . " WHERE $where ORDER BY sort_order ASC, id ASC", ARRAY_A );
    $relations = $wpdb->get_results( "SELECT * FROM " . viswiz_get_table_name( 'relations' ) . " WHERE $where ORDER BY sort_order ASC, id ASC", ARRAY_A );

    if ( empty( $points ) && empty( $relations ) ) {
        return array();
    }

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
                return array(
                    'from' => $relation['from_key'],
                    'to' => $relation['to_key'],
                    'label' => $relation['label'],
                    'direction' => $relation['direction'],
                    'intensity' => (float) $relation['intensity'],
                    'relation_type' => $relation['relation_type'],
                );
            },
            $relations
        ),
    );
}

function viswiz_get_existing_graph_data_with_table_fallback( $post_id ) {
    $graph_data = viswiz_get_existing_graph_data_for_post( $post_id );
    $has_nodes = ! empty( $graph_data['nodes'] ) && is_array( $graph_data['nodes'] );
    $has_links = ! empty( $graph_data['links'] ) && is_array( $graph_data['links'] );
    if ( $has_nodes && $has_links ) {
        return $graph_data;
    }

    $table_graph_data = viswiz_get_table_graph_data_for_post( $post_id );
    if ( ! is_array( $table_graph_data ) || empty( $table_graph_data ) ) {
        return is_array( $graph_data ) ? $graph_data : array();
    }

    if ( ! $has_nodes && ! empty( $table_graph_data['nodes'] ) && is_array( $table_graph_data['nodes'] ) ) {
        $graph_data['nodes'] = $table_graph_data['nodes'];
    }
    if ( ! $has_links && ! empty( $table_graph_data['links'] ) && is_array( $table_graph_data['links'] ) ) {
        $graph_data['links'] = $table_graph_data['links'];
    }

    return is_array( $graph_data ) ? $graph_data : array();
}

function viswiz_update_single_graph_node_data( $post_id, $raw_graph_data, $original_node_id ) {
    $existing_graph_data = viswiz_get_existing_graph_data_with_table_fallback( $post_id );
    $existing_nodes = isset( $existing_graph_data['nodes'] ) && is_array( $existing_graph_data['nodes'] ) ? $existing_graph_data['nodes'] : array();
    $existing_links = isset( $existing_graph_data['links'] ) && is_array( $existing_graph_data['links'] ) ? $existing_graph_data['links'] : array();

    $single_graph_json = viswiz_sanitize_graph_option(
        array(
            'nodes' => $raw_graph_data['nodes'] ?? array(),
            'links' => array(),
        )
    );
    $single_graph_data = json_decode( $single_graph_json, true );
    $incoming_node = $single_graph_data['nodes'][0] ?? null;
    if ( ! is_array( $incoming_node ) ) {
        return new WP_Error( 'viswiz_missing_node', __( 'Missing node data.', 'viswiz' ) );
    }

    $original_node_id = sanitize_key( $original_node_id );
    $incoming_node_id = sanitize_key( $incoming_node['id'] ?? '' );
    if ( $original_node_id === '' ) {
        $original_node_id = $incoming_node_id;
    }

    $replace_index = null;
    foreach ( $existing_nodes as $index => $node ) {
        if ( ! is_array( $node ) ) {
            continue;
        }
        $candidate_id = sanitize_key( $node['id'] ?? '' );
        if ( $candidate_id !== '' && ( $candidate_id === $original_node_id || $candidate_id === $incoming_node_id ) ) {
            $replace_index = $index;
            break;
        }
    }

    if ( $replace_index === null ) {
        foreach ( $existing_nodes as $index => $node ) {
            if ( ! is_array( $node ) ) {
                continue;
            }
            $candidate_title = strtolower( sanitize_text_field( $node['title'] ?? '' ) );
            $candidate_label = strtolower( sanitize_text_field( $node['label'] ?? '' ) );
            $incoming_title = strtolower( sanitize_text_field( $incoming_node['title'] ?? '' ) );
            $incoming_label = strtolower( sanitize_text_field( $incoming_node['label'] ?? '' ) );
            if ( $incoming_title !== '' && ( $candidate_title === $incoming_title || $candidate_label === $incoming_title ) ) {
                $replace_index = $index;
                break;
            }
            if ( $incoming_label !== '' && ( $candidate_title === $incoming_label || $candidate_label === $incoming_label ) ) {
                $replace_index = $index;
                break;
            }
        }
    }

    if ( $replace_index === null ) {
        $existing_nodes[] = $incoming_node;
    } else {
        $existing_nodes[ $replace_index ] = array_merge( is_array( $existing_nodes[ $replace_index ] ) ? $existing_nodes[ $replace_index ] : array(), $incoming_node );
    }

    if ( $original_node_id !== '' && $incoming_node_id !== '' && $original_node_id !== $incoming_node_id ) {
        foreach ( $existing_links as $index => $link ) {
            if ( ! is_array( $link ) ) {
                continue;
            }
            if ( sanitize_key( $link['from'] ?? '' ) === $original_node_id ) {
                $existing_links[ $index ]['from'] = $incoming_node_id;
            }
            if ( sanitize_key( $link['to'] ?? '' ) === $original_node_id ) {
                $existing_links[ $index ]['to'] = $incoming_node_id;
            }
        }
    }

    return array(
        'nodes' => array_values( $existing_nodes ),
        'links' => array_values( $existing_links ),
    );
}

function viswiz_sync_graph_tables_from_saved_meta( $post_id, $graph_data ) {
    $type = get_post_meta( $post_id, 'viswiz_type', true ) ?: 'graph';
    if ( ! viswiz_is_graph_like_type( $type ) ) {
        return;
    }

    viswiz_save_visualization_tables(
        $post_id,
        array(
            'type' => $type,
            'dataset_id' => (int) get_post_meta( $post_id, 'viswiz_dataset_id', true ),
            'new_dataset_name' => '',
            'legend' => get_post_meta( $post_id, 'viswiz_legend', true ) ?: '',
            'axis_labels' => get_post_meta( $post_id, 'viswiz_axis_labels', true ) ?: '',
            'other_settings' => get_post_meta( $post_id, 'viswiz_other_settings', true ) ?: '',
            'theme' => viswiz_get_visualization_format_colors( $post_id ),
            'manual_progress' => json_decode( get_post_meta( $post_id, 'viswiz_manual_progress', true ) ?: '[]', true ) ?: array(),
            'manual_pie' => json_decode( get_post_meta( $post_id, 'viswiz_manual_pie', true ) ?: '[]', true ) ?: array(),
            'diagram_data' => json_decode( get_post_meta( $post_id, 'viswiz_diagram_data', true ) ?: '[]', true ) ?: array(),
            'graph_data' => is_array( $graph_data ) ? $graph_data : array(),
        )
    );
}

function viswiz_remap_graph_links_to_current_nodes( $links, $old_nodes, $new_nodes ) {
    if ( ! is_array( $links ) ) {
        return array();
    }

    $id_map = array();
    foreach ( $new_nodes as $index => $new_node ) {
        $new_id = sanitize_key( $new_node['id'] ?? '' );
        if ( $new_id === '' || ! isset( $old_nodes[ $index ] ) || ! is_array( $old_nodes[ $index ] ) ) {
            continue;
        }

        $old_candidates = array(
            $old_nodes[ $index ]['id'] ?? '',
            $old_nodes[ $index ]['title'] ?? '',
            $old_nodes[ $index ]['label'] ?? '',
        );
        foreach ( $old_candidates as $old_candidate ) {
            $old_key = sanitize_key( $old_candidate );
            if ( $old_key !== '' ) {
                $id_map[ strtolower( $old_key ) ] = $new_id;
            }
        }
    }

    $remapped = array();
    foreach ( $links as $link ) {
        if ( ! is_array( $link ) ) {
            continue;
        }
        $from_key = strtolower( sanitize_key( $link['from'] ?? '' ) );
        $to_key = strtolower( sanitize_key( $link['to'] ?? '' ) );
        if ( $from_key !== '' && isset( $id_map[ $from_key ] ) ) {
            $link['from'] = $id_map[ $from_key ];
        }
        if ( $to_key !== '' && isset( $id_map[ $to_key ] ) ) {
            $link['to'] = $id_map[ $to_key ];
        }
        $remapped[] = $link;
    }

    return $remapped;
}

function viswiz_preserve_existing_graph_links_for_node_autosave( $post_id, $raw_graph_data, $sanitized_graph_data, $context = '' ) {
    if ( ! is_array( $sanitized_graph_data ) ) {
        $sanitized_graph_data = array();
    }

    $links_are_meaningful = viswiz_graph_payload_has_meaningful_links( $raw_graph_data );
    $links_key_missing = ! is_array( $raw_graph_data ) || ! isset( $raw_graph_data['links'] );

    $existing_graph_data = viswiz_get_existing_graph_data_for_post( $post_id );
    $existing_links = ( isset( $existing_graph_data['links'] ) && is_array( $existing_graph_data['links'] ) ) ? $existing_graph_data['links'] : array();
    $existing_nodes = ( isset( $existing_graph_data['nodes'] ) && is_array( $existing_graph_data['nodes'] ) ) ? $existing_graph_data['nodes'] : array();
    $new_nodes = ( isset( $sanitized_graph_data['nodes'] ) && is_array( $sanitized_graph_data['nodes'] ) ) ? $sanitized_graph_data['nodes'] : array();

    /*
     * Node edit/autosave is deliberately node-only. It must never treat a missing,
     * partial, stale, or empty links payload as an instruction to delete relations.
     * The only relation-side change allowed in this path is endpoint remapping when
     * a node ID was intentionally renamed.
     */
    if ( $context === 'node' ) {
        $sanitized_graph_data['links'] = viswiz_remap_graph_links_to_current_nodes(
            $existing_links,
            $existing_nodes,
            $new_nodes
        );
        return $sanitized_graph_data;
    }

    /*
     * Full post saves and relation autosaves may replace links only when they carry
     * a meaningful relation payload. When a node ID has changed during the same
     * submit, remap submitted relation endpoints from the previous node identifiers
     * to the current node identifiers before saving. This protects title/ID edits
     * from orphaning existing relations.
     */
    if ( $links_key_missing || ! $links_are_meaningful ) {
        $sanitized_graph_data['links'] = viswiz_remap_graph_links_to_current_nodes(
            $existing_links,
            $existing_nodes,
            $new_nodes
        );
        return $sanitized_graph_data;
    }

    if ( ! empty( $sanitized_graph_data['links'] ) ) {
        $sanitized_graph_data['links'] = viswiz_remap_graph_links_to_current_nodes(
            $sanitized_graph_data['links'],
            $existing_nodes,
            $new_nodes
        );
    }

    return $sanitized_graph_data;
}

function viswiz_sanitize_node_type_payload( $node_type, $node_subtype ) {
    $node_type = sanitize_key( $node_type );
    $node_subtype = sanitize_key( $node_subtype );
    $allowed_node_types = array_keys( viswiz_get_graph_node_types() );
    $known_node_subtypes = viswiz_get_graph_node_subtypes();

    if ( ! in_array( $node_type, $allowed_node_types, true ) ) {
        return array( '', '' );
    }

    $allowed_subtypes = $known_node_subtypes[ $node_type ] ?? array();
    if ( $node_subtype !== 'proposed' && ! isset( $allowed_subtypes[ $node_subtype ] ) ) {
        $node_subtype = '';
    }

    return array( $node_type, $node_subtype );
}


function viswiz_format_graph_node_description_for_display( $description ) {
    $description = wp_unslash( (string) $description );
    if ( trim( wp_strip_all_tags( $description ) ) === '' && trim( $description ) === '' ) {
        return '';
    }

    $has_html = $description !== wp_strip_all_tags( $description );
    $html = $has_html ? $description : wpautop( $description );

    return wp_kses_post( $html );
}

function viswiz_get_attachment_caption_payload( $image_id ) {
    $caption = wp_get_attachment_caption( $image_id );
    $caption = is_string( $caption ) ? trim( $caption ) : '';
    if ( $caption === '' ) {
        return array(
            'caption' => '',
            'caption_html' => '',
        );
    }

    $caption_html = wp_kses_post( wpautop( $caption ) );

    return array(
        'caption' => sanitize_text_field( wp_strip_all_tags( $caption ) ),
        'caption_html' => $caption_html,
    );
}

function viswiz_prepare_graph_data_for_display( $graph_data ) {
    if ( ! is_array( $graph_data ) ) {
        return array();
    }

    $node_types = viswiz_get_graph_node_types();
    $node_subtypes = viswiz_get_graph_node_subtypes();
    foreach ( $graph_data['nodes'] ?? array() as $index => $node ) {
        list( $node_type, $node_subtype ) = viswiz_normalize_graph_node_type( $node );
        $graph_data['nodes'][ $index ]['node_type'] = $node_type;
        $graph_data['nodes'][ $index ]['node_subtype'] = $node_subtype;
        $graph_data['nodes'][ $index ]['node_type_label'] = $node_types[ $node_type ] ?? '';
        $graph_data['nodes'][ $index ]['node_subtype_label'] = $node_subtype === 'proposed' ? ( $node['proposed_subtype_label'] ?? 'Proposed subtype' ) : ( $node_subtypes[ $node_type ][ $node_subtype ] ?? '' );
        $graph_data['nodes'][ $index ]['entity_type_label'] = $graph_data['nodes'][ $index ]['node_type_label'];
        $graph_data['nodes'][ $index ]['description_html'] = viswiz_format_graph_node_description_for_display( $node['description'] ?? '' );
        $main_image_id = absint( $node['main_image'] ?? 0 );
        $graph_data['nodes'][ $index ]['main_image_url'] = '';
        if ( $main_image_id ) {
            $graph_data['nodes'][ $index ]['main_image_url'] = wp_get_attachment_image_url( $main_image_id, 'medium_large' ) ?: '';
        }

        $other_image_urls = array();
        $image_gallery = array();
        $gallery_image_ids = array();
        if ( $main_image_id ) {
            $gallery_image_ids[] = $main_image_id;
        }
        foreach ( $node['other_images'] ?? array() as $image_id ) {
            $image_id = absint( $image_id );
            if ( $image_id ) {
                $gallery_image_ids[] = $image_id;
            }
        }
        $gallery_image_ids = array_values( array_unique( array_filter( $gallery_image_ids ) ) );
        foreach ( $gallery_image_ids as $image_id ) {
            $large_url = wp_get_attachment_image_url( $image_id, 'large' ) ?: wp_get_attachment_image_url( $image_id, 'medium_large' );
            $medium_url = wp_get_attachment_image_url( $image_id, 'medium' );
            if ( ! $large_url && ! $medium_url ) {
                continue;
            }
            $is_featured = ( $main_image_id && $image_id === $main_image_id );
            if ( ! $is_featured && $medium_url ) {
                $other_image_urls[] = $medium_url;
            }
            $alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
            if ( $alt === '' ) {
                $alt = get_the_title( $image_id );
            }
            $caption_payload = viswiz_get_attachment_caption_payload( $image_id );
            $image_gallery[] = array(
                'id' => $image_id,
                'url' => esc_url_raw( $large_url ?: $medium_url ),
                'thumb_url' => esc_url_raw( $medium_url ?: $large_url ),
                'alt' => sanitize_text_field( $alt ?: ( $node['title'] ?? $node['label'] ?? '' ) ),
                'caption' => $caption_payload['caption'],
                'caption_html' => $caption_payload['caption_html'],
                'featured' => $is_featured,
            );
        }
        $graph_data['nodes'][ $index ]['other_image_urls'] = $other_image_urls;
        $graph_data['nodes'][ $index ]['image_gallery'] = $image_gallery;
    }


    $relation_types = viswiz_get_relation_type_schema();
    foreach ( $graph_data['links'] ?? array() as $index => $link ) {
        $relation_type = sanitize_key( $link['relation_type'] ?? '' );
        $graph_data['links'][ $index ]['relation_type'] = $relation_type;
        $graph_data['links'][ $index ]['relation_type_label'] = $relation_types[ $relation_type ]['label'] ?? $relation_type;
    }

    return $graph_data;
}

function viswiz_get_sales_product_ids() {
    $ids = get_option( VISWIZ_OPTION_SALES_PRODUCTS, array() );
    return viswiz_sanitize_id_array( $ids );
}

function viswiz_get_sales_category_ids() {
    $ids = get_option( VISWIZ_OPTION_SALES_CATEGORIES, array() );
    return viswiz_sanitize_id_array( $ids );
}

function viswiz_get_post_meta_ids( $post_id, $key ) {
    $value = get_post_meta( $post_id, $key, true );
    return viswiz_sanitize_id_array( is_array( $value ) ? $value : array() );
}

function viswiz_parse_id_list( $value ) {
    if ( is_array( $value ) ) {
        return viswiz_sanitize_id_array( $value );
    }
    if ( is_string( $value ) ) {
        $parts = array_map( 'trim', explode( ',', $value ) );
        return viswiz_sanitize_id_array( $parts );
    }
    return array();
}

function viswiz_get_visualization_category_ids( $post_id ) {
    return viswiz_get_post_meta_ids( $post_id, 'viswiz_category_ids' );
}


function viswiz_get_graph_modal_label_fields() {
    return array(
        'node_modal_title_fallback' => array(
            'label' => 'Node title fallback',
            'default' => 'Node details',
            'help' => 'Used when a node has no title or label.',
        ),
        'node_modal_close_label' => array(
            'label' => 'Close button aria label',
            'default' => 'Close node details',
            'help' => 'Accessibility label for the close button.',
        ),
        'node_modal_featured_image_label' => array(
            'label' => 'Featured image badge',
            'default' => 'Featured image',
            'help' => 'Shown above a featured image caption.',
        ),
        'node_modal_previous_image_label' => array(
            'label' => 'Previous image button',
            'default' => 'Previous node image',
            'help' => 'Accessibility label for gallery previous image navigation.',
        ),
        'node_modal_next_image_label' => array(
            'label' => 'Next image button',
            'default' => 'Next node image',
            'help' => 'Accessibility label for gallery next image navigation.',
        ),
        'node_modal_proposed_subtype_reason' => array(
            'label' => 'Proposed subtype reason',
            'default' => 'Proposed subtype reason',
            'help' => 'Label for proposed subtype reason metadata.',
        ),
        'node_modal_example_entity' => array(
            'label' => 'Example entity',
            'default' => 'Example entity',
            'help' => 'Label for proposed subtype example metadata.',
        ),
        'node_modal_proposed_subtype_gap' => array(
            'label' => 'Existing types gap',
            'default' => 'Why existing types do not fit',
            'help' => 'Label for proposed subtype gap metadata.',
        ),
        'node_modal_proposal_status' => array(
            'label' => 'Proposal status',
            'default' => 'Proposal status',
            'help' => 'Label for proposed subtype status metadata.',
        ),
        'node_modal_custom_field' => array(
            'label' => 'Custom field fallback',
            'default' => 'Custom field',
            'help' => 'Used when a custom label has no key.',
        ),
        'node_modal_related_heading' => array(
            'label' => 'Related nodes section heading',
            'default' => 'Related nodes by relation',
            'help' => 'Heading above the grouped related nodes list.',
        ),
        'node_modal_relation_fallback' => array(
            'label' => 'Relation group fallback',
            'default' => 'Relation',
            'help' => 'Used when a relation has no label.',
        ),
        'node_modal_outgoing_relation' => array(
            'label' => 'Outgoing relation fallback',
            'default' => 'Outgoing relation',
            'help' => 'Used when an outgoing relation has no label.',
        ),
        'node_modal_incoming_relation' => array(
            'label' => 'Incoming relation fallback',
            'default' => 'Incoming relation',
            'help' => 'Used when an incoming relation has no label.',
        ),
        'node_modal_direction_outgoing' => array(
            'label' => 'Outgoing direction label',
            'default' => 'Outgoing',
            'help' => 'Shown on related node cards for outgoing directed relations.',
        ),
        'node_modal_direction_incoming' => array(
            'label' => 'Incoming direction label',
            'default' => 'Incoming',
            'help' => 'Shown on related node cards for incoming directed relations.',
        ),
        'node_modal_direction_bidirectional' => array(
            'label' => 'Bidirectional direction label',
            'default' => 'Bidirectional',
            'help' => 'Shown on related node cards for bidirectional relations.',
        ),
        'node_modal_direction_undirected' => array(
            'label' => 'Undirected direction label',
            'default' => 'Undirected',
            'help' => 'Shown on related node cards for undirected relations.',
        ),
        'node_modal_nodes_title_fallback' => array(
            'label' => 'Nodes list fallback title',
            'default' => 'Nodes',
            'help' => 'Used for type/subtype drill-down modals when no label is available.',
        ),
        'node_modal_selected_type_nodes_template' => array(
            'label' => 'Selected type nodes heading template',
            'default' => '{type} nodes',
            'help' => 'Use {type} as a placeholder for the selected type or subtype label.',
        ),
    );
}

function viswiz_get_graph_modal_label_defaults() {
    $defaults = array();
    foreach ( viswiz_get_graph_modal_label_fields() as $key => $field ) {
        $defaults[ $key ] = $field['default'];
    }
    return $defaults;
}

function viswiz_render_graph_modal_label_inputs( $values, $name_prefix, $id_prefix ) {
    $values = is_array( $values ) ? $values : array();
    $defaults = viswiz_get_graph_modal_label_defaults();
    ?>
    <div class="viswiz-modal-label-settings">
        <p class="description">These labels are used in the public full info modal for every node in this visualization. Leave a field empty to use the default text.</p>
        <div class="viswiz-modal-label-grid">
            <?php foreach ( viswiz_get_graph_modal_label_fields() as $key => $field ) : ?>
                <?php
                $value = array_key_exists( $key, $values ) ? $values[ $key ] : ( $defaults[ $key ] ?? '' );
                $field_id = $id_prefix . '_' . $key;
                ?>
                <p class="viswiz-modal-label-field">
                    <label for="<?php echo esc_attr( $field_id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
                    <input type="text" name="<?php echo esc_attr( $name_prefix . '[' . $key . ']' ); ?>" id="<?php echo esc_attr( $field_id ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" data-viswiz-node-modal-label="<?php echo esc_attr( $key ); ?>" data-viswiz-node-modal-default="<?php echo esc_attr( $defaults[ $key ] ?? '' ); ?>" />
                    <?php if ( ! empty( $field['help'] ) ) : ?>
                        <span class="description"><?php echo esc_html( $field['help'] ); ?></span>
                    <?php endif; ?>
                </p>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function viswiz_get_builtin_display_defaults() {
    return array(
        'primary' => '#4caf50',
        'secondary' => '#2196f3',
        'accent' => '#ffc107',
        'background' => '#ffffff',
        'text' => '#333333',
        'node_radius' => 20,
        'link_distance' => 100,
        'charge_strength' => -300,
        'node_card_width' => 150,
        'relation_size_step' => 3,
        'max_relation_size_boost' => 30,
        'node_style' => 'card',
        'node_label_style' => 'rounded',
        'show_node_images' => 1,
        'show_type_badges' => 1,
        'show_graph_toolbar' => 1,
        'show_graph_search' => 1,
        'show_graph_filters' => 1,
        'show_graph_zoom' => 1,
        'show_relation_labels' => 1,
        'show_fullscreen_toggle' => 1,
        'scale_nodes_by_relations' => 0,
        'graph_filter_mode' => 'fade',
    ) + viswiz_get_graph_modal_label_defaults();
}

function viswiz_sanitize_format_colors( $colors, $defaults = array() ) {
    if ( ! is_array( $colors ) ) {
        $colors = array();
    }
    $defaults = array_merge( viswiz_get_builtin_display_defaults(), is_array( $defaults ) ? $defaults : array() );
    $keys = array( 'primary', 'secondary', 'accent', 'background', 'text' );
    $sanitized = array();
    foreach ( $keys as $key ) {
        $value = array_key_exists( $key, $colors ) ? $colors[ $key ] : ( $defaults[ $key ] ?? '' );
        $sanitized[ $key ] = sanitize_hex_color( $value ) ?: ( $defaults[ $key ] ?? '' );
    }

    $number_ranges = array(
        'node_radius' => array( 10, 50, 20 ),
        'link_distance' => array( 50, 300, 100 ),
        'charge_strength' => array( -1000, -50, -300 ),
        'node_card_width' => array( 90, 260, 150 ),
        'relation_size_step' => array( 0, 20, 3 ),
        'max_relation_size_boost' => array( 0, 120, 30 ),
    );
    foreach ( $number_ranges as $key => $range ) {
        $value = array_key_exists( $key, $colors ) ? (int) $colors[ $key ] : (int) ( $defaults[ $key ] ?? $range[2] );
        $sanitized[ $key ] = max( $range[0], min( $range[1], $value ) );
    }

    $node_style = sanitize_key( $colors['node_style'] ?? ( $defaults['node_style'] ?? 'card' ) );
    $sanitized['node_style'] = in_array( $node_style, array( 'card', 'compact', 'round' ), true ) ? $node_style : 'card';

    $label_style = sanitize_key( $colors['node_label_style'] ?? ( $defaults['node_label_style'] ?? 'rounded' ) );
    $sanitized['node_label_style'] = in_array( $label_style, array( 'rounded', 'pill', 'plain' ), true ) ? $label_style : 'rounded';

    foreach ( array( 'show_node_images', 'show_type_badges', 'show_graph_toolbar', 'show_graph_search', 'show_graph_filters', 'show_graph_zoom', 'show_relation_labels', 'show_fullscreen_toggle', 'scale_nodes_by_relations' ) as $key ) {
        $sanitized[ $key ] = array_key_exists( $key, $colors ) ? ( empty( $colors[ $key ] ) ? 0 : 1 ) : ( empty( $defaults[ $key ] ) ? 0 : 1 );
    }

    $filter_mode = sanitize_key( $colors['graph_filter_mode'] ?? ( $defaults['graph_filter_mode'] ?? 'fade' ) );
    $sanitized['graph_filter_mode'] = in_array( $filter_mode, array( 'fade', 'hide' ), true ) ? $filter_mode : 'fade';

    foreach ( viswiz_get_graph_modal_label_defaults() as $key => $default_label ) {
        $value = array_key_exists( $key, $colors ) ? $colors[ $key ] : ( $defaults[ $key ] ?? $default_label );
        $value = sanitize_text_field( $value );
        $sanitized[ $key ] = $value === '' ? $default_label : $value;
    }

    return $sanitized;
}

function viswiz_sanitize_display_defaults( $value ) {
    return viswiz_sanitize_format_colors( is_array( $value ) ? $value : array(), viswiz_get_builtin_display_defaults() );
}

function viswiz_get_display_defaults() {
    $stored = get_option( VISWIZ_OPTION_DISPLAY_DEFAULTS, array() );
    return viswiz_sanitize_format_colors( is_array( $stored ) ? $stored : array(), viswiz_get_builtin_display_defaults() );
}

function viswiz_get_visualization_format_colors( $post_id ) {
    $colors = get_post_meta( $post_id, 'viswiz_format_colors', true );
    return viswiz_sanitize_format_colors( is_array( $colors ) ? $colors : array(), viswiz_get_display_defaults() );
}

function viswiz_get_currency_code() {
    $code = get_option( VISWIZ_OPTION_CURRENCY, '' );
    if ( $code !== '' ) {
        return $code;
    }
    if ( function_exists( 'get_woocommerce_currency' ) ) {
        return get_woocommerce_currency();
    }
    return 'USD';
}

function viswiz_get_currency_symbol() {
    $code = viswiz_get_currency_code();
    return viswiz_get_currency_symbol_for_code( $code );
}

function viswiz_get_currency_symbol_for_code( $code ) {
    if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
        return get_woocommerce_currency_symbol( $code );
    }
    $symbols = array(
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
    );
    return $symbols[ $code ] ?? '$';
}

function viswiz_get_currency_options() {
    if ( function_exists( 'get_woocommerce_currencies' ) ) {
        return get_woocommerce_currencies();
    }
    return array(
        'USD' => 'US Dollar',
        'EUR' => 'Euro',
        'GBP' => 'British Pound',
        'JPY' => 'Japanese Yen',
    );
}

function viswiz_get_period_spec_from_request( WP_REST_Request $request ) {
    $period_value = (int) $request->get_param( 'period_value' );
    $period_unit = sanitize_text_field( $request->get_param( 'period_unit' ) );
    $period_mode = sanitize_text_field( $request->get_param( 'period_mode' ) );
    $period_start = sanitize_text_field( $request->get_param( 'period_start' ) );

    if ( $period_mode === '' ) {
        $period_mode = viswiz_get_period_mode_option();
    }

    if ( $period_mode === 'fixed' ) {
        if ( $period_start === '' ) {
            $period_start = get_option( VISWIZ_OPTION_SALES_PERIOD_START, '' );
        }
        return array( 'fixed', $period_start );
    }

    if ( $period_value <= 0 ) {
        $period_value = (int) get_option( VISWIZ_OPTION_SALES_PERIOD_VALUE, 0 );
        $period_unit = $period_unit !== '' ? $period_unit : viswiz_get_period_unit_option();
    }

    if ( $period_value <= 0 ) {
        $period_value = 30;
        $period_unit = 'day';
    }

    return array( 'relative', $period_value, viswiz_normalize_period_unit( $period_unit ) );
}

function viswiz_get_period_start_date( $period_value, $period_unit ) {
    $period_value = max( 1, (int) $period_value );
    $period_unit = viswiz_normalize_period_unit( $period_unit );
    $date = new DateTime();
    $date->modify( sprintf( '-%d %ss', $period_value, $period_unit ) );
    return $date->format( 'Y-m-d' );
}

function viswiz_get_fixed_period_start_date( $period_start ) {
    if ( $period_start === '' ) {
        return ( new DateTime( '-30 days' ) )->format( 'Y-m-d H:i:s' );
    }
    try {
        $date = new DateTime( $period_start );
        return $date->format( 'Y-m-d H:i:s' );
    } catch ( Exception $exception ) {
        return ( new DateTime( '-30 days' ) )->format( 'Y-m-d H:i:s' );
    }
}

function viswiz_normalize_period_unit( $unit ) {
    $unit = strtolower( trim( $unit ) );
    $allowed = array( 'day', 'month', 'year' );
    if ( ! in_array( $unit, $allowed, true ) ) {
        return 'day';
    }
    return $unit;
}

function viswiz_get_period_unit_option() {
    $unit = get_option( VISWIZ_OPTION_SALES_PERIOD_UNIT, 'day' );
    return viswiz_normalize_period_unit( $unit );
}

function viswiz_get_period_mode_option() {
    $mode = get_option( VISWIZ_OPTION_SALES_PERIOD_MODE, 'relative' );
    return $mode === 'fixed' ? 'fixed' : 'relative';
}

function viswiz_get_orders_for_period( $period_value, $period_unit ) {
    $period_value = max( 1, (int) $period_value );
    $period_unit = viswiz_normalize_period_unit( $period_unit );
    return wc_get_orders(
        array(
            'status' => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
            'limit' => -1,
            'date_created' => '>' . viswiz_get_period_start_date( $period_value, $period_unit ),
            'return' => 'ids',
        )
    );
}

function viswiz_get_orders_for_fixed_period( $period_start ) {
    return wc_get_orders(
        array(
            'status' => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
            'limit' => -1,
            'date_created' => '>' . viswiz_get_fixed_period_start_date( $period_start ),
            'return' => 'ids',
        )
    );
}

function viswiz_get_order_total_for_products( WC_Order $order, array $product_ids ) {
    $total = 0.0;
    $product_ids = array_map( 'absint', $product_ids );
    foreach ( $order->get_items() as $item ) {
        if ( in_array( (int) $item->get_product_id(), $product_ids, true ) ) {
            $total += (float) $item->get_total();
        }
    }
    return $total;
}

function viswiz_get_order_total_for_category( WC_Order $order, array $category_ids ) {
    $total = 0.0;
    $category_ids = viswiz_get_category_ids_with_children( $category_ids );
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }
        $categories = $product->get_category_ids();
        if ( array_intersect( $category_ids, $categories ) ) {
            $total += (float) $item->get_total();
        }
    }
    return $total;
}

function viswiz_get_category_ids_with_children( array $category_ids ) {
    $all_ids = array();
    foreach ( $category_ids as $category_id ) {
        $category_id = (int) $category_id;
        if ( $category_id <= 0 ) {
            continue;
        }
        $all_ids[] = $category_id;
        $children = get_term_children( $category_id, 'product_cat' );
        if ( is_array( $children ) ) {
            foreach ( $children as $child_id ) {
                $all_ids[] = (int) $child_id;
            }
        }
    }
    return array_values( array_unique( array_filter( $all_ids ) ) );
}

function viswiz_get_sales_breakdown( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'viswiz_no_woocommerce', 'WooCommerce is not active.' );
    }

    $scope = sanitize_text_field( $request->get_param( 'scope' ) );
    if ( $scope === '' ) {
        $scope = get_option( VISWIZ_OPTION_SALES_SCOPE, 'total' );
    }

    $period_spec = viswiz_get_period_spec_from_request( $request );
    $period_mode = $period_spec[0];
    $product_ids = viswiz_parse_id_list( $request->get_param( 'product_ids' ) );
    if ( empty( $product_ids ) ) {
        $product_ids = viswiz_get_sales_product_ids();
    }
    $category_ids = viswiz_parse_id_list( $request->get_param( 'category_ids' ) );
    if ( empty( $category_ids ) ) {
        $category_ids = viswiz_get_sales_category_ids();
    }
    $category_ids = viswiz_get_category_ids_with_children( $category_ids );
    if ( $period_mode === 'fixed' ) {
        $orders = viswiz_get_orders_for_fixed_period( $period_spec[1] );
    } else {
        $orders = viswiz_get_orders_for_period( $period_spec[1], $period_spec[2] );
    }
    $totals = array();

    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            continue;
        }

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) {
                continue;
            }

            if ( $scope === 'product' && ! empty( $product_ids ) ) {
                if ( ! in_array( (int) $item->get_product_id(), $product_ids, true ) ) {
                    continue;
                }
                $label = $product->get_name();
                $totals[ $label ] = ( $totals[ $label ] ?? 0 ) + (float) $item->get_total();
                continue;
            }

            if ( $scope === 'category' ) {
                foreach ( $product->get_category_ids() as $category_id ) {
                    if ( ! empty( $category_ids ) && ! in_array( (int) $category_id, $category_ids, true ) ) {
                        continue;
                    }
                    $label = sprintf( 'Category %d', $category_id );
                    $term = get_term( $category_id, 'product_cat' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $label = $term->name;
                    }
                    $totals[ $label ] = ( $totals[ $label ] ?? 0 ) + (float) $item->get_total();
                }
            } else {
                $label = $product->get_name();
                $totals[ $label ] = ( $totals[ $label ] ?? 0 ) + (float) $item->get_total();
            }
        }
    }

    $values = array();
    foreach ( $totals as $label => $value ) {
        $values[] = array(
            'label' => $label,
            'value' => $value,
        );
    }

    return array(
        'values' => $values,
    );
}

function viswiz_json_encode( $data ) {
    return wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
}

function viswiz_register_visualizations_cpt() {
    register_post_type(
        'viswiz_visualization',
        array(
            'labels' => array(
                'name' => 'Visualizations',
                'singular_name' => 'Visualization',
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_rest' => true,
            'show_in_menu' => false,
            'menu_icon' => 'dashicons-chart-bar',
            'supports' => array( 'title' ),
        )
    );
}

function viswiz_register_block_assets() {
    wp_register_style(
        'viswiz-block-editor-style',
        plugins_url( 'assets/viswiz.css', __FILE__ ),
        array(),
        VISWIZ_VERSION
    );

    wp_register_script(
        'viswiz-block',
        plugins_url( 'assets/viswiz-block.js', __FILE__ ),
        array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch' ),
        VISWIZ_VERSION,
        true
    );

    register_block_type(
        'viswiz/visualization',
        array(
            'editor_script' => 'viswiz-block',
            'editor_style' => 'viswiz-block-editor-style',
            'render_callback' => 'viswiz_render_visualization_block',
            'attributes' => array(
                'visualizationId' => array(
                    'type' => 'number',
                    'default' => 0,
                ),
            ),
        )
    );
}

function viswiz_register_visualization_meta_box() {
    add_meta_box(
        'viswiz_visualization_meta',
        'Visualization Builder',
        'viswiz_render_visualization_meta_box',
        'viswiz_visualization',
        'normal',
        'default'
    );
}

function viswiz_render_visualization_meta_box( WP_Post $post ) {
    wp_nonce_field( 'viswiz_visualization_save', 'viswiz_visualization_nonce' );
    $active_tab = isset( $_GET['viswiz_tab'] ) ? sanitize_key( wp_unslash( $_GET['viswiz_tab'] ) ) : 'data';
    if ( ! in_array( $active_tab, array( 'data', 'nodes', 'node-types', 'relations', 'formatting', 'preview' ), true ) ) {
        $active_tab = 'data';
    }
    $meta = viswiz_get_visualization_meta( $post->ID );
    $manual_progress = $meta['manual_progress'];
    $manual_pie = $meta['manual_pie'];
    $diagram_data = $meta['diagram_data'];
    $graph_data = $meta['graph_data'];
    ?>
    <input type="hidden" name="viswiz_active_tab" value="<?php echo esc_attr( $active_tab ); ?>" data-viswiz-active-tab-input />
    <div class="viswiz-builder-overview">
        <div>
            <strong>Visualization builder</strong> <span class="viswiz-dirty-indicator" data-viswiz-dirty-indicator hidden>Unsaved changes</span>
            <p>Start with the data source, then edit rows/nodes, configure formatting, preview, and embed.</p>
        </div>
        <div class="viswiz-builder-shortcode">
            <label for="viswiz_shortcode"><strong>Embed shortcode</strong></label>
            <div class="viswiz-copy-field">
                <input type="text" id="viswiz_shortcode" class="large-text" readonly value="<?php echo esc_attr( viswiz_get_visualization_shortcode( $post->ID ) ); ?>" />
                <button type="button" class="button" data-viswiz-copy-target="viswiz_shortcode">Copy</button>
            </div>
        </div>
    </div>
    <ol class="viswiz-builder-steps" aria-label="Visualization workflow" data-viswiz-builder-steps>
        <li class="is-active" data-viswiz-step="data">1. Data source</li>
        <li data-viswiz-step="editing">2. Data editing</li>
        <li data-viswiz-step="formatting">3. Display</li>
        <li data-viswiz-step="preview">4. Preview</li>
        <li data-viswiz-step="embed">5. Embed</li>
    </ol>
    <div class="viswiz-meta-tabs" role="tablist" aria-label="Visualization builder sections">
        <button type="button" class="button viswiz-tab-button <?php echo $active_tab === 'data' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_tab === 'data' ? 'true' : 'false'; ?>" data-viswiz-tab="data">Data source</button>
        <button type="button" class="button viswiz-tab-button <?php echo $active_tab === 'nodes' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_tab === 'nodes' ? 'true' : 'false'; ?>" data-viswiz-tab="nodes">Nodes</button>
        <button type="button" class="button viswiz-tab-button <?php echo $active_tab === 'node-types' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_tab === 'node-types' ? 'true' : 'false'; ?>" data-viswiz-tab="node-types">Type Usage &amp; Proposals</button>
        <button type="button" class="button viswiz-tab-button <?php echo $active_tab === 'relations' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_tab === 'relations' ? 'true' : 'false'; ?>" data-viswiz-tab="relations">Relations</button>
        <button type="button" class="button viswiz-tab-button <?php echo $active_tab === 'formatting' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_tab === 'formatting' ? 'true' : 'false'; ?>" data-viswiz-tab="formatting">Display</button>
        <button type="button" class="button viswiz-tab-button <?php echo $active_tab === 'preview' ? 'is-active' : ''; ?>" role="tab" aria-selected="<?php echo $active_tab === 'preview' ? 'true' : 'false'; ?>" data-viswiz-tab="preview">Preview</button>
    </div>
    <div class="viswiz-tab-panel <?php echo $active_tab === 'data' ? 'is-active' : ''; ?>" role="tabpanel" data-viswiz-panel="data">
    <div class="viswiz-editor-guide" aria-live="polite" data-viswiz-editor-guide>
        <div class="viswiz-editor-guide-header">
            <strong data-viswiz-guide-title>Data editing guide</strong>
            <span class="viswiz-editor-guide-badge" data-viswiz-guide-badge>Auto</span>
        </div>
        <p data-viswiz-guide-summary>Choose a visualization type to see the relevant editing workflow.</p>
        <ul data-viswiz-guide-steps>
            <li>Select the visualization type and data source.</li>
            <li>Open the relevant editing tab for rows, diagram sections, or graph nodes and relations.</li>
        </ul>
    </div>
    <p>
        <label for="viswiz_type">Visualization Type</label>
        <select name="viswiz_meta[type]" id="viswiz_type" data-viswiz-type>
            <?php foreach ( viswiz_get_supported_visualization_types() as $type_key => $type_label ) : ?>
                <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $meta['type'], $type_key ); ?>><?php echo esc_html( $type_label ); ?></option>
            <?php endforeach; ?>
        </select>
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map">
        <label for="viswiz_source">Data Source</label>
        <select name="viswiz_meta[source]" id="viswiz_source" data-viswiz-source>
            <option value="auto" <?php selected( $meta['source'], 'auto' ); ?>>WooCommerce</option>
            <option value="manual" <?php selected( $meta['source'], 'manual' ); ?>>Manual</option>
        </select>
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map">
        <label for="viswiz_label">Label/Title</label>
        <input type="text" name="viswiz_meta[label]" id="viswiz_label" value="<?php echo esc_attr( $meta['label'] ); ?>" class="regular-text" />
    </p>
    <p>
        <label for="viswiz_dataset_id">Reusable Dataset</label>
        <?php echo viswiz_render_dataset_select( 'viswiz_meta[dataset_id]', $meta['dataset_id'] ); ?>
        <span class="description">Choose an existing dataset or leave as “Visualization-specific data”.</span>
    </p>
    <p>
        <label for="viswiz_new_dataset_name">Create Reusable Dataset</label>
        <input type="text" name="viswiz_meta[new_dataset_name]" id="viswiz_new_dataset_name" class="regular-text" placeholder="Optional dataset name" />
        <span class="description">When filled, the data entered below is also stored as a reusable dataset.</span>
    </p>
    <p>
        <label for="viswiz_legend">Legend entries</label>
        <textarea name="viswiz_meta[legend]" id="viswiz_legend" class="large-text" rows="2" placeholder="One legend entry per line"><?php echo esc_textarea( $meta['legend'] ); ?></textarea>
        <span class="description">One legend entry per line. Leave empty to use generated labels.</span>
    </p>
    <p>
        <label for="viswiz_axis_labels">Axis labels / display labels</label>
        <textarea name="viswiz_meta[axis_labels]" id="viswiz_axis_labels" class="large-text" rows="2" placeholder="Example: X: Date / Y: Count"><?php echo esc_textarea( $meta['axis_labels'] ); ?></textarea>
        <span class="description">Use short lines such as <code>X: Date</code> and <code>Y: Count</code>.</span>
    </p>
    <p>
        <label for="viswiz_other_settings">Advanced settings</label>
        <textarea name="viswiz_meta[other_settings]" id="viswiz_other_settings" class="large-text" rows="3" placeholder="Optional advanced notes or JSON. Keep empty unless needed."><?php echo esc_textarea( $meta['other_settings'] ); ?></textarea>
        <span class="description">Reserved for advanced renderer hints. Prefer the structured fields and formatting tab for routine settings.</span>
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress">
        <label for="viswiz_target">Target (progress)</label>
        <input type="number" name="viswiz_meta[target]" id="viswiz_target" value="<?php echo esc_attr( $meta['target'] ); ?>" step="0.01" />
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map" data-viswiz-sources="auto">
        <label for="viswiz_scope">Sales Scope</label>
        <select name="viswiz_meta[scope]" id="viswiz_scope">
            <option value="total" <?php selected( $meta['scope'], 'total' ); ?>>All sales</option>
            <option value="product" <?php selected( $meta['scope'], 'product' ); ?>>By product</option>
            <option value="category" <?php selected( $meta['scope'], 'category' ); ?>>By category</option>
        </select>
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map" data-viswiz-sources="auto">
        <label for="viswiz_period_mode">Period Type</label>
        <select name="viswiz_meta[period_mode]" id="viswiz_period_mode" data-viswiz-period-mode>
            <option value="relative" <?php selected( $meta['period_mode'], 'relative' ); ?>>Period (value + unit)</option>
            <option value="fixed" <?php selected( $meta['period_mode'], 'fixed' ); ?>>From date/time until now</option>
        </select>
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map" data-viswiz-sources="auto" data-viswiz-period="relative">
        <label for="viswiz_period_value">Period</label>
        <input type="number" name="viswiz_meta[period_value]" id="viswiz_period_value" value="<?php echo esc_attr( $meta['period_value'] ); ?>" min="1" class="small-text" />
        <select name="viswiz_meta[period_unit]" id="viswiz_period_unit">
            <option value="day" <?php selected( $meta['period_unit'], 'day' ); ?>>day(s)</option>
            <option value="month" <?php selected( $meta['period_unit'], 'month' ); ?>>month(s)</option>
            <option value="year" <?php selected( $meta['period_unit'], 'year' ); ?>>year(s)</option>
        </select>
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map" data-viswiz-sources="auto" data-viswiz-period="fixed">
        <label for="viswiz_period_start">Start date/time</label>
        <input type="datetime-local" name="viswiz_meta[period_start]" id="viswiz_period_start" value="<?php echo esc_attr( $meta['period_start'] ); ?>" />
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map" data-viswiz-sources="auto">
        <label for="viswiz_product_id">Product</label>
        <?php echo viswiz_render_product_search_field( 'viswiz_meta[product_ids][]', $meta['product_ids'], true ); ?>
    </p>
    <p class="viswiz-field-group" data-viswiz-types="progress,pie,bar,column,line,area,scatter,counter,timeline,map" data-viswiz-sources="auto">
        <label for="viswiz_category_id">Category</label>
        <?php echo viswiz_render_category_select_field( 'viswiz_meta[category_ids][]', $meta['category_ids'], 'viswiz_category_id', true ); ?>
    </p>
    <div class="viswiz-field-group" data-viswiz-types="progress" data-viswiz-sources="manual">
        <h4>Manual Progress</h4>
        <div id="viswiz-visual-progress" class="viswiz-repeatable">
            <?php if ( empty( $manual_progress ) ) : ?>
                <?php $manual_progress = array( array( 'label' => '', 'value' => '', 'target' => '' ) ); ?>
            <?php endif; ?>
        <?php foreach ( $manual_progress as $progress_index => $progress_item ) : ?>
            <div class="viswiz-row" data-progress-index="<?php echo esc_attr( $progress_index ); ?>">
                <?php $progress_targets = $progress_item['targets'] ?? array(); ?>
                <?php if ( empty( $progress_targets ) && isset( $progress_item['target'] ) ) : ?>
                    <?php $progress_targets = array( array( 'name' => 'Target', 'value' => $progress_item['target'] ) ); ?>
                <?php endif; ?>
                <?php if ( empty( $progress_targets ) ) : ?>
                    <?php $progress_targets = array( array( 'name' => '', 'value' => '' ) ); ?>
                <?php endif; ?>
                <input type="text" name="viswiz_meta[manual_progress][label][]" placeholder="Label" value="<?php echo esc_attr( $progress_item['label'] ?? '' ); ?>" class="regular-text" />
                <input type="number" name="viswiz_meta[manual_progress][value][]" placeholder="Value" value="<?php echo esc_attr( $progress_item['value'] ?? '' ); ?>" step="0.01" />
                <div class="viswiz-targets" data-name-prefix="viswiz_meta[manual_progress]">
                    <?php foreach ( $progress_targets as $target ) : ?>
                        <div class="viswiz-target-row">
                            <input type="text" name="viswiz_meta[manual_progress][targets][name][<?php echo esc_attr( $progress_index ); ?>][]" placeholder="Target name" value="<?php echo esc_attr( $target['name'] ?? '' ); ?>" class="regular-text" />
                            <input type="number" name="viswiz_meta[manual_progress][targets][value][<?php echo esc_attr( $progress_index ); ?>][]" placeholder="Target value" value="<?php echo esc_attr( $target['value'] ?? '' ); ?>" step="0.01" />
                            <button type="button" class="button viswiz-remove-target">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button viswiz-add-target" data-target-scope="visual">Add Target</button>
                <button type="button" class="button viswiz-remove-row">Remove</button>
            </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="button" data-viswiz-add="visual-progress">Add Progress Row</button>
    </div>
    <div class="viswiz-field-group" data-viswiz-types="pie,bar,column,line,area,scatter,counter,timeline,map" data-viswiz-sources="manual">
        <h4><span data-viswiz-manual-data-heading>Manual data rows</span></h4>
        <div id="viswiz-visual-pie" class="viswiz-repeatable">
            <?php if ( empty( $manual_pie ) ) : ?>
                <?php $manual_pie = array( array( 'label' => '', 'value' => '', 'color' => '' ) ); ?>
            <?php endif; ?>
            <?php foreach ( $manual_pie as $pie_item ) : ?>
            <div class="viswiz-row">
                <input type="text" name="viswiz_meta[manual_pie][label][]" placeholder="Label" value="<?php echo esc_attr( $pie_item['label'] ?? '' ); ?>" class="regular-text" />
                <input type="number" name="viswiz_meta[manual_pie][value][]" placeholder="Value" value="<?php echo esc_attr( $pie_item['value'] ?? '' ); ?>" step="0.01" />
                <input type="color" name="viswiz_meta[manual_pie][color][]" value="<?php echo esc_attr( $pie_item['color'] ?? '' ); ?>" />
                <button type="button" class="button viswiz-remove-row">Remove</button>
            </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="button" data-viswiz-add="visual-pie" data-viswiz-manual-data-add>Add data row</button><p class="description" data-viswiz-manual-data-help>Use one row per value, category, point, date, counter, timeline item, or map marker depending on the selected visualization type.</p>
    </div>
    <div class="viswiz-field-group" data-viswiz-types="diagram">
        <h4>Legacy diagram sections</h4>
        <div id="viswiz-visual-diagram" class="viswiz-repeatable">
            <?php if ( empty( $diagram_data ) ) : ?>
                <?php $diagram_data = array( array( 'title' => '', 'items' => array( '' ) ) ); ?>
            <?php endif; ?>
            <?php foreach ( $diagram_data as $section_index => $diagram_section ) : ?>
            <div class="viswiz-section" data-section-index="<?php echo esc_attr( $section_index ); ?>">
                <input type="text" name="viswiz_meta[diagram_data][title][]" placeholder="Section Title" value="<?php echo esc_attr( $diagram_section['title'] ?? '' ); ?>" class="regular-text" />
                <div class="viswiz-items">
                    <?php $items = $diagram_section['items'] ?? array( '' ); ?>
                    <?php if ( empty( $items ) ) : ?>
                        <?php $items = array( '' ); ?>
                    <?php endif; ?>
                    <?php foreach ( $items as $item_value ) : ?>
                        <div class="viswiz-item-row">
                            <input type="text" name="viswiz_meta[diagram_data][items][<?php echo esc_attr( $section_index ); ?>][]" placeholder="Item" value="<?php echo esc_attr( $item_value ); ?>" class="regular-text" />
                            <button type="button" class="button viswiz-remove-item">Remove</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button viswiz-add-item">Add Item</button>
                <button type="button" class="button viswiz-remove-section">Remove Section</button>
            </div>
        <?php endforeach; ?>
        </div>
        <button type="button" class="button" data-viswiz-add="visual-diagram">Add diagram section</button>
    </div>
    </div>
    <div class="viswiz-tab-panel <?php echo $active_tab === 'nodes' ? 'is-active' : ''; ?>" data-viswiz-panel="nodes">
    <div class="viswiz-field-group" data-viswiz-types="graph,flow_diagram,org_chart">
        <h4>Nodes <span class="viswiz-dataset-badge"><?php echo esc_html( viswiz_get_graph_dataset_label( $meta['dataset_id'] ) ); ?></span></h4>
        <div class="viswiz-graph">
            <?php $dataset_label = viswiz_get_graph_dataset_label( $meta['dataset_id'] ); ?>
            <div class="viswiz-graph-validation-panel" data-viswiz-graph-validation-panel>
                <strong>Dataset validation</strong>
                <div data-viswiz-graph-validation-summary class="description">Validation will run as you edit nodes and relations.</div>
            </div>
            <div class="viswiz-quick-entry-panel" data-viswiz-quick-entry-panel>
                <button type="button" class="button" data-viswiz-toggle-quick-nodes>Quick add nodes</button>
                <button type="button" class="button" data-viswiz-toggle-quick-relations>Quick add relations</button>
                <div class="viswiz-quick-entry-drawer" data-viswiz-quick-nodes hidden>
                    <h5>Paste or type nodes</h5>
                    <p class="description">One node per line. Use tabs or commas: Title, Type, Subtype.</p>
                    <textarea rows="5" class="large-text" data-viswiz-quick-node-text placeholder="Nikos Michaloliakos, person, politician"></textarea>
                    <button type="button" class="button button-secondary" data-viswiz-import-quick-nodes>Import nodes</button>
                </div>
                <div class="viswiz-quick-entry-drawer" data-viswiz-quick-relations hidden>
                    <h5>Paste or type relations</h5>
                    <p class="description">One relation per line. Use tabs or commas: From, Relation type, To, Direction, Intensity.</p>
                    <textarea rows="5" class="large-text" data-viswiz-quick-relation-text placeholder="nikos-michaloliakos, leader_of, chrysi-avgi, directed, 1"></textarea>
                    <button type="button" class="button button-secondary" data-viswiz-import-quick-relations>Import relations</button>
                </div>
            </div>
            <div class="viswiz-node-list-frame" data-viswiz-node-list-frame>
                <h5>Node list and editor</h5>
                <div class="viswiz-node-list-controls">
                    <div class="viswiz-node-type-filter" data-viswiz-node-type-filter>
                        <button type="button" class="button viswiz-node-type-filter-toggle" data-viswiz-node-type-filter-toggle>All node types and subtypes</button>
                        <div class="viswiz-node-type-filter-menu" data-viswiz-node-type-filter-menu hidden>
                            <label class="screen-reader-text" for="viswiz_node_type_filter_search">Search node types and subtypes</label>
                            <input type="search" id="viswiz_node_type_filter_search" class="regular-text" placeholder="Filter types/subtypes..." data-viswiz-node-type-filter-search />
                            <div class="viswiz-node-type-filter-options" data-viswiz-node-type-filter-options></div>
                        </div>
                    </div>
                    <div class="viswiz-node-search-row">
                        <label class="viswiz-node-search-label" for="viswiz_node_search">Search nodes</label>
                        <input type="search" id="viswiz_node_search" class="regular-text" placeholder="Type at least 3 characters to filter by title, name, or description" data-viswiz-node-search />
                        <button type="button" class="button button-secondary" data-viswiz-add="visual-graph-node">New node</button>
                    </div>
                    <span class="description" data-viswiz-node-search-status></span>
                </div>
                <div id="viswiz-visual-graph-nodes" class="viswiz-repeatable viswiz-card-list viswiz-node-box-grid" data-viswiz-node-list>
                <?php $nodes = $graph_data['nodes'] ?? array(); ?>
                <?php if ( empty( $nodes ) ) : ?>
                    <?php $nodes = array( array( 'id' => '', 'label' => '', 'title' => '' ) ); ?>
                <?php endif; ?>
                <?php foreach ( $nodes as $node_index => $node ) : ?>
                    <?php viswiz_render_graph_node_row( 'viswiz_meta[graph_data][nodes]', $node, $node_index, $dataset_label ); ?>
                <?php endforeach; ?>
                </div>
                <p class="description">The editor shows a compact node list here. Click any listed node to open its full-screen editor with all node details.</p>
            </div>
        </div>
    </div>
    </div>
    <div class="viswiz-tab-panel <?php echo $active_tab === 'node-types' ? 'is-active' : ''; ?>" data-viswiz-panel="node-types">
        <div class="viswiz-field-group" data-viswiz-types="graph,flow_diagram,org_chart">
            <h4>Type Usage &amp; Proposals</h4>
            <p class="description">Review how this visualization uses the canonical node schema, approve proposed subtypes into the global Node Types screen, and clean up type/subtype assignments.</p>
            <div id="viswiz-node-type-manager" class="viswiz-node-type-manager" data-viswiz-node-type-manager></div>
        </div>
    </div>
    <div class="viswiz-tab-panel <?php echo $active_tab === 'relations' ? 'is-active' : ''; ?>" data-viswiz-panel="relations">
        <div class="viswiz-field-group" data-viswiz-types="graph,flow_diagram,org_chart">
            <h4>Relations <span class="viswiz-dataset-badge"><?php echo esc_html( viswiz_get_graph_dataset_label( $meta['dataset_id'] ) ); ?></span></h4>
            <?php viswiz_render_graph_node_datalist( $graph_data['nodes'] ?? array(), 'viswiz_visual_relation_nodes' ); ?>
            <div id="viswiz-visual-graph-links" class="viswiz-repeatable viswiz-card-list viswiz-relation-box-grid" data-viswiz-relation-list>
                <?php $links = $graph_data['links'] ?? array(); ?>
                <?php if ( empty( $links ) ) : ?>
                    <?php $links = array( array( 'from' => '', 'to' => '', 'label' => '' ) ); ?>
                <?php endif; ?>
                <?php foreach ( $links as $link_index => $link ) : ?>
                    <?php viswiz_render_graph_link_row( 'viswiz_meta[graph_data][links]', $link, $link_index, viswiz_get_graph_dataset_label( $meta['dataset_id'] ), 'viswiz_visual_relation_nodes', $graph_data['nodes'] ?? array() ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" data-viswiz-add="visual-graph-link">Add Relation</button>
        </div>
    </div>
    <div class="viswiz-tab-panel <?php echo $active_tab === 'formatting' ? 'is-active' : ''; ?>" data-viswiz-panel="formatting">
        <p>
            <label for="viswiz_animation">Animation</label>
            <select name="viswiz_meta[animation]" id="viswiz_animation">
                <option value="none" <?php selected( $meta['animation'], 'none' ); ?>>None</option>
                <option value="fade" <?php selected( $meta['animation'], 'fade' ); ?>>Fade In</option>
                <option value="slide" <?php selected( $meta['animation'], 'slide' ); ?>>Slide Up</option>
                <option value="grow" <?php selected( $meta['animation'], 'grow' ); ?>>Grow</option>
                <option value="pulse" <?php selected( $meta['animation'], 'pulse' ); ?>>Pulse</option>
            </select>
            <span class="description">Applied when the visualization appears on the page.</span>
        </p>
        <p>
            <label>Colors</label>
        </p>
        <p>
            <label for="viswiz_color_primary">Primary</label>
            <input type="color" name="viswiz_meta[format_colors][primary]" id="viswiz_color_primary" value="<?php echo esc_attr( $meta['format_colors']['primary'] ?? '#4caf50' ); ?>" />
        </p>
        <p>
            <label for="viswiz_color_secondary">Secondary</label>
            <input type="color" name="viswiz_meta[format_colors][secondary]" id="viswiz_color_secondary" value="<?php echo esc_attr( $meta['format_colors']['secondary'] ?? '#2196f3' ); ?>" />
        </p>
        <p>
            <label for="viswiz_color_accent">Accent</label>
            <input type="color" name="viswiz_meta[format_colors][accent]" id="viswiz_color_accent" value="<?php echo esc_attr( $meta['format_colors']['accent'] ?? '#ffc107' ); ?>" />
        </p>
        <p>
            <label for="viswiz_color_background">Background</label>
            <input type="color" name="viswiz_meta[format_colors][background]" id="viswiz_color_background" value="<?php echo esc_attr( $meta['format_colors']['background'] ?? '#ffffff' ); ?>" />
        </p>
        <p>
            <label for="viswiz_color_text">Text</label>
            <input type="color" name="viswiz_meta[format_colors][text]" id="viswiz_color_text" value="<?php echo esc_attr( $meta['format_colors']['text'] ?? '#333333' ); ?>" />
        </p>
        <p>
            <label><input type="hidden" name="viswiz_meta[format_colors][show_fullscreen_toggle]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_fullscreen_toggle]" id="viswiz_show_fullscreen_toggle" value="1" <?php checked( $meta['format_colors']['show_fullscreen_toggle'] ?? 1, 1 ); ?> /> Show full screen toggle</label>
            <span class="description">Adds a public full screen button to this visualization.</span>
        </p>
        <div class="viswiz-field-group" data-viswiz-types="graph,flow_diagram,org_chart">
            <h4>Graph Options</h4>
            <p>
                <label for="viswiz_graph_node_style">Node Appearance</label>
                <select name="viswiz_meta[format_colors][node_style]" id="viswiz_graph_node_style">
                    <option value="card" <?php selected( $meta['format_colors']['node_style'] ?? 'card', 'card' ); ?>>Basic info cards</option>
                    <option value="compact" <?php selected( $meta['format_colors']['node_style'] ?? 'card', 'compact' ); ?>>Compact labels</option>
                    <option value="round" <?php selected( $meta['format_colors']['node_style'] ?? 'card', 'round' ); ?>>Round labels</option>
                </select>
                <span class="description">Choose cards for rich nodes, compact for dense maps, or round labels for simple networks.</span>
            </p>
            <p>
                <label for="viswiz_graph_node_label_style">Label Shape</label>
                <select name="viswiz_meta[format_colors][node_label_style]" id="viswiz_graph_node_label_style">
                    <option value="rounded" <?php selected( $meta['format_colors']['node_label_style'] ?? 'rounded', 'rounded' ); ?>>Rounded rectangle</option>
                    <option value="pill" <?php selected( $meta['format_colors']['node_label_style'] ?? 'rounded', 'pill' ); ?>>Pill</option>
                    <option value="plain" <?php selected( $meta['format_colors']['node_label_style'] ?? 'rounded', 'plain' ); ?>>Plain text</option>
                </select>
            </p>
            <p>
                <label for="viswiz_graph_node_radius">Node Size</label>
                <input type="number" name="viswiz_meta[format_colors][node_radius]" id="viswiz_graph_node_radius" value="<?php echo esc_attr( $meta['format_colors']['node_radius'] ?? '20' ); ?>" min="10" max="50" step="1" class="small-text" />
                <span class="description">Radius for round/compact graph nodes (10-50 pixels)</span>
            </p>
            <p>
                <label for="viswiz_graph_link_distance">Link Distance</label>
                <input type="number" name="viswiz_meta[format_colors][link_distance]" id="viswiz_graph_link_distance" value="<?php echo esc_attr( $meta['format_colors']['link_distance'] ?? '100' ); ?>" min="50" max="300" step="10" class="small-text" />
                <span class="description">Distance between connected nodes (50-300 pixels)</span>
            </p>
            <p>
                <label for="viswiz_graph_charge">Repulsion Strength</label>
                <input type="number" name="viswiz_meta[format_colors][charge_strength]" id="viswiz_graph_charge" value="<?php echo esc_attr( $meta['format_colors']['charge_strength'] ?? '-300' ); ?>" min="-1000" max="-50" step="50" class="small-text" />
                <span class="description">How much nodes push apart (-1000 to -50)</span>
            </p>
            <p>
                <label for="viswiz_graph_node_card_width">Card Width</label>
                <input type="number" name="viswiz_meta[format_colors][node_card_width]" id="viswiz_graph_node_card_width" value="<?php echo esc_attr( $meta['format_colors']['node_card_width'] ?? '150' ); ?>" min="90" max="260" step="10" class="small-text" />
                <span class="description">Width of basic info cards (90-260 pixels)</span>
            </p>
            <p>
                <label><input type="hidden" name="viswiz_meta[format_colors][scale_nodes_by_relations]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][scale_nodes_by_relations]" id="viswiz_graph_scale_nodes_by_relations" value="1" <?php checked( $meta['format_colors']['scale_nodes_by_relations'] ?? 0, 1 ); ?> /> Scale round nodes and cards by relation count</label>
                <span class="description">When enabled, highly connected nodes become visually larger.</span>
            </p>
            <p>
                <label for="viswiz_graph_relation_size_step">Size increase per relation</label>
                <input type="number" name="viswiz_meta[format_colors][relation_size_step]" id="viswiz_graph_relation_size_step" value="<?php echo esc_attr( $meta['format_colors']['relation_size_step'] ?? '3' ); ?>" min="0" max="20" step="1" class="small-text" />
                <span class="description">Pixels added per relation before the maximum is reached.</span>
            </p>
            <p>
                <label for="viswiz_graph_max_relation_size_boost">Maximum relation size increase</label>
                <input type="number" name="viswiz_meta[format_colors][max_relation_size_boost]" id="viswiz_graph_max_relation_size_boost" value="<?php echo esc_attr( $meta['format_colors']['max_relation_size_boost'] ?? '30' ); ?>" min="0" max="120" step="5" class="small-text" />
                <span class="description">Caps the extra radius/card growth from relation count.</span>
            </p>
            <p>
                <label for="viswiz_graph_filter_mode">Filter Behaviour</label>
                <select name="viswiz_meta[format_colors][graph_filter_mode]" id="viswiz_graph_filter_mode">
                    <option value="fade" <?php selected( $meta['format_colors']['graph_filter_mode'] ?? 'fade', 'fade' ); ?>>Fade non-matching items</option>
                    <option value="hide" <?php selected( $meta['format_colors']['graph_filter_mode'] ?? 'fade', 'hide' ); ?>>Hide non-matching items</option>
                </select>
                <span class="description">Controls visitor toolbar filtering.</span>
            </p>
            <p>
                <label><input type="hidden" name="viswiz_meta[format_colors][show_node_images]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_node_images]" id="viswiz_graph_show_node_images" value="1" <?php checked( $meta['format_colors']['show_node_images'] ?? 1, 1 ); ?> /> Show node images on cards</label><br />
                <label><input type="hidden" name="viswiz_meta[format_colors][show_type_badges]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_type_badges]" id="viswiz_graph_show_type_badges" value="1" <?php checked( $meta['format_colors']['show_type_badges'] ?? 1, 1 ); ?> /> Show type and subtype badges</label><br />
                <label><input type="hidden" name="viswiz_meta[format_colors][show_graph_toolbar]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_graph_toolbar]" id="viswiz_graph_show_toolbar" value="1" <?php checked( $meta['format_colors']['show_graph_toolbar'] ?? 1, 1 ); ?> /> Show visitor exploration toolbar</label><br />
                <label><input type="hidden" name="viswiz_meta[format_colors][show_graph_search]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_graph_search]" id="viswiz_graph_show_search" value="1" <?php checked( $meta['format_colors']['show_graph_search'] ?? 1, 1 ); ?> /> Include search in toolbar</label><br />
                <label><input type="hidden" name="viswiz_meta[format_colors][show_graph_filters]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_graph_filters]" id="viswiz_graph_show_filters" value="1" <?php checked( $meta['format_colors']['show_graph_filters'] ?? 1, 1 ); ?> /> Include type and relation filters</label><br />
                <label><input type="hidden" name="viswiz_meta[format_colors][show_graph_zoom]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_graph_zoom]" id="viswiz_graph_show_zoom" value="1" <?php checked( $meta['format_colors']['show_graph_zoom'] ?? 1, 1 ); ?> /> Show zoom controls</label><br />
                <label><input type="hidden" name="viswiz_meta[format_colors][show_relation_labels]" value="0" /><input type="checkbox" name="viswiz_meta[format_colors][show_relation_labels]" id="viswiz_graph_show_relation_labels" value="1" <?php checked( $meta['format_colors']['show_relation_labels'] ?? 1, 1 ); ?> /> Show relation labels on graph edges</label><br />
            </p>
            <h4>Full info modal labels</h4>
            <?php viswiz_render_graph_modal_label_inputs( $meta['format_colors'] ?? array(), 'viswiz_meta[format_colors]', 'viswiz_visual' ); ?>
        </div>
    </div>
    <div class="viswiz-tab-panel <?php echo $active_tab === 'preview' ? 'is-active' : ''; ?>" data-viswiz-panel="preview">
        <p class="description">This preview shows how your visualization will appear based on current settings. For WooCommerce data sources, sample data is shown.</p>
        <button type="button" class="button button-secondary" id="viswiz-refresh-preview">Refresh Preview</button>
        <div id="viswiz-preview-container" class="viswiz-preview-wrap"></div>
    </div>
    <?php
}

function viswiz_save_visualization_meta( $post_id ) {
    if ( ! isset( $_POST['viswiz_visualization_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['viswiz_visualization_nonce'] ), 'viswiz_visualization_save' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    if ( ! isset( $_POST['viswiz_meta'] ) || ! is_array( $_POST['viswiz_meta'] ) ) {
        return;
    }

    $meta = wp_unslash( $_POST['viswiz_meta'] );
    $type = sanitize_text_field( $meta['type'] ?? 'progress' );
    $source = sanitize_text_field( $meta['source'] ?? 'auto' );
    $label = sanitize_text_field( $meta['label'] ?? '' );
    $target = isset( $meta['target'] ) ? (float) $meta['target'] : 0;
    $scope = sanitize_text_field( $meta['scope'] ?? 'total' );
    $period_mode = isset( $meta['period_mode'] ) ? sanitize_text_field( $meta['period_mode'] ) : '';
    $period_value = isset( $meta['period_value'] ) ? (int) $meta['period_value'] : 0;
    $period_unit = isset( $meta['period_unit'] ) ? viswiz_normalize_period_unit( $meta['period_unit'] ) : '';
    $period_start = isset( $meta['period_start'] ) ? sanitize_text_field( $meta['period_start'] ) : '';
    if ( $period_value <= 0 ) {
        $period_value = 30;
    }
    if ( $period_unit === '' ) {
        $period_unit = 'day';
    }
    if ( $period_mode === '' ) {
        $period_mode = 'relative';
    }
    $product_ids = isset( $meta['product_ids'] ) ? viswiz_sanitize_id_array( $meta['product_ids'] ) : array();
    $format_colors = $meta['format_colors'] ?? array();
    $animation = sanitize_text_field( $meta['animation'] ?? 'none' );
    $allowed_animations = array( 'none', 'fade', 'slide', 'grow', 'pulse' );
    if ( ! in_array( $animation, $allowed_animations, true ) ) {
        $animation = 'none';
    }
    $category_ids = isset( $meta['category_ids'] ) ? viswiz_sanitize_id_array( $meta['category_ids'] ) : array();
    $dataset_id = isset( $meta['dataset_id'] ) ? absint( $meta['dataset_id'] ) : 0;
    $new_dataset_name = sanitize_text_field( $meta['new_dataset_name'] ?? '' );
    $legend = sanitize_textarea_field( $meta['legend'] ?? '' );
    $axis_labels = sanitize_textarea_field( $meta['axis_labels'] ?? '' );
    $other_settings = sanitize_textarea_field( $meta['other_settings'] ?? '' );

    update_post_meta( $post_id, 'viswiz_type', $type );
    update_post_meta( $post_id, 'viswiz_source', $source );
    update_post_meta( $post_id, 'viswiz_label', $label );
    update_post_meta( $post_id, 'viswiz_target', $target );
    update_post_meta( $post_id, 'viswiz_scope', $scope );
    update_post_meta( $post_id, 'viswiz_period_mode', $period_mode );
    update_post_meta( $post_id, 'viswiz_period_value', $period_value );
    update_post_meta( $post_id, 'viswiz_period_unit', $period_unit );
    update_post_meta( $post_id, 'viswiz_period_start', $period_start );
    update_post_meta( $post_id, 'viswiz_product_ids', $product_ids );
    update_post_meta( $post_id, 'viswiz_category_ids', $category_ids );
    update_post_meta( $post_id, 'viswiz_animation', $animation );
    update_post_meta( $post_id, 'viswiz_format_colors', viswiz_sanitize_format_colors( $format_colors ) );
    update_post_meta( $post_id, 'viswiz_dataset_id', $dataset_id );
    update_post_meta( $post_id, 'viswiz_legend', $legend );
    update_post_meta( $post_id, 'viswiz_axis_labels', $axis_labels );
    update_post_meta( $post_id, 'viswiz_other_settings', $other_settings );

    $progress_json = viswiz_sanitize_progress_option( $meta['manual_progress'] ?? array() );
    $pie_json = viswiz_sanitize_pie_option( $meta['manual_pie'] ?? array() );
    $diagram_json = viswiz_sanitize_diagram_option( $meta['diagram_data'] ?? array() );
    $raw_graph_data = $meta['graph_data'] ?? array();
    $graph_json = viswiz_sanitize_graph_option( $raw_graph_data );
    $graph_data_for_tables = json_decode( $graph_json, true ) ?: array();
    $graph_data_for_tables = viswiz_preserve_existing_graph_links_for_node_autosave( $post_id, $raw_graph_data, $graph_data_for_tables, '' );
    $graph_json = viswiz_json_encode( $graph_data_for_tables );

    update_post_meta( $post_id, 'viswiz_manual_progress', $progress_json );
    update_post_meta( $post_id, 'viswiz_manual_pie', $pie_json );
    update_post_meta( $post_id, 'viswiz_diagram_data', $diagram_json );
    update_post_meta( $post_id, 'viswiz_graph_data', $graph_json );

    viswiz_save_visualization_tables( $post_id, array(
        'type' => $type,
        'dataset_id' => $dataset_id,
        'new_dataset_name' => $new_dataset_name,
        'legend' => $legend,
        'axis_labels' => $axis_labels,
        'other_settings' => $other_settings,
        'theme' => viswiz_sanitize_format_colors( $format_colors ),
        'manual_progress' => json_decode( $progress_json, true ) ?: array(),
        'manual_pie' => json_decode( $pie_json, true ) ?: array(),
        'diagram_data' => json_decode( $diagram_json, true ) ?: array(),
        'graph_data' => $graph_data_for_tables,
    ) );
}


function viswiz_redirect_to_active_visualization_tab( $location, $post_id ) {
    if ( get_post_type( $post_id ) !== 'viswiz_visualization' || empty( $_POST['viswiz_active_tab'] ) ) {
        return $location;
    }
    $tab = sanitize_key( wp_unslash( $_POST['viswiz_active_tab'] ) );
    $allowed = array( 'data', 'nodes', 'node-types', 'relations', 'formatting', 'preview' );
    if ( ! in_array( $tab, $allowed, true ) ) {
        return $location;
    }
    return add_query_arg( 'viswiz_tab', $tab, $location );
}

function viswiz_get_admin_i18n() {
    return array(
        'closeModal' => __( 'Close modal', 'viswiz' ),
        'closeNodeEditor' => __( 'Close node editor', 'viswiz' ),
        'closeRelationEditor' => __( 'Close relation editor', 'viswiz' ),
        'autosaving' => __( 'Autosaving…', 'viswiz' ),
        'autosaved' => __( 'Autosaved.', 'viswiz' ),
        'autosaveFailed' => __( 'Autosave failed. Use Save to retry.', 'viswiz' ),
        'selectNode' => __( 'Select a node…', 'viswiz' ),
        'noImage' => __( 'No image', 'viswiz' ),
        'noImageSelected' => __( 'No image selected', 'viswiz' ),
        'noImagesSelected' => __( 'No images selected', 'viswiz' ),
        'nodeImages' => __( 'Node images', 'viswiz' ),
        'featuredImage' => __( 'Featured image', 'viswiz' ),
        'attachedImage' => __( 'Attached image', 'viswiz' ),
        'replace' => __( 'Replace', 'viswiz' ),
        'edit' => __( 'Edit', 'viswiz' ),
        'remove' => __( 'Remove', 'viswiz' ),
    );
}

function viswiz_get_visualization_meta( $post_id ) {
    $prefill_dataset_id = isset( $_GET['viswiz_dataset_id'] ) ? absint( $_GET['viswiz_dataset_id'] ) : 0;
    $saved_dataset_id = (int) get_post_meta( $post_id, 'viswiz_dataset_id', true );
    return array(
        'type' => get_post_meta( $post_id, 'viswiz_type', true ) ?: 'progress',
        'source' => get_post_meta( $post_id, 'viswiz_source', true ) ?: 'auto',
        'label' => get_post_meta( $post_id, 'viswiz_label', true ) ?: '',
        'target' => (float) get_post_meta( $post_id, 'viswiz_target', true ),
        'scope' => get_post_meta( $post_id, 'viswiz_scope', true ) ?: 'total',
        'period_mode' => get_post_meta( $post_id, 'viswiz_period_mode', true ) ?: viswiz_get_period_mode_option(),
        'period_value' => (int) get_post_meta( $post_id, 'viswiz_period_value', true ) ?: (int) get_option( VISWIZ_OPTION_SALES_PERIOD_VALUE, 30 ),
        'period_unit' => get_post_meta( $post_id, 'viswiz_period_unit', true ) ?: viswiz_get_period_unit_option(),
        'period_start' => get_post_meta( $post_id, 'viswiz_period_start', true ) ?: '',
        'product_ids' => viswiz_get_post_meta_ids( $post_id, 'viswiz_product_ids' ),
        'category_ids' => viswiz_get_visualization_category_ids( $post_id ),
        'animation' => get_post_meta( $post_id, 'viswiz_animation', true ) ?: 'none',
        'format_colors' => viswiz_get_visualization_format_colors( $post_id ),
        'dataset_id' => $saved_dataset_id ?: $prefill_dataset_id,
        'legend' => get_post_meta( $post_id, 'viswiz_legend', true ) ?: '',
        'axis_labels' => get_post_meta( $post_id, 'viswiz_axis_labels', true ) ?: '',
        'other_settings' => get_post_meta( $post_id, 'viswiz_other_settings', true ) ?: '',
        'manual_progress' => json_decode( get_post_meta( $post_id, 'viswiz_manual_progress', true ) ?: '[]', true ) ?: array(),
        'manual_pie' => json_decode( get_post_meta( $post_id, 'viswiz_manual_pie', true ) ?: '[]', true ) ?: array(),
        'diagram_data' => json_decode( get_post_meta( $post_id, 'viswiz_diagram_data', true ) ?: '[]', true ) ?: array(),
        'graph_data' => json_decode( get_post_meta( $post_id, 'viswiz_graph_data', true ) ?: '[]', true ) ?: array(),
    );
}

function viswiz_render_visualization_block( $attributes ) {
    $post_id = isset( $attributes['visualizationId'] ) ? (int) $attributes['visualizationId'] : 0;
    if ( $post_id <= 0 ) {
        return '<div class="viswiz-message">Select a visualization.</div>';
    }

    return viswiz_render_visualization( $post_id );
}


function viswiz_get_dataset_payload( $dataset_id, $type ) {
    global $wpdb;
    $dataset_id = absint( $dataset_id );
    if ( ! $dataset_id ) {
        return null;
    }
    $points = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . viswiz_get_table_name( 'data_points' ) . " WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC", $dataset_id ), ARRAY_A );
    $relations = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . viswiz_get_table_name( 'relations' ) . " WHERE dataset_id = %d ORDER BY sort_order ASC, id ASC", $dataset_id ), ARRAY_A );
    if ( viswiz_is_graph_like_type( $type ) ) {
        return array(
            'nodes' => array_map(
                function ( $point ) {
                    $meta = json_decode( $point['meta'] ?? '[]', true );
                    $node = is_array( $meta ) ? $meta : array();
                    $node['id'] = $point['point_key'];
                    $node['label'] = $node['label'] ?? $point['label'];

                    return $node;
                },
                $points
            ),
            'links' => array_map(
                function ( $relation ) {
                    return array(
                        'from' => $relation['from_key'],
                        'to' => $relation['to_key'],
                        'label' => $relation['label'],
                        'direction' => $relation['direction'],
                        'intensity' => (float) $relation['intensity'],
                        'relation_type' => $relation['relation_type'],
                    );
                },
                $relations
            ),
        );
    }
    return array_map(
        function ( $point ) {
            return array(
                'label' => $point['label'],
                'value' => (float) $point['value'],
                'color' => $point['color'],
            );
        },
        $points
    );
}

function viswiz_render_visualization( $post_id ) {
    $meta = viswiz_get_visualization_meta( $post_id );
    $dataset_payload = viswiz_get_dataset_payload( $meta['dataset_id'], $meta['type'] );
    if ( $dataset_payload ) {
        if ( $meta['type'] === 'progress' ) {
            $meta['manual_progress'] = $dataset_payload;
            $meta['source'] = 'manual';
        } elseif ( viswiz_is_graph_like_type( $meta['type'] ) ) {
            $meta['graph_data'] = $dataset_payload;
        } else {
            $meta['manual_pie'] = $dataset_payload;
            $meta['source'] = 'manual';
        }
    }
    $show_fullscreen_toggle = empty( $meta['format_colors']['show_fullscreen_toggle'] ) ? '0' : '1';
    $data_attrs = sprintf(
        'data-type="%s" data-label="%s" data-title="%s" data-target="%s" data-scope="%s" data-period-mode="%s" data-period-value="%s" data-period-unit="%s" data-period-start="%s" data-product-ids="%s" data-category-ids="%s" data-animation="%s" data-colors="%s" data-show-fullscreen-toggle="%s"',
        esc_attr( $meta['source'] ),
        esc_attr( $meta['label'] ),
        esc_attr( $meta['label'] ),
        esc_attr( $meta['target'] ),
        esc_attr( $meta['scope'] ),
        esc_attr( $meta['period_mode'] ),
        esc_attr( $meta['period_value'] ),
        esc_attr( $meta['period_unit'] ),
        esc_attr( $meta['period_start'] ),
        esc_attr( implode( ',', $meta['product_ids'] ) ),
        esc_attr( implode( ',', $meta['category_ids'] ) ),
        esc_attr( $meta['animation'] ),
        esc_attr( viswiz_json_encode( $meta['format_colors'] ) ),
        esc_attr( $show_fullscreen_toggle )
    );

    if ( $meta['type'] === 'progress' ) {
        $manual_json = $meta['source'] === 'manual' ? esc_attr( viswiz_json_encode( $meta['manual_progress'] ) ) : '';
        return sprintf( '<div class="viswiz-progress" %s data-manual="%s"></div>', $data_attrs, $manual_json );
    }

    if ( viswiz_is_chart_like_type( $meta['type'] ) ) {
        $manual_json = $meta['source'] === 'manual' ? esc_attr( viswiz_json_encode( $meta['manual_pie'] ) ) : '';
        return sprintf( '<div class="viswiz-pie" %s data-manual="%s"></div>', $data_attrs, $manual_json );
    }

    if ( viswiz_is_diagram_like_type( $meta['type'] ) ) {
        $manual_json = esc_attr( viswiz_json_encode( $meta['diagram_data'] ) );
        return sprintf( '<div class="viswiz-diagram" %s data-manual="%s"></div>', $data_attrs, $manual_json );
    }

    if ( viswiz_is_graph_like_type( $meta['type'] ) ) {
        $manual_json = esc_attr( viswiz_json_encode( viswiz_prepare_graph_data_for_display( $meta['graph_data'] ) ) );
        $node_radius = esc_attr( $meta['format_colors']['node_radius'] ?? '20' );
        $link_distance = esc_attr( $meta['format_colors']['link_distance'] ?? '100' );
        $charge_strength = esc_attr( $meta['format_colors']['charge_strength'] ?? '-300' );
        $node_style = esc_attr( $meta['format_colors']['node_style'] ?? 'card' );
        $node_label_style = esc_attr( $meta['format_colors']['node_label_style'] ?? 'rounded' );
        $node_card_width = esc_attr( $meta['format_colors']['node_card_width'] ?? '150' );
        $show_node_images = empty( $meta['format_colors']['show_node_images'] ) ? '0' : '1';
        $show_type_badges = empty( $meta['format_colors']['show_type_badges'] ) ? '0' : '1';
        $show_graph_toolbar = empty( $meta['format_colors']['show_graph_toolbar'] ) ? '0' : '1';
        $show_graph_search = empty( $meta['format_colors']['show_graph_search'] ) ? '0' : '1';
        $show_graph_filters = empty( $meta['format_colors']['show_graph_filters'] ) ? '0' : '1';
        $show_graph_zoom = empty( $meta['format_colors']['show_graph_zoom'] ) ? '0' : '1';
        $show_relation_labels = empty( $meta['format_colors']['show_relation_labels'] ) ? '0' : '1';
        $scale_nodes_by_relations = empty( $meta['format_colors']['scale_nodes_by_relations'] ) ? '0' : '1';
        $relation_size_step = esc_attr( $meta['format_colors']['relation_size_step'] ?? '3' );
        $max_relation_size_boost = esc_attr( $meta['format_colors']['max_relation_size_boost'] ?? '30' );
        $graph_filter_mode = esc_attr( $meta['format_colors']['graph_filter_mode'] ?? 'fade' );
        return sprintf(
            '<div class="viswiz-graph" %s data-manual="%s" data-node-radius="%s" data-link-distance="%s" data-charge-strength="%s" data-node-style="%s" data-node-label-style="%s" data-node-card-width="%s" data-scale-nodes-by-relations="%s" data-relation-size-step="%s" data-max-relation-size-boost="%s" data-show-node-images="%s" data-show-type-badges="%s" data-show-graph-toolbar="%s" data-show-graph-search="%s" data-show-graph-filters="%s" data-show-graph-zoom="%s" data-show-relation-labels="%s" data-graph-filter-mode="%s"></div>',
            $data_attrs,
            $manual_json,
            $node_radius,
            $link_distance,
            $charge_strength,
            $node_style,
            $node_label_style,
            $node_card_width,
            $scale_nodes_by_relations,
            $relation_size_step,
            $max_relation_size_boost,
            $show_node_images,
            $show_type_badges,
            $show_graph_toolbar,
            $show_graph_search,
            $show_graph_filters,
            $show_graph_zoom,
            $show_relation_labels,
            $graph_filter_mode
        );
    }

    return '<div class="viswiz-message">Unsupported visualization.</div>';
}

function viswiz_get_visualizations() {
    $posts = get_posts(
        array(
            'post_type' => 'viswiz_visualization',
            'posts_per_page' => 100,
            'post_status' => 'publish',
        )
    );

    $data = array();
    foreach ( $posts as $post ) {
        $meta = viswiz_get_visualization_meta( $post->ID );
        $data[] = array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'type' => $meta['type'],
            'source' => $meta['source'],
            'shortcode' => viswiz_get_visualization_shortcode( $post->ID ),
            'editUrl' => get_edit_post_link( $post->ID, 'raw' ),
        );
    }

    return $data;
}

function viswiz_can_access_visualizations() {
    return current_user_can( 'edit_posts' );
}

function viswiz_get_visualization_shortcode( $post_id ) {
    return sprintf( '[viswiz_visualization id="%d"]', (int) $post_id );
}

function viswiz_add_visualization_columns( $columns ) {
    $columns['viswiz_shortcode'] = 'Shortcode';
    return $columns;
}

function viswiz_render_visualization_columns( $column, $post_id ) {
    if ( $column !== 'viswiz_shortcode' ) {
        return;
    }

    $field_id = 'viswiz_shortcode_' . (int) $post_id;
    printf(
        '<div class="viswiz-copy-field"><input id="%s" type="text" class="viswiz-shortcode-field" readonly value="%s" onclick="this.select();" /><button type="button" class="button button-small" data-viswiz-copy-target="%s">Copy</button></div>',
        esc_attr( $field_id ),
        esc_attr( viswiz_get_visualization_shortcode( $post_id ) ),
        esc_attr( $field_id )
    );
}


function viswiz_ajax_autosave_graph_node() {
    check_ajax_referer( 'viswiz_node_type_autosave', 'nonce' );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'You are not allowed to edit this visualization.' ), 403 );
    }

    if ( ! isset( $_POST['viswiz_meta'] ) || ! is_array( $_POST['viswiz_meta'] ) ) {
        wp_send_json_error( array( 'message' => 'Missing visualization data.' ), 400 );
    }

    $meta = wp_unslash( $_POST['viswiz_meta'] );
    $raw_graph_data = $meta['graph_data'] ?? array();
    $autosave_context = sanitize_key( $_POST['viswiz_autosave_context'] ?? '' );

    if ( $autosave_context === 'node' ) {
        $graph_data = viswiz_update_single_graph_node_data(
            $post_id,
            $raw_graph_data,
            sanitize_key( wp_unslash( $_POST['viswiz_node_original_id'] ?? '' ) )
        );
        if ( is_wp_error( $graph_data ) ) {
            wp_send_json_error( array( 'message' => $graph_data->get_error_message() ), 400 );
        }
        update_post_meta( $post_id, 'viswiz_graph_data', viswiz_json_encode( $graph_data ) );
        viswiz_sync_graph_tables_from_saved_meta( $post_id, $graph_data );
        wp_send_json_success( array( 'message' => __( 'Node changes autosaved without rewriting relations.', 'viswiz' ) ) );
    }

    $graph_json = viswiz_sanitize_graph_option( $raw_graph_data );
    $graph_data = json_decode( $graph_json, true ) ?: array();
    $graph_data = viswiz_preserve_existing_graph_links_for_node_autosave( $post_id, $raw_graph_data, $graph_data, $autosave_context );
    update_post_meta( $post_id, 'viswiz_graph_data', viswiz_json_encode( $graph_data ) );
    viswiz_sync_graph_tables_from_saved_meta( $post_id, $graph_data );

    wp_send_json_success( array( 'message' => __( 'Graph changes autosaved.', 'viswiz' ) ) );
}

function viswiz_ajax_autosave_node_type() {
    check_ajax_referer( 'viswiz_node_type_autosave', 'nonce' );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
        wp_send_json_error( array( 'message' => 'You are not allowed to edit this visualization.' ), 403 );
    }

    $raw_node_type = sanitize_key( $_POST['node_type'] ?? '' );
    $raw_node_subtype = sanitize_key( $_POST['node_subtype'] ?? '' );
    $raw_proposed_status = sanitize_key( $_POST['proposed_subtype_status'] ?? 'proposed' );
    $raw_proposed_label = sanitize_text_field( $_POST['proposed_subtype_label'] ?? '' );
    list( $node_type, $node_subtype ) = viswiz_sanitize_node_type_payload( $raw_node_type, $raw_node_subtype );
    $node_subtype = viswiz_maybe_approve_node_subtype_payload( $node_type, $node_subtype, $raw_proposed_label, $raw_proposed_status );
    list( $node_type, $node_subtype ) = viswiz_sanitize_node_type_payload( $node_type, $node_subtype );
    $node_id = sanitize_key( $_POST['node_id'] ?? '' );
    $node_index = isset( $_POST['node_index'] ) ? absint( $_POST['node_index'] ) : null;
    if ( $node_id === '' && $node_index === null ) {
        wp_send_json_error( array( 'message' => 'Missing node identifier.' ), 400 );
    }

    $graph_data = json_decode( get_post_meta( $post_id, 'viswiz_graph_data', true ) ?: '[]', true );
    if ( ! is_array( $graph_data ) ) {
        $graph_data = array();
    }
    if ( ! isset( $graph_data['nodes'] ) || ! is_array( $graph_data['nodes'] ) ) {
        $graph_data['nodes'] = array();
    }
    if ( ! isset( $graph_data['links'] ) || ! is_array( $graph_data['links'] ) ) {
        $graph_data['links'] = array();
    }

    $target_index = null;
    foreach ( $graph_data['nodes'] as $index => $node ) {
        if ( $node_id !== '' && sanitize_key( $node['id'] ?? '' ) === $node_id ) {
            $target_index = $index;
            break;
        }
    }
    if ( $target_index === null && $node_index !== null && isset( $graph_data['nodes'][ $node_index ] ) ) {
        $target_index = $node_index;
    }
    if ( $target_index === null ) {
        $target_index = count( $graph_data['nodes'] );
        $graph_data['nodes'][ $target_index ] = array(
            'id' => $node_id ?: 'node-' . ( $target_index + 1 ),
            'label' => sanitize_text_field( $_POST['node_label'] ?? '' ),
            'title' => sanitize_text_field( $_POST['node_title'] ?? '' ),
            'description' => '',
            'custom_labels' => array(),
        );
    }

    $graph_data['nodes'][ $target_index ]['node_type'] = $node_type;
    $graph_data['nodes'][ $target_index ]['node_subtype'] = $node_subtype;
    $graph_data['nodes'][ $target_index ]['entity_type'] = $node_type;
    if ( isset( $_POST['node_title'] ) ) {
        $graph_data['nodes'][ $target_index ]['title'] = sanitize_text_field( $_POST['node_title'] );
    }
    if ( isset( $_POST['node_label'] ) ) {
        $graph_data['nodes'][ $target_index ]['label'] = sanitize_text_field( $_POST['node_label'] );
    }

    $proposed_status = $raw_proposed_status;
    if ( ! in_array( $proposed_status, array( 'proposed', 'approved', 'merged', 'renamed', 'rejected' ), true ) ) {
        $proposed_status = 'proposed';
    }
    $graph_data['nodes'][ $target_index ]['proposed_subtype_label'] = sanitize_text_field( $_POST['proposed_subtype_label'] ?? '' );
    $graph_data['nodes'][ $target_index ]['proposed_subtype_reason'] = sanitize_textarea_field( $_POST['proposed_subtype_reason'] ?? '' );
    $graph_data['nodes'][ $target_index ]['proposed_subtype_example'] = sanitize_text_field( $_POST['proposed_subtype_example'] ?? '' );
    $graph_data['nodes'][ $target_index ]['proposed_subtype_gap'] = sanitize_textarea_field( $_POST['proposed_subtype_gap'] ?? '' );
    $graph_data['nodes'][ $target_index ]['proposed_subtype_status'] = $proposed_status;

    update_post_meta( $post_id, 'viswiz_graph_data', viswiz_json_encode( $graph_data ) );

    wp_send_json_success(
        array(
            'node_type' => $node_type,
            'node_subtype' => $node_subtype,
            'node_subtypes' => viswiz_get_graph_node_subtype_options( $node_type ),
            'all_subtypes' => viswiz_get_graph_node_subtypes_for_script(),
        )
    );
}

function viswiz_ajax_register_node_subtype() {
    check_ajax_referer( 'viswiz_node_type_autosave', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Only admins can update the global node schema.' ), 403 );
    }
    $node_type = sanitize_key( $_POST['node_type'] ?? '' );
    $label = sanitize_text_field( $_POST['label'] ?? '' );
    $preferred_slug = sanitize_key( $_POST['slug'] ?? '' );
    $slug = viswiz_register_node_subtype_in_schema( $node_type, $label, $preferred_slug );
    if ( ! $slug ) {
        wp_send_json_error( array( 'message' => 'Subtype could not be registered.' ), 400 );
    }
    wp_send_json_success(
        array(
            'node_type' => $node_type,
            'subtype' => $slug,
            'label' => $label,
            'node_subtypes' => viswiz_get_graph_node_subtype_options( $node_type ),
            'all_subtypes' => viswiz_get_graph_node_subtypes_for_script(),
        )
    );
}

function viswiz_enqueue_admin_assets( $hook ) {
    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    $is_viswiz_admin = in_array( $screen->id, array( 'toplevel_page_viswiz', 'viswiz_page_viswiz-settings', 'viswiz_page_viswiz-datasets', 'viswiz_page_viswiz-node-types', 'viswiz_page_viswiz-relation-types' ), true );
    $is_viswiz_post = $screen->post_type === 'viswiz_visualization';

    if ( ! $is_viswiz_admin && ! $is_viswiz_post ) {
        return;
    }

    wp_enqueue_style(
        'viswiz-admin-style',
        plugins_url( 'assets/viswiz.css', __FILE__ ),
        array(),
        VISWIZ_VERSION
    );

    wp_enqueue_media();
    wp_enqueue_editor();
    viswiz_register_d3_script();

    wp_enqueue_script(
        'viswiz-script',
        plugins_url( 'assets/viswiz.js', __FILE__ ),
        array( 'd3' ),
        VISWIZ_VERSION,
        true
    );

    wp_localize_script(
        'viswiz-script',
        'VisWizData',
        array(
            'restUrl' => esc_url_raw( rest_url( 'viswiz/v1' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'target' => (float) get_option( VISWIZ_OPTION_TARGET, 0 ),
            'manualProgress' => viswiz_get_manual_progress(),
            'manualPie' => viswiz_get_manual_pie(),
            'diagramData' => viswiz_get_diagram_data(),
            'graphData' => viswiz_prepare_graph_data_for_display( viswiz_get_graph_data() ),
            'currencyCode' => viswiz_get_currency_code(),
            'currencySymbol' => viswiz_get_currency_symbol(),
            'skipAutoInit' => true,
        )
    );

    wp_enqueue_script(
        'viswiz-admin',
        plugins_url( 'assets/viswiz-admin.js', __FILE__ ),
        array( 'jquery', 'wp-editor', 'd3', 'viswiz-script' ),
        VISWIZ_VERSION,
        true
    );

    wp_localize_script(
        'viswiz-admin',
        'VisWizAdmin',
        array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'viswiz_node_type_autosave' ),
            'postId' => $is_viswiz_post ? (int) ( get_the_ID() ?: filter_input( INPUT_GET, 'post', FILTER_VALIDATE_INT ) ) : 0,
            'nodeTypes' => viswiz_node_type_options_for_script(),
            'nodeSubtypes' => viswiz_get_graph_node_subtypes_for_script(),
            'relationTypes' => viswiz_relation_type_options_for_script(),
            'i18n' => viswiz_get_admin_i18n(),
        )
    );

    if ( class_exists( 'WooCommerce' ) ) {
        wp_enqueue_script( 'select2' );
        wp_enqueue_style( 'select2' );
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_style( 'woocommerce_admin_styles' );
        wp_enqueue_script( 'wc-product-search' );
        wp_enqueue_script( 'selectWoo' );
        wp_enqueue_style( 'selectWoo' );
        wp_add_inline_script(
            'viswiz-admin',
            'jQuery(function($){$(document.body).trigger("wc-enhanced-select-init");});'
        );
    }
}


function viswiz_render_dataset_select( $name, $selected_id = 0 ) {
    global $wpdb;
    $datasets = $wpdb->get_results( "SELECT id, name, data_type FROM " . viswiz_get_table_name( 'datasets' ) . " ORDER BY name ASC LIMIT 200" );
    $html = sprintf( '<select name="%s" id="viswiz_dataset_id">', esc_attr( $name ) );
    $html .= '<option value="0">Visualization-specific data</option>';
    foreach ( $datasets as $dataset ) {
        $html .= sprintf(
            '<option value="%d" %s>%s</option>',
            (int) $dataset->id,
            selected( (int) $selected_id, (int) $dataset->id, false ),
            esc_html( sprintf( '%s (%s)', $dataset->name, $dataset->data_type ) )
        );
    }
    $html .= '</select>';
    return $html;
}

function viswiz_save_visualization_tables( $post_id, array $meta ) {
    global $wpdb;
    viswiz_create_custom_tables();
    $now = current_time( 'mysql' );
    $dataset_id = (int) ( $meta['dataset_id'] ?? 0 );
    if ( ! empty( $meta['new_dataset_name'] ) ) {
        $wpdb->insert(
            viswiz_get_table_name( 'datasets' ),
            array(
                'name' => $meta['new_dataset_name'],
                'description' => sprintf( 'Created from visualization #%d.', $post_id ),
                'data_type' => $meta['type'],
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );
        $dataset_id = (int) $wpdb->insert_id;
        update_post_meta( $post_id, 'viswiz_dataset_id', $dataset_id );
    }

    $table = viswiz_get_table_name( 'visualization_data' );
    $existing_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE post_id = %d", $post_id ) );
    $row = array(
        'post_id' => $post_id,
        'visualization_type' => $meta['type'],
        'dataset_id' => $dataset_id,
        'legend' => viswiz_json_encode( array_filter( array_map( 'trim', explode( "\n", $meta['legend'] ?? '' ) ) ) ),
        'labels' => viswiz_json_encode( array_filter( array_map( 'trim', explode( "\n", $meta['axis_labels'] ?? '' ) ) ) ),
        'theme' => viswiz_json_encode( $meta['theme'] ?? array() ),
        'settings' => viswiz_json_encode( array( 'other' => $meta['other_settings'] ?? '' ) ),
        'updated_at' => $now,
    );
    if ( $existing_id ) {
        $wpdb->update( $table, $row, array( 'id' => $existing_id ) );
        $visualization_data_id = $existing_id;
    } else {
        $row['created_at'] = $now;
        $wpdb->insert( $table, $row );
        $visualization_data_id = (int) $wpdb->insert_id;
    }

    viswiz_replace_table_points( $visualization_data_id, $dataset_id, $meta );
}

function viswiz_replace_table_points( $visualization_id, $dataset_id, array $meta ) {
    global $wpdb;
    $points_table = viswiz_get_table_name( 'data_points' );
    $relations_table = viswiz_get_table_name( 'relations' );
    $wpdb->delete( $points_table, array( 'visualization_id' => $visualization_id ) );
    $wpdb->delete( $relations_table, array( 'visualization_id' => $visualization_id ) );
    if ( $dataset_id ) {
        $wpdb->delete( $points_table, array( 'dataset_id' => $dataset_id ) );
        $wpdb->delete( $relations_table, array( 'dataset_id' => $dataset_id ) );
    }
    $type = $meta['type'];
    if ( $type === 'progress' ) {
        foreach ( $meta['manual_progress'] as $i => $item ) {
            viswiz_insert_point( $visualization_id, $dataset_id, 'progress-' . $i, $item['label'] ?? '', $item['value'] ?? 0, '', $item, $i );
        }
    } elseif ( viswiz_is_chart_like_type( $type ) ) {
        foreach ( $meta['manual_pie'] as $i => $item ) {
            viswiz_insert_point( $visualization_id, $dataset_id, sanitize_title( $item['label'] ?? 'point-' . $i ), $item['label'] ?? '', $item['value'] ?? 0, $item['color'] ?? '', $item, $i );
        }
    } elseif ( viswiz_is_graph_like_type( $type ) ) {
        foreach ( $meta['graph_data']['nodes'] ?? array() as $i => $node ) {
            viswiz_insert_point( $visualization_id, $dataset_id, $node['id'] ?? '', $node['label'] ?? '', 0, '', $node, $i );
        }
        foreach ( $meta['graph_data']['links'] ?? array() as $i => $link ) {
            viswiz_insert_relation( $visualization_id, $dataset_id, $link, $i );
        }
    } else {
        foreach ( $meta['diagram_data'] as $i => $section ) {
            viswiz_insert_point( $visualization_id, $dataset_id, sanitize_title( $section['title'] ?? 'section-' . $i ), $section['title'] ?? '', 0, '', $section, $i );
        }
    }
}

function viswiz_insert_point( $visualization_id, $dataset_id, $key, $label, $value, $color, $meta, $order ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert( viswiz_get_table_name( 'data_points' ), array(
        'visualization_id' => $visualization_id,
        'dataset_id' => $dataset_id,
        'point_key' => sanitize_text_field( $key ),
        'label' => sanitize_text_field( $label ),
        'value' => (float) $value,
        'color' => sanitize_hex_color( $color ) ?: '',
        'meta' => viswiz_json_encode( $meta ),
        'sort_order' => (int) $order,
        'created_at' => $now,
        'updated_at' => $now,
    ) );
}

function viswiz_insert_relation( $visualization_id, $dataset_id, array $link, $order ) {
    global $wpdb;
    $now = current_time( 'mysql' );
    $wpdb->insert( viswiz_get_table_name( 'relations' ), array(
        'visualization_id' => $visualization_id,
        'dataset_id' => $dataset_id,
        'from_key' => sanitize_text_field( $link['from'] ?? '' ),
        'to_key' => sanitize_text_field( $link['to'] ?? '' ),
        'label' => sanitize_text_field( $link['label'] ?? '' ),
        'direction' => sanitize_key( $link['direction'] ?? 'directed' ),
        'intensity' => (float) ( $link['intensity'] ?? 1 ),
        'relation_type' => sanitize_text_field( $link['relation_type'] ?? '' ),
        'meta' => viswiz_json_encode( $link ),
        'sort_order' => (int) $order,
        'created_at' => $now,
        'updated_at' => $now,
    ) );
}

function viswiz_render_product_search_field( $name, $selected_ids, $multiple = false ) {
    $selected_ids = viswiz_sanitize_id_array( is_array( $selected_ids ) ? $selected_ids : array( $selected_ids ) );
    $field_id = sanitize_html_class( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );

    $options = '';
    if ( ! empty( $selected_ids ) ) {
        foreach ( $selected_ids as $selected_id ) {
            $product_title = get_the_title( $selected_id );
            $options .= sprintf( '<option value="%d" selected="selected">%s</option>', $selected_id, esc_html( $product_title ) );
        }
    }

    return sprintf(
        '<select id="%s" name="%s" class="wc-product-search" data-action="woocommerce_json_search_products_and_variations" data-placeholder="Search for a product" style="width: 300px;" %s>%s</select>',
        esc_attr( $field_id ),
        esc_attr( $name ),
        $multiple ? 'multiple="multiple"' : '',
        $options
    );
}

function viswiz_render_category_select_field( $name, $selected_ids, $field_id = '', $multiple = false ) {
    $field_id = $field_id ?: sanitize_html_class( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
    $selected_ids = viswiz_sanitize_id_array( is_array( $selected_ids ) ? $selected_ids : array( $selected_ids ) );
    if ( $multiple ) {
        $categories = get_terms(
            array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
            )
        );
        $options = '';
        if ( ! is_wp_error( $categories ) ) {
            foreach ( $categories as $category ) {
                $options .= sprintf(
                    '<option value="%d" %s>%s</option>',
                    (int) $category->term_id,
                    selected( in_array( (int) $category->term_id, $selected_ids, true ), true, false ),
                    esc_html( $category->name )
                );
            }
        }
        return sprintf(
            '<select name="%s" id="%s" class="wc-enhanced-select" multiple="multiple" style="width: 300px;">%s</select>',
            esc_attr( $name ),
            esc_attr( $field_id ),
            $options
        );
    }

    return wp_dropdown_categories(
        array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'id' => $field_id,
            'selected' => $selected_ids[0] ?? 0,
            'show_option_none' => 'Select category',
            'option_none_value' => 0,
            'class' => 'wc-enhanced-select',
            'echo' => false,
        )
    );
}
