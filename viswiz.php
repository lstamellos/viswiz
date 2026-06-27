<?php
/**
 * Plugin Name: VisWiz WooCommerce Visualizer
 * Description: Real-time progress bars, charts, diagrams, and graph visualizations based on WooCommerce sales, custom datasets, or manual inputs.
 * Version: 1.2.4
 * Author: cremedia.studio
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const VISWIZ_VERSION = '1.2.4';
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

register_activation_hook( __FILE__, 'viswiz_activate' );
add_action( 'init', 'viswiz_register_shortcodes' );
add_action( 'rest_api_init', 'viswiz_register_rest_routes' );
add_action( 'wp_enqueue_scripts', 'viswiz_enqueue_assets' );
add_action( 'admin_menu', 'viswiz_register_admin_menu' );
add_action( 'admin_init', 'viswiz_register_settings' );
add_action( 'admin_init', 'viswiz_maybe_upgrade_tables' );
add_action( 'init', 'viswiz_register_visualizations_cpt' );
add_action( 'init', 'viswiz_register_block_assets' );
add_action( 'add_meta_boxes', 'viswiz_register_visualization_meta_box' );
add_action( 'save_post_viswiz_visualization', 'viswiz_save_visualization_meta' );
add_action( 'admin_enqueue_scripts', 'viswiz_enqueue_admin_assets' );
add_filter( 'manage_viswiz_visualization_posts_columns', 'viswiz_add_visualization_columns' );
add_action( 'manage_viswiz_visualization_posts_custom_column', 'viswiz_render_visualization_columns', 10, 2 );


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

    update_option( 'viswiz_db_version', '1.2.4' );
}

function viswiz_get_table_name( $table ) {
    global $wpdb;
    return $wpdb->prefix . 'viswiz_' . $table;
}

function viswiz_get_supported_visualization_types() {
    return array(
        'pie' => 'Pie',
        'bar' => 'Bar',
        'column' => 'Column',
        'line' => 'Line',
        'area' => 'Area',
        'scatter' => 'Scatter',
        'progress' => 'Progress',
        'counter' => 'Counter',
        'timeline' => 'Timeline',
        'graph' => 'Graph',
        'flow_diagram' => 'Flow Diagram',
        'org_chart' => 'Org Chart',
        'map' => 'Map',
        'diagram' => 'Diagram (legacy)',
    );
}

function viswiz_is_graph_like_type( $type ) {
    return in_array( $type, array( 'graph', 'flow_diagram', 'org_chart' ), true );
}

function viswiz_maybe_upgrade_tables() {
    if ( get_option( 'viswiz_db_version' ) !== '1.2.4' ) {
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
    wp_register_script(
        'd3',
        'https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js',
        array(),
        '7.9.0',
        true
    );
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
        '<div class="viswiz-progress" data-type="%s" data-label="%s" data-target="%s" data-scope="%s" data-period-mode="%s" data-period-value="%s" data-period-unit="%s" data-period-start="%s" data-product-ids="%s" data-category-ids="%s"></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['label'] ),
        esc_attr( $target ),
        esc_attr( $atts['scope'] ),
        esc_attr( $atts['period_mode'] ),
        esc_attr( $atts['period_value'] ),
        esc_attr( $atts['period_unit'] ),
        esc_attr( $atts['period_start'] ),
        esc_attr( $atts['product_ids'] ),
        esc_attr( $atts['category_ids'] )
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
        '<div class="viswiz-pie" data-type="%s" data-title="%s" data-scope="%s" data-period-mode="%s" data-period-value="%s" data-period-unit="%s" data-period-start="%s" data-product-ids="%s" data-category-ids="%s"></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['title'] ),
        esc_attr( $atts['scope'] ),
        esc_attr( $atts['period_mode'] ),
        esc_attr( $atts['period_value'] ),
        esc_attr( $atts['period_unit'] ),
        esc_attr( $atts['period_start'] ),
        esc_attr( $atts['product_ids'] ),
        esc_attr( $atts['category_ids'] )
    );
}

function viswiz_diagram_shortcode() {
    return '<div class="viswiz-diagram"></div>';
}

function viswiz_graph_shortcode() {
    return '<div class="viswiz-graph"></div>';
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
        'manage_options',
        'viswiz-settings',
        'viswiz_render_settings_page',
        'dashicons-chart-pie',
        56
    );
    add_submenu_page(
        'viswiz-settings',
        'Visualizations',
        'Visualizations',
        'edit_posts',
        'edit.php?post_type=viswiz_visualization'
    );
    add_submenu_page(
        'viswiz-settings',
        'Add New Visualization',
        'Add New Visualization',
        'edit_posts',
        'post-new.php?post_type=viswiz_visualization'
    );
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
    $currency_symbol = viswiz_get_currency_symbol();
    if ( $sales_period_value <= 0 ) {
        $sales_period_value = 30;
        $sales_period_unit = 'day';
    }
    $sales_product_ids = viswiz_get_sales_product_ids();
    $sales_category_ids = viswiz_get_sales_category_ids();
    $active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'sales';
    ?>
    <div class="wrap">
        <h1>VisWiz Settings</h1>
        <p>
            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=viswiz_visualization' ) ); ?>" class="button button-primary">Add New Visualization</a>
            <span class="description">Create a reusable visualization with its own data and settings.</span>
        </p>
        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-settings&tab=sales' ) ); ?>" class="nav-tab <?php echo $active_tab === 'sales' ? 'nav-tab-active' : ''; ?>">Sales Data</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-settings&tab=progress' ) ); ?>" class="nav-tab <?php echo $active_tab === 'progress' ? 'nav-tab-active' : ''; ?>">Manual Progress</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-settings&tab=pie' ) ); ?>" class="nav-tab <?php echo $active_tab === 'pie' ? 'nav-tab-active' : ''; ?>">Manual Pie</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-settings&tab=diagram' ) ); ?>" class="nav-tab <?php echo $active_tab === 'diagram' ? 'nav-tab-active' : ''; ?>">Diagram</a>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-settings&tab=graph' ) ); ?>" class="nav-tab <?php echo $active_tab === 'graph' ? 'nav-tab-active' : ''; ?>">Graph</a>
        </h2>
        <form method="post" action="options.php">
            <?php settings_fields( 'viswiz_settings' ); ?>
            <table class="form-table" role="presentation">
                <?php if ( $active_tab === 'sales' ) : ?>
                    <tr>
                        <th scope="row"><label for="viswiz_sales_scope">Default Sales Scope</label></th>
                        <td>
                            <select name="viswiz_sales_scope" id="viswiz_sales_scope">
                                <option value="total" <?php selected( $sales_scope, 'total' ); ?>>All sales (total)</option>
                                <option value="product" <?php selected( $sales_scope, 'product' ); ?>>Specific product</option>
                                <option value="category" <?php selected( $sales_scope, 'category' ); ?>>Specific category</option>
                            </select>
                            <p class="description">Used when shortcodes or saved visualizations do not override scope. Example: <code>scope="product"</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="viswiz_currency_code">Currency</label></th>
                        <td>
                            <select name="viswiz_currency_code" id="viswiz_currency_code">
                                <?php foreach ( viswiz_get_currency_options() as $code => $label ) : ?>
                                    <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $currency_code, $code ); ?>>
                                        <?php echo esc_html( sprintf( '%s (%s)', $label, viswiz_get_currency_symbol_for_code( $code ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Controls how amounts are labeled in charts and progress bars. Example: <code>USD ($)</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="viswiz_sales_period_mode">Default Sales Period</label></th>
                        <td>
                            <select name="viswiz_sales_period_mode" id="viswiz_sales_period_mode">
                                <option value="relative" <?php selected( $sales_period_mode, 'relative' ); ?>>Period (value + unit)</option>
                                <option value="fixed" <?php selected( $sales_period_mode, 'fixed' ); ?>>From date/time until now</option>
                            </select>
                            <p class="description">Choose how the default period is calculated.</p>
                            <div class="viswiz-period-group" data-viswiz-period="relative">
                                <input type="number" name="viswiz_sales_period_value" id="viswiz_sales_period_value" value="<?php echo esc_attr( $sales_period_value ); ?>" min="1" class="small-text" />
                                <select name="viswiz_sales_period_unit" id="viswiz_sales_period_unit">
                                    <option value="day" <?php selected( $sales_period_unit, 'day' ); ?>>day(s)</option>
                                    <option value="month" <?php selected( $sales_period_unit, 'month' ); ?>>month(s)</option>
                                    <option value="year" <?php selected( $sales_period_unit, 'year' ); ?>>year(s)</option>
                                </select>
                                <p class="description">Example: <code>period_mode="relative" period_value="3" period_unit="month"</code>.</p>
                            </div>
                            <div class="viswiz-period-group" data-viswiz-period="fixed">
                                <input type="datetime-local" name="viswiz_sales_period_start" id="viswiz_sales_period_start" value="<?php echo esc_attr( $sales_period_start ); ?>" />
                                <p class="description">Example: <code>period_mode="fixed" period_start="2024-01-01T00:00"</code>.</p>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="viswiz_sales_product_id">Default Product</label></th>
                        <td>
                            <?php echo viswiz_render_product_search_field( 'viswiz_sales_product_ids[]', $sales_product_ids, true ); ?>
                            <p class="description">Used for product-scope charts. Example: <code>product_ids="123,456"</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="viswiz_sales_category_id">Default Category</label></th>
                        <td>
                            <?php echo viswiz_render_category_select_field( 'viswiz_sales_category_ids[]', $sales_category_ids, 'viswiz_sales_category_id', true ); ?>
                            <p class="description">Used for category-scope charts. Example: <code>category_ids="45,67"</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="viswiz_sales_target">Sales Target</label></th>
                        <td>
                            <input type="number" name="viswiz_sales_target" id="viswiz_sales_target" value="<?php echo esc_attr( get_option( VISWIZ_OPTION_TARGET, 0 ) ); ?>" step="0.01" class="regular-text" />
                            <p class="description">Default target for auto progress bars. Example: target 10000 for monthly revenue goal.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ( $active_tab === 'progress' ) : ?>
                    <tr>
                        <th scope="row"><label for="viswiz_manual_progress">Manual Progress Data</label></th>
                        <td>
                            <div id="viswiz-progress-rows" class="viswiz-repeatable">
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
                                                <button type="button" class="button viswiz-remove-target">Remove</button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" class="button viswiz-add-target" data-target-scope="settings">Add Target</button>
                                    <button type="button" class="button viswiz-remove-row">Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                            <button type="button" class="button" data-viswiz-add="progress">Add Progress Row</button>
                            <p class="description">Each row becomes a manual progress bar. Example: Label “Campaign”, Value 45, Target 100.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ( $active_tab === 'pie' ) : ?>
                    <tr>
                        <th scope="row"><label for="viswiz_manual_pie">Manual Pie Data</label></th>
                        <td>
                            <div id="viswiz-pie-rows" class="viswiz-repeatable">
                                <?php if ( empty( $pie_items ) ) : ?>
                                    <?php $pie_items = array( array( 'label' => '', 'value' => '', 'color' => '' ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $pie_items as $pie_item ) : ?>
                                    <div class="viswiz-row">
                                        <input type="text" name="viswiz_manual_pie[label][]" placeholder="Label" value="<?php echo esc_attr( $pie_item['label'] ?? '' ); ?>" class="regular-text" />
                                        <input type="number" name="viswiz_manual_pie[value][]" placeholder="Value" value="<?php echo esc_attr( $pie_item['value'] ?? '' ); ?>" step="0.01" />
                                        <input type="color" name="viswiz_manual_pie[color][]" value="<?php echo esc_attr( $pie_item['color'] ?? '' ); ?>" />
                                        <button type="button" class="button viswiz-remove-row">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="pie">Add Pie Slice</button>
                            <p class="description">Each row becomes a pie slice. Example: Label “Retail”, Value 30, Color #4caf50.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ( $active_tab === 'diagram' ) : ?>
                    <tr>
                        <th scope="row"><label for="viswiz_diagram_data">Diagram Data</label></th>
                        <td>
                            <div id="viswiz-diagram-sections" class="viswiz-repeatable">
                                <?php if ( empty( $diagram_sections ) ) : ?>
                                    <?php $diagram_sections = array( array( 'title' => '', 'items' => array( '' ) ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $diagram_sections as $section_index => $diagram_section ) : ?>
                                    <div class="viswiz-section" data-section-index="<?php echo esc_attr( $section_index ); ?>">
                                        <input type="text" name="viswiz_diagram_data[title][]" placeholder="Section Title" value="<?php echo esc_attr( $diagram_section['title'] ?? '' ); ?>" class="regular-text" />
                                        <div class="viswiz-items">
                                            <?php $items = $diagram_section['items'] ?? array( '' ); ?>
                                            <?php if ( empty( $items ) ) : ?>
                                                <?php $items = array( '' ); ?>
                                            <?php endif; ?>
                                            <?php foreach ( $items as $item_value ) : ?>
                                                <div class="viswiz-item-row">
                                                    <input type="text" name="viswiz_diagram_data[items][<?php echo esc_attr( $section_index ); ?>][]" placeholder="Item" value="<?php echo esc_attr( $item_value ); ?>" class="regular-text" />
                                                    <button type="button" class="button viswiz-remove-item">Remove</button>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="button" class="button viswiz-add-item">Add Item</button>
                                        <button type="button" class="button viswiz-remove-section">Remove Section</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="diagram">Add Diagram Section</button>
                            <p class="description">Each section is a diagram column with items. Example: “Stage” with “Idea, Build, Launch”.</p>
                        </td>
                    </tr>
                <?php endif; ?>
                <?php if ( $active_tab === 'graph' ) : ?>
                    <tr>
                        <th scope="row"><label for="viswiz_graph_data">Graph Data</label></th>
                        <td>
                            <div class="viswiz-graph">
                                <?php $dataset_label = viswiz_get_graph_dataset_label( 0 ); ?>
                                <h4>Nodes <span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span></h4>
                                <div id="viswiz-graph-nodes" class="viswiz-repeatable viswiz-card-list">
                                    <?php $nodes = $graph_data['nodes'] ?? array(); ?>
                                    <?php if ( empty( $nodes ) ) : ?>
                                        <?php $nodes = array( array( 'id' => '', 'label' => '', 'title' => '' ) ); ?>
                                    <?php endif; ?>
                                    <?php foreach ( $nodes as $node_index => $node ) : ?>
                                        <?php viswiz_render_graph_node_row( 'viswiz_graph_data[nodes]', $node, $node_index, $dataset_label ); ?>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" data-viswiz-add="graph-node">Add Node</button>
                                <p class="description">IDs are assigned automatically. Use the title, formatted description, image fields, and custom labels to enrich each node.</p>
                                <h4>Relations <span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span></h4>
                                <div id="viswiz-graph-links" class="viswiz-repeatable viswiz-card-list">
                                    <?php $links = $graph_data['links'] ?? array(); ?>
                                    <?php if ( empty( $links ) ) : ?>
                                        <?php $links = array( array( 'from' => '', 'to' => '', 'label' => '' ) ); ?>
                                    <?php endif; ?>
                                    <?php foreach ( $links as $link_index => $link ) : ?>
                                        <?php viswiz_render_graph_link_row( 'viswiz_graph_data[links]', $link, $link_index, $dataset_label ); ?>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="button" data-viswiz-add="graph-link">Add Relation</button>
                                <p class="description">Relations stay grouped in movable cards and display their dataset context.</p>
                            </div>
                            <p class="description">Nodes map to IDs and labels; links connect nodes for graph-style views.</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </table>
            <?php submit_button(); ?>
        </form>
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

function viswiz_get_node_auto_id( $node, $index ) {
    $id = $node['id'] ?? '';
    if ( $id === '' ) {
        $id = 'node-' . ( (int) $index + 1 );
    }
    return sanitize_key( $id );
}

function viswiz_render_graph_node_row( $name_prefix, $node = array(), $index = 0, $dataset_label = '' ) {
    $id = viswiz_get_node_auto_id( $node, $index );
    $title = $node['title'] ?? ( $node['label'] ?? '' );
    $description = $node['description'] ?? '';
    $main_image = absint( $node['main_image'] ?? 0 );
    $other_images = is_array( $node['other_images'] ?? null ) ? implode( ',', array_map( 'absint', $node['other_images'] ) ) : sanitize_text_field( $node['other_images'] ?? '' );
    $custom_labels = is_array( $node['custom_labels'] ?? null ) ? $node['custom_labels'] : array();
    if ( empty( $custom_labels ) ) {
        $custom_labels = array( array( 'key' => '', 'type' => 'short', 'value' => '' ) );
    }
    ?>
    <details class="viswiz-node-card viswiz-sortable-card" open data-viswiz-node-card data-node-index="<?php echo esc_attr( $index ); ?>">
        <summary>
            <span class="viswiz-drag-handle" aria-hidden="true">↕</span>
            <strong><?php echo esc_html( $title ?: 'New node' ); ?></strong>
            <code data-viswiz-node-id-display><?php echo esc_html( $id ); ?></code>
            <?php if ( $dataset_label ) : ?><span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span><?php endif; ?>
        </summary>
        <input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[id][]" value="<?php echo esc_attr( $id ); ?>" data-viswiz-node-id />
        <div class="viswiz-node-grid">
            <label>Title <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[title][]" placeholder="Node title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" data-viswiz-node-title /></label>
            <label>Short label <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[label][]" placeholder="Optional short label" value="<?php echo esc_attr( $node['label'] ?? '' ); ?>" class="regular-text" /></label>
            <label>Main image <span class="viswiz-media-field"><input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[main_image][]" value="<?php echo esc_attr( $main_image ); ?>" data-viswiz-media-value /><button type="button" class="button" data-viswiz-media-select="single">Select/upload</button><span data-viswiz-media-label><?php echo $main_image ? esc_html( '#' . $main_image ) : 'No image selected'; ?></span></span></label>
            <label>Other images <span class="viswiz-media-field"><input type="hidden" name="<?php echo esc_attr( $name_prefix ); ?>[other_images][]" value="<?php echo esc_attr( $other_images ); ?>" data-viswiz-media-value /><button type="button" class="button" data-viswiz-media-select="multiple">Select/upload</button><span data-viswiz-media-label><?php echo $other_images ? esc_html( $other_images ) : 'No images selected'; ?></span></span></label>
        </div>
        <label class="viswiz-full-field">Formatted description<?php wp_editor( wp_kses_post( $description ), 'viswiz_node_desc_' . md5( $name_prefix . $index ), array( 'textarea_name' => $name_prefix . '[description][]', 'textarea_rows' => 4, 'media_buttons' => false, 'teeny' => true ) ); ?></label>
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
        <p><button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button viswiz-remove-row">Remove node</button></p>
    </details>
    <?php
}

function viswiz_render_graph_link_row( $name_prefix, $link = array(), $index = 0, $dataset_label = '' ) {
    ?>
    <details class="viswiz-relation-card viswiz-sortable-card" open>
        <summary><span class="viswiz-drag-handle" aria-hidden="true">↕</span><strong><?php echo esc_html( $link['label'] ?? 'Relation' ); ?></strong><?php if ( $dataset_label ) : ?><span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span><?php endif; ?></summary>
        <div class="viswiz-relation-grid">
            <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[from][]" placeholder="From node ID" value="<?php echo esc_attr( $link['from'] ?? '' ); ?>" class="regular-text" />
            <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[to][]" placeholder="To node ID" value="<?php echo esc_attr( $link['to'] ?? '' ); ?>" class="regular-text" />
            <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[label][]" placeholder="Relation label" value="<?php echo esc_attr( $link['label'] ?? '' ); ?>" class="regular-text" />
            <select name="<?php echo esc_attr( $name_prefix ); ?>[direction][]"><option value="directed" <?php selected( $link['direction'] ?? 'directed', 'directed' ); ?>>Directed</option><option value="undirected" <?php selected( $link['direction'] ?? '', 'undirected' ); ?>>Undirected</option><option value="bidirectional" <?php selected( $link['direction'] ?? '', 'bidirectional' ); ?>>Bidirectional</option></select>
            <input type="number" name="<?php echo esc_attr( $name_prefix ); ?>[intensity][]" placeholder="Intensity" value="<?php echo esc_attr( $link['intensity'] ?? '1' ); ?>" min="0" step="0.01" />
            <input type="text" name="<?php echo esc_attr( $name_prefix ); ?>[relation_type][]" placeholder="Relation type" value="<?php echo esc_attr( $link['relation_type'] ?? '' ); ?>" class="regular-text" />
        </div>
        <p><button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button viswiz-remove-row">Remove relation</button></p>
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
            'main_image' => absint( $node_main_images[ $index ] ?? 0 ),
            'other_images' => array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $node_other_images[ $index ] ?? '' ) ) ) ) ),
            'custom_labels' => $custom_labels,
        );
    }

    $link_from = $links['from'] ?? array();
    $link_to = $links['to'] ?? array();
    $link_label = $links['label'] ?? array();
    foreach ( $link_from as $index => $from ) {
        $from = sanitize_text_field( $from );
        $to = sanitize_text_field( $link_to[ $index ] ?? '' );
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
            'relation_type' => sanitize_text_field( $relation_types[ $index ] ?? '' ),
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

function viswiz_prepare_graph_data_for_display( $graph_data ) {
    if ( ! is_array( $graph_data ) ) {
        return array();
    }

    foreach ( $graph_data['nodes'] ?? array() as $index => $node ) {
        $main_image_id = absint( $node['main_image'] ?? 0 );
        if ( $main_image_id ) {
            $graph_data['nodes'][ $index ]['main_image_url'] = wp_get_attachment_image_url( $main_image_id, 'medium_large' ) ?: '';
        }

        $other_image_urls = array();
        foreach ( $node['other_images'] ?? array() as $image_id ) {
            $image_url = wp_get_attachment_image_url( absint( $image_id ), 'medium' );
            if ( $image_url ) {
                $other_image_urls[] = $image_url;
            }
        }
        $graph_data['nodes'][ $index ]['other_image_urls'] = $other_image_urls;
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

function viswiz_sanitize_format_colors( $colors ) {
    if ( ! is_array( $colors ) ) {
        return array();
    }
    $keys = array( 'primary', 'secondary', 'accent', 'background', 'text' );
    $sanitized = array();
    foreach ( $keys as $key ) {
        if ( isset( $colors[ $key ] ) ) {
            $sanitized[ $key ] = sanitize_hex_color( $colors[ $key ] ) ?: '';
        }
    }
    return $sanitized;
}

function viswiz_get_visualization_format_colors( $post_id ) {
    $colors = get_post_meta( $post_id, 'viswiz_format_colors', true );
    return viswiz_sanitize_format_colors( is_array( $colors ) ? $colors : array() );
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
        'Visualization Settings',
        'viswiz_render_visualization_meta_box',
        'viswiz_visualization',
        'normal',
        'default'
    );
}

function viswiz_render_visualization_meta_box( WP_Post $post ) {
    wp_nonce_field( 'viswiz_visualization_save', 'viswiz_visualization_nonce' );
    $meta = viswiz_get_visualization_meta( $post->ID );
    $manual_progress = $meta['manual_progress'];
    $manual_pie = $meta['manual_pie'];
    $diagram_data = $meta['diagram_data'];
    $graph_data = $meta['graph_data'];
    ?>
    <div class="viswiz-meta-tabs">
        <button type="button" class="button viswiz-tab-button is-active" data-viswiz-tab="data">Data</button>
        <button type="button" class="button viswiz-tab-button" data-viswiz-tab="formatting">Formatting</button>
        <button type="button" class="button viswiz-tab-button" data-viswiz-tab="preview">Preview</button>
    </div>
    <div class="viswiz-tab-panel is-active" data-viswiz-panel="data">
    <p>
        <label for="viswiz_shortcode"><strong>Shortcode</strong></label><br />
        <input type="text" id="viswiz_shortcode" class="large-text" readonly value="<?php echo esc_attr( viswiz_get_visualization_shortcode( $post->ID ) ); ?>" />
        <span class="description">Copy and paste this shortcode into any post or page.</span>
    </p>
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
        <label for="viswiz_legend">Legend</label>
        <textarea name="viswiz_meta[legend]" id="viswiz_legend" class="large-text" rows="2"><?php echo esc_textarea( $meta['legend'] ); ?></textarea>
    </p>
    <p>
        <label for="viswiz_axis_labels">Labels / Axis Labels</label>
        <textarea name="viswiz_meta[axis_labels]" id="viswiz_axis_labels" class="large-text" rows="2"><?php echo esc_textarea( $meta['axis_labels'] ); ?></textarea>
    </p>
    <p>
        <label for="viswiz_other_settings">Other Settings</label>
        <textarea name="viswiz_meta[other_settings]" id="viswiz_other_settings" class="large-text" rows="3"><?php echo esc_textarea( $meta['other_settings'] ); ?></textarea>
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
        <h4>Manual Pie</h4>
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
        <button type="button" class="button" data-viswiz-add="visual-pie">Add Pie Slice</button>
    </div>
    <div class="viswiz-field-group" data-viswiz-types="diagram,flow_diagram,org_chart,timeline">
        <h4>Manual Diagram</h4>
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
        <button type="button" class="button" data-viswiz-add="visual-diagram">Add Diagram Section</button>
    </div>
    <div class="viswiz-field-group" data-viswiz-types="graph,flow_diagram,org_chart">
        <h4>Manual Graph</h4>
        <div class="viswiz-graph">
            <?php $dataset_label = viswiz_get_graph_dataset_label( $meta['dataset_id'] ); ?>
            <h5>Nodes <span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span></h5>
            <div id="viswiz-visual-graph-nodes" class="viswiz-repeatable viswiz-card-list">
                <?php $nodes = $graph_data['nodes'] ?? array(); ?>
                <?php if ( empty( $nodes ) ) : ?>
                    <?php $nodes = array( array( 'id' => '', 'label' => '', 'title' => '' ) ); ?>
                <?php endif; ?>
                <?php foreach ( $nodes as $node_index => $node ) : ?>
                    <?php viswiz_render_graph_node_row( 'viswiz_meta[graph_data][nodes]', $node, $node_index, $dataset_label ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" data-viswiz-add="visual-graph-node">Add Node</button>
            <h5>Relations <span class="viswiz-dataset-badge"><?php echo esc_html( $dataset_label ); ?></span></h5>
            <div id="viswiz-visual-graph-links" class="viswiz-repeatable viswiz-card-list">
                <?php $links = $graph_data['links'] ?? array(); ?>
                <?php if ( empty( $links ) ) : ?>
                    <?php $links = array( array( 'from' => '', 'to' => '', 'label' => '' ) ); ?>
                <?php endif; ?>
                <?php foreach ( $links as $link_index => $link ) : ?>
                    <?php viswiz_render_graph_link_row( 'viswiz_meta[graph_data][links]', $link, $link_index, $dataset_label ); ?>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button" data-viswiz-add="visual-graph-link">Add Relation</button>
        </div>
    </div>
    </div>
    <div class="viswiz-tab-panel" data-viswiz-panel="formatting">
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
        <div class="viswiz-field-group" data-viswiz-types="graph,flow_diagram,org_chart">
            <h4>Graph Options</h4>
            <p>
                <label for="viswiz_graph_node_radius">Node Size</label>
                <input type="number" name="viswiz_meta[format_colors][node_radius]" id="viswiz_graph_node_radius" value="<?php echo esc_attr( $meta['format_colors']['node_radius'] ?? '20' ); ?>" min="10" max="50" step="1" class="small-text" />
                <span class="description">Radius of graph nodes (10-50 pixels)</span>
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
        </div>
    </div>
    <div class="viswiz-tab-panel" data-viswiz-panel="preview">
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
    $graph_json = viswiz_sanitize_graph_option( $meta['graph_data'] ?? array() );

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
        'graph_data' => json_decode( $graph_json, true ) ?: array(),
    ) );
}

function viswiz_get_visualization_meta( $post_id ) {
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
        'dataset_id' => (int) get_post_meta( $post_id, 'viswiz_dataset_id', true ),
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
    $data_attrs = sprintf(
        'data-type="%s" data-label="%s" data-title="%s" data-target="%s" data-scope="%s" data-period-mode="%s" data-period-value="%s" data-period-unit="%s" data-period-start="%s" data-product-ids="%s" data-category-ids="%s" data-animation="%s" data-colors="%s"',
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
        esc_attr( viswiz_json_encode( $meta['format_colors'] ) )
    );

    if ( $meta['type'] === 'progress' ) {
        $manual_json = $meta['source'] === 'manual' ? esc_attr( viswiz_json_encode( $meta['manual_progress'] ) ) : '';
        return sprintf( '<div class="viswiz-progress" %s data-manual="%s"></div>', $data_attrs, $manual_json );
    }

    if ( in_array( $meta['type'], array( 'pie', 'bar', 'column', 'line', 'area', 'scatter', 'counter', 'timeline', 'map' ), true ) ) {
        $manual_json = $meta['source'] === 'manual' ? esc_attr( viswiz_json_encode( $meta['manual_pie'] ) ) : '';
        return sprintf( '<div class="viswiz-pie" %s data-manual="%s"></div>', $data_attrs, $manual_json );
    }

    if ( in_array( $meta['type'], array( 'diagram', ), true ) ) {
        $manual_json = esc_attr( viswiz_json_encode( $meta['diagram_data'] ) );
        return sprintf( '<div class="viswiz-diagram" data-manual="%s"></div>', $manual_json );
    }

    if ( viswiz_is_graph_like_type( $meta['type'] ) ) {
        $manual_json = esc_attr( viswiz_json_encode( viswiz_prepare_graph_data_for_display( $meta['graph_data'] ) ) );
        $node_radius = esc_attr( $meta['format_colors']['node_radius'] ?? '20' );
        $link_distance = esc_attr( $meta['format_colors']['link_distance'] ?? '100' );
        $charge_strength = esc_attr( $meta['format_colors']['charge_strength'] ?? '-300' );
        return sprintf(
            '<div class="viswiz-graph" %s data-manual="%s" data-node-radius="%s" data-link-distance="%s" data-charge-strength="%s"></div>',
            $data_attrs,
            $manual_json,
            $node_radius,
            $link_distance,
            $charge_strength
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
        $data[] = array(
            'id' => $post->ID,
            'title' => $post->post_title,
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

    printf(
        '<input type="text" class="viswiz-shortcode-field" readonly value="%s" onclick="this.select();" />',
        esc_attr( viswiz_get_visualization_shortcode( $post_id ) )
    );
}

function viswiz_enqueue_admin_assets( $hook ) {
    $screen = get_current_screen();
    if ( ! $screen ) {
        return;
    }

    $is_viswiz_settings = $screen->id === 'toplevel_page_viswiz-settings';
    $is_viswiz_post = $screen->post_type === 'viswiz_visualization';

    if ( ! $is_viswiz_settings && ! $is_viswiz_post ) {
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

    wp_enqueue_script(
        'viswiz-admin',
        plugins_url( 'assets/viswiz-admin.js', __FILE__ ),
        array( 'jquery', 'wp-editor' ),
        VISWIZ_VERSION,
        true
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
    } elseif ( in_array( $type, array( 'pie', 'bar', 'column', 'line', 'area', 'scatter', 'counter', 'timeline', 'map' ), true ) ) {
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
