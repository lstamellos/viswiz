<?php
/**
 * Plugin Name: VisWiz WooCommerce Visualizer
 * Description: Real-time progress bars, pie charts, diagrams, and graphs based on WooCommerce sales or manual inputs.
 * Version: 1.0.0
 * Author: VisWiz
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const VISWIZ_VERSION = '1.0.0';
const VISWIZ_OPTION_TARGET = 'viswiz_sales_target';
const VISWIZ_OPTION_PROGRESS_MANUAL = 'viswiz_manual_progress';
const VISWIZ_OPTION_PIE_MANUAL = 'viswiz_manual_pie';
const VISWIZ_OPTION_DIAGRAM = 'viswiz_diagram_data';
const VISWIZ_OPTION_GRAPH = 'viswiz_graph_data';
const VISWIZ_OPTION_SALES_SCOPE = 'viswiz_sales_scope';
const VISWIZ_OPTION_SALES_PERIOD = 'viswiz_sales_period_days';
const VISWIZ_OPTION_SALES_PRODUCT = 'viswiz_sales_product_id';
const VISWIZ_OPTION_SALES_CATEGORY = 'viswiz_sales_category_id';

add_action( 'init', 'viswiz_register_shortcodes' );
add_action( 'rest_api_init', 'viswiz_register_rest_routes' );
add_action( 'wp_enqueue_scripts', 'viswiz_enqueue_assets' );
add_action( 'admin_menu', 'viswiz_register_admin_menu' );
add_action( 'admin_init', 'viswiz_register_settings' );
add_action( 'init', 'viswiz_register_visualizations_cpt' );
add_action( 'init', 'viswiz_register_block_assets' );
add_action( 'add_meta_boxes', 'viswiz_register_visualization_meta_box' );
add_action( 'save_post_viswiz_visualization', 'viswiz_save_visualization_meta' );
add_action( 'admin_enqueue_scripts', 'viswiz_enqueue_admin_assets' );

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
            'graphData' => viswiz_get_graph_data(),
            'salesScope' => get_option( VISWIZ_OPTION_SALES_SCOPE, 'total' ),
            'salesPeriod' => (int) get_option( VISWIZ_OPTION_SALES_PERIOD, 30 ),
            'salesProduct' => (int) get_option( VISWIZ_OPTION_SALES_PRODUCT, 0 ),
            'salesCategory' => (int) get_option( VISWIZ_OPTION_SALES_CATEGORY, 0 ),
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
            'permission_callback' => '__return_true',
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

    $period_days = (int) $request->get_param( 'period_days' );
    if ( $period_days <= 0 ) {
        $period_days = (int) get_option( VISWIZ_OPTION_SALES_PERIOD, 30 );
    }

    $product_id = (int) $request->get_param( 'product_id' );
    if ( $product_id <= 0 ) {
        $product_id = (int) get_option( VISWIZ_OPTION_SALES_PRODUCT, 0 );
    }

    $category_id = (int) $request->get_param( 'category_id' );
    if ( $category_id <= 0 ) {
        $category_id = (int) get_option( VISWIZ_OPTION_SALES_CATEGORY, 0 );
    }

    $orders = viswiz_get_orders_for_period( $period_days );
    $total_sales = 0.0;
    $order_count = 0;

    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            continue;
        }

        if ( $scope === 'product' && $product_id > 0 ) {
            $total_sales += viswiz_get_order_total_for_product( $order, $product_id );
            $order_count++;
            continue;
        }

        if ( $scope === 'category' && $category_id > 0 ) {
            $total_sales += viswiz_get_order_total_for_category( $order, $category_id );
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

    $period_days = (int) $request->get_param( 'period_days' );
    if ( $period_days <= 0 ) {
        $period_days = (int) get_option( VISWIZ_OPTION_SALES_PERIOD, 30 );
    }

    $statuses = wc_get_order_statuses();
    $counts = array();

    foreach ( $statuses as $status_key => $label ) {
        $orders = wc_get_orders(
            array(
                'status' => array( $status_key ),
                'limit' => -1,
                'date_created' => '>' . ( new DateTime( sprintf( '-%d days', $period_days ) ) )->format( 'Y-m-d' ),
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
            'period_days' => '',
            'product_id' => '',
            'category_id' => '',
        ),
        $atts,
        'viswiz_progress'
    );

    $target = $atts['target'] !== '' ? (float) $atts['target'] : (float) get_option( VISWIZ_OPTION_TARGET, 0 );

    return sprintf(
        '<div class="viswiz-progress" data-type="%s" data-label="%s" data-target="%s" data-scope="%s" data-period-days="%s" data-product-id="%s" data-category-id="%s"></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['label'] ),
        esc_attr( $target ),
        esc_attr( $atts['scope'] ),
        esc_attr( $atts['period_days'] ),
        esc_attr( $atts['product_id'] ),
        esc_attr( $atts['category_id'] )
    );
}

function viswiz_pie_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'type' => 'auto',
            'title' => 'Sales Breakdown',
            'scope' => '',
            'period_days' => '',
            'product_id' => '',
            'category_id' => '',
        ),
        $atts,
        'viswiz_pie'
    );

    return sprintf(
        '<div class="viswiz-pie" data-type="%s" data-title="%s" data-scope="%s" data-period-days="%s" data-product-id="%s" data-category-id="%s"></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['title'] ),
        esc_attr( $atts['scope'] ),
        esc_attr( $atts['period_days'] ),
        esc_attr( $atts['product_id'] ),
        esc_attr( $atts['category_id'] )
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
}

function viswiz_register_settings() {
    register_setting( 'viswiz_settings', VISWIZ_OPTION_TARGET, array( 'type' => 'number', 'sanitize_callback' => 'floatval' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PROGRESS_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_progress_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PIE_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_pie_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_DIAGRAM, array( 'sanitize_callback' => 'viswiz_sanitize_diagram_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_GRAPH, array( 'sanitize_callback' => 'viswiz_sanitize_graph_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_SCOPE, array( 'sanitize_callback' => 'sanitize_text_field' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_PERIOD, array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_PRODUCT, array( 'sanitize_callback' => 'absint' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_SALES_CATEGORY, array( 'sanitize_callback' => 'absint' ) );
}

function viswiz_render_settings_page() {
    $progress_items = viswiz_get_manual_progress();
    $pie_items = viswiz_get_manual_pie();
    $diagram_sections = viswiz_get_diagram_data();
    $graph_data = viswiz_get_graph_data();
    $sales_scope = get_option( VISWIZ_OPTION_SALES_SCOPE, 'total' );
    $sales_period = (int) get_option( VISWIZ_OPTION_SALES_PERIOD, 30 );
    $sales_product = (int) get_option( VISWIZ_OPTION_SALES_PRODUCT, 0 );
    $sales_category = (int) get_option( VISWIZ_OPTION_SALES_CATEGORY, 0 );
    ?>
    <div class="wrap">
        <h1>VisWiz Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'viswiz_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="viswiz_sales_scope">Default Sales Scope</label></th>
                    <td>
                        <select name="viswiz_sales_scope" id="viswiz_sales_scope">
                            <option value="total" <?php selected( $sales_scope, 'total' ); ?>>All sales (total)</option>
                            <option value="product" <?php selected( $sales_scope, 'product' ); ?>>Specific product</option>
                            <option value="category" <?php selected( $sales_scope, 'category' ); ?>>Specific category</option>
                        </select>
                        <p class="description">Used for automatic charts when shortcode overrides are not set.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_sales_period_days">Default Sales Period (days)</label></th>
                    <td>
                        <select name="viswiz_sales_period_days" id="viswiz_sales_period_days">
                            <option value="7" <?php selected( $sales_period, 7 ); ?>>Last 7 days</option>
                            <option value="30" <?php selected( $sales_period, 30 ); ?>>Last 30 days</option>
                            <option value="90" <?php selected( $sales_period, 90 ); ?>>Last 90 days</option>
                        </select>
                        <p class="description">Used for WooCommerce data queries.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_sales_product_id">Default Product ID</label></th>
                    <td>
                        <?php echo viswiz_render_product_search_field( 'viswiz_sales_product_id', $sales_product ); ?>
                        <p class="description">Search for a WooCommerce product to set default charts.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_sales_category_id">Default Category ID</label></th>
                    <td>
                        <?php echo viswiz_render_category_select_field( 'viswiz_sales_category_id', $sales_category ); ?>
                        <p class="description">Search for a WooCommerce category to set default charts.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_sales_target">Sales Target</label></th>
                    <td>
                        <input type="number" name="viswiz_sales_target" id="viswiz_sales_target" value="<?php echo esc_attr( get_option( VISWIZ_OPTION_TARGET, 0 ) ); ?>" step="0.01" class="regular-text" />
                        <p class="description">Used for automatic progress bars when no shortcode target is provided.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_manual_progress">Manual Progress Data</label></th>
                    <td>
                        <div id="viswiz-progress-rows" class="viswiz-repeatable">
                            <?php if ( empty( $progress_items ) ) : ?>
                                <?php $progress_items = array( array( 'label' => '', 'value' => '', 'target' => '' ) ); ?>
                            <?php endif; ?>
                            <?php foreach ( $progress_items as $progress_item ) : ?>
                                <div class="viswiz-row">
                                    <input type="text" name="viswiz_manual_progress[label][]" placeholder="Label" value="<?php echo esc_attr( $progress_item['label'] ?? '' ); ?>" class="regular-text" />
                                    <input type="number" name="viswiz_manual_progress[value][]" placeholder="Value" value="<?php echo esc_attr( $progress_item['value'] ?? '' ); ?>" step="0.01" />
                                    <input type="number" name="viswiz_manual_progress[target][]" placeholder="Target" value="<?php echo esc_attr( $progress_item['target'] ?? '' ); ?>" step="0.01" />
                                    <button type="button" class="button viswiz-remove-row">Remove</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="button" data-viswiz-add="progress">Add Progress Row</button>
                        <p class="description">Each row becomes a progress bar in manual mode.</p>
                    </td>
                </tr>
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
                        <p class="description">Each row becomes a slice in manual mode.</p>
                    </td>
                </tr>
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
                        <p class="description">Each section holds a list of items for the diagram.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_graph_data">Graph Data</label></th>
                    <td>
                        <div class="viswiz-graph">
                            <h4>Nodes</h4>
                            <div id="viswiz-graph-nodes" class="viswiz-repeatable">
                                <?php $nodes = $graph_data['nodes'] ?? array(); ?>
                                <?php if ( empty( $nodes ) ) : ?>
                                    <?php $nodes = array( array( 'id' => '', 'label' => '' ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $nodes as $node ) : ?>
                                    <div class="viswiz-row">
                                        <input type="text" name="viswiz_graph_data[nodes][id][]" placeholder="Node ID" value="<?php echo esc_attr( $node['id'] ?? '' ); ?>" class="regular-text" />
                                        <input type="text" name="viswiz_graph_data[nodes][label][]" placeholder="Label" value="<?php echo esc_attr( $node['label'] ?? '' ); ?>" class="regular-text" />
                                        <button type="button" class="button viswiz-remove-row">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="graph-node">Add Node</button>
                            <h4>Links</h4>
                            <div id="viswiz-graph-links" class="viswiz-repeatable">
                                <?php $links = $graph_data['links'] ?? array(); ?>
                                <?php if ( empty( $links ) ) : ?>
                                    <?php $links = array( array( 'from' => '', 'to' => '', 'label' => '' ) ); ?>
                                <?php endif; ?>
                                <?php foreach ( $links as $link ) : ?>
                                    <div class="viswiz-row">
                                        <input type="text" name="viswiz_graph_data[links][from][]" placeholder="From ID" value="<?php echo esc_attr( $link['from'] ?? '' ); ?>" class="regular-text" />
                                        <input type="text" name="viswiz_graph_data[links][to][]" placeholder="To ID" value="<?php echo esc_attr( $link['to'] ?? '' ); ?>" class="regular-text" />
                                        <input type="text" name="viswiz_graph_data[links][label][]" placeholder="Label" value="<?php echo esc_attr( $link['label'] ?? '' ); ?>" class="regular-text" />
                                        <button type="button" class="button viswiz-remove-row">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" data-viswiz-add="graph-link">Add Link</button>
                        </div>
                        <p class="description">Nodes map to IDs and labels, links connect nodes.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
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

function viswiz_sanitize_progress_option( $value ) {
    if ( ! is_array( $value ) ) {
        return viswiz_sanitize_json_option( $value );
    }

    $labels = $value['label'] ?? array();
    $values = $value['value'] ?? array();
    $targets = $value['target'] ?? array();
    $sanitized = array();

    foreach ( $labels as $index => $label ) {
        $label = sanitize_text_field( $label );
        $val = isset( $values[ $index ] ) ? (float) $values[ $index ] : 0;
        $target = isset( $targets[ $index ] ) ? (float) $targets[ $index ] : 0;
        if ( $label === '' && $val === 0.0 && $target === 0.0 ) {
            continue;
        }
        $sanitized[] = array(
            'label' => $label,
            'value' => $val,
            'target' => $target,
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
    foreach ( $node_ids as $index => $node_id ) {
        $node_id = sanitize_text_field( $node_id );
        $label = sanitize_text_field( $node_labels[ $index ] ?? '' );
        if ( $node_id === '' && $label === '' ) {
            continue;
        }
        $sanitized_nodes[] = array(
            'id' => $node_id,
            'label' => $label,
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
        $sanitized_links[] = array(
            'from' => $from,
            'to' => $to,
            'label' => $label,
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

function viswiz_get_orders_for_period( $period_days ) {
    $period_days = max( 1, (int) $period_days );
    return wc_get_orders(
        array(
            'status' => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
            'limit' => -1,
            'date_created' => '>' . ( new DateTime( sprintf( '-%d days', $period_days ) ) )->format( 'Y-m-d' ),
            'return' => 'ids',
        )
    );
}

function viswiz_get_order_total_for_product( WC_Order $order, $product_id ) {
    $total = 0.0;
    foreach ( $order->get_items() as $item ) {
        if ( (int) $item->get_product_id() === (int) $product_id ) {
            $total += (float) $item->get_total();
        }
    }
    return $total;
}

function viswiz_get_order_total_for_category( WC_Order $order, $category_id ) {
    $total = 0.0;
    foreach ( $order->get_items() as $item ) {
        $product = $item->get_product();
        if ( ! $product ) {
            continue;
        }
        $categories = $product->get_category_ids();
        if ( in_array( (int) $category_id, $categories, true ) ) {
            $total += (float) $item->get_total();
        }
    }
    return $total;
}

function viswiz_get_sales_breakdown( WP_REST_Request $request ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'viswiz_no_woocommerce', 'WooCommerce is not active.' );
    }

    $scope = sanitize_text_field( $request->get_param( 'scope' ) );
    if ( $scope === '' ) {
        $scope = get_option( VISWIZ_OPTION_SALES_SCOPE, 'total' );
    }

    $period_days = (int) $request->get_param( 'period_days' );
    if ( $period_days <= 0 ) {
        $period_days = (int) get_option( VISWIZ_OPTION_SALES_PERIOD, 30 );
    }

    $orders = viswiz_get_orders_for_period( $period_days );
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

            if ( $scope === 'category' ) {
                foreach ( $product->get_category_ids() as $category_id ) {
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
    <p>
        <label for="viswiz_type">Visualization Type</label>
        <select name="viswiz_meta[type]" id="viswiz_type">
            <option value="progress" <?php selected( $meta['type'], 'progress' ); ?>>Progress</option>
            <option value="pie" <?php selected( $meta['type'], 'pie' ); ?>>Pie</option>
            <option value="diagram" <?php selected( $meta['type'], 'diagram' ); ?>>Diagram</option>
            <option value="graph" <?php selected( $meta['type'], 'graph' ); ?>>Graph</option>
        </select>
    </p>
    <p>
        <label for="viswiz_source">Data Source</label>
        <select name="viswiz_meta[source]" id="viswiz_source">
            <option value="auto" <?php selected( $meta['source'], 'auto' ); ?>>WooCommerce</option>
            <option value="manual" <?php selected( $meta['source'], 'manual' ); ?>>Manual</option>
        </select>
    </p>
    <p>
        <label for="viswiz_label">Label/Title</label>
        <input type="text" name="viswiz_meta[label]" id="viswiz_label" value="<?php echo esc_attr( $meta['label'] ); ?>" class="regular-text" />
    </p>
    <p>
        <label for="viswiz_target">Target (progress)</label>
        <input type="number" name="viswiz_meta[target]" id="viswiz_target" value="<?php echo esc_attr( $meta['target'] ); ?>" step="0.01" />
    </p>
    <p>
        <label for="viswiz_scope">Sales Scope</label>
        <select name="viswiz_meta[scope]" id="viswiz_scope">
            <option value="total" <?php selected( $meta['scope'], 'total' ); ?>>All sales</option>
            <option value="product" <?php selected( $meta['scope'], 'product' ); ?>>By product</option>
            <option value="category" <?php selected( $meta['scope'], 'category' ); ?>>By category</option>
        </select>
    </p>
    <p>
        <label for="viswiz_period_days">Period (days)</label>
        <select name="viswiz_meta[period_days]" id="viswiz_period_days">
            <option value="7" <?php selected( $meta['period_days'], 7 ); ?>>Last 7 days</option>
            <option value="30" <?php selected( $meta['period_days'], 30 ); ?>>Last 30 days</option>
            <option value="90" <?php selected( $meta['period_days'], 90 ); ?>>Last 90 days</option>
        </select>
    </p>
    <p>
        <label for="viswiz_product_id">Product</label>
        <?php echo viswiz_render_product_search_field( 'viswiz_meta[product_id]', $meta['product_id'] ); ?>
    </p>
    <p>
        <label for="viswiz_category_id">Category</label>
        <?php echo viswiz_render_category_select_field( 'viswiz_meta[category_id]', $meta['category_id'], 'viswiz_category_id' ); ?>
    </p>
    <h4>Manual Progress</h4>
    <div id="viswiz-visual-progress" class="viswiz-repeatable">
        <?php if ( empty( $manual_progress ) ) : ?>
            <?php $manual_progress = array( array( 'label' => '', 'value' => '', 'target' => '' ) ); ?>
        <?php endif; ?>
        <?php foreach ( $manual_progress as $progress_item ) : ?>
            <div class="viswiz-row">
                <input type="text" name="viswiz_meta[manual_progress][label][]" placeholder="Label" value="<?php echo esc_attr( $progress_item['label'] ?? '' ); ?>" class="regular-text" />
                <input type="number" name="viswiz_meta[manual_progress][value][]" placeholder="Value" value="<?php echo esc_attr( $progress_item['value'] ?? '' ); ?>" step="0.01" />
                <input type="number" name="viswiz_meta[manual_progress][target][]" placeholder="Target" value="<?php echo esc_attr( $progress_item['target'] ?? '' ); ?>" step="0.01" />
                <button type="button" class="button viswiz-remove-row">Remove</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="button" data-viswiz-add="visual-progress">Add Progress Row</button>
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
    <h4>Manual Graph</h4>
    <div class="viswiz-graph">
        <h5>Nodes</h5>
        <div id="viswiz-visual-graph-nodes" class="viswiz-repeatable">
            <?php $nodes = $graph_data['nodes'] ?? array(); ?>
            <?php if ( empty( $nodes ) ) : ?>
                <?php $nodes = array( array( 'id' => '', 'label' => '' ) ); ?>
            <?php endif; ?>
            <?php foreach ( $nodes as $node ) : ?>
                <div class="viswiz-row">
                    <input type="text" name="viswiz_meta[graph_data][nodes][id][]" placeholder="Node ID" value="<?php echo esc_attr( $node['id'] ?? '' ); ?>" class="regular-text" />
                    <input type="text" name="viswiz_meta[graph_data][nodes][label][]" placeholder="Label" value="<?php echo esc_attr( $node['label'] ?? '' ); ?>" class="regular-text" />
                    <button type="button" class="button viswiz-remove-row">Remove</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button" data-viswiz-add="visual-graph-node">Add Node</button>
        <h5>Links</h5>
        <div id="viswiz-visual-graph-links" class="viswiz-repeatable">
            <?php $links = $graph_data['links'] ?? array(); ?>
            <?php if ( empty( $links ) ) : ?>
                <?php $links = array( array( 'from' => '', 'to' => '', 'label' => '' ) ); ?>
            <?php endif; ?>
            <?php foreach ( $links as $link ) : ?>
                <div class="viswiz-row">
                    <input type="text" name="viswiz_meta[graph_data][links][from][]" placeholder="From ID" value="<?php echo esc_attr( $link['from'] ?? '' ); ?>" class="regular-text" />
                    <input type="text" name="viswiz_meta[graph_data][links][to][]" placeholder="To ID" value="<?php echo esc_attr( $link['to'] ?? '' ); ?>" class="regular-text" />
                    <input type="text" name="viswiz_meta[graph_data][links][label][]" placeholder="Label" value="<?php echo esc_attr( $link['label'] ?? '' ); ?>" class="regular-text" />
                    <button type="button" class="button viswiz-remove-row">Remove</button>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button" data-viswiz-add="visual-graph-link">Add Link</button>
    </div>
    <?php
}

function viswiz_save_visualization_meta( $post_id ) {
    if ( ! isset( $_POST['viswiz_visualization_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['viswiz_visualization_nonce'] ), 'viswiz_visualization_save' ) ) {
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
    $period_days = isset( $meta['period_days'] ) ? (int) $meta['period_days'] : 30;
    $product_id = isset( $meta['product_id'] ) ? (int) $meta['product_id'] : 0;
    $category_id = isset( $meta['category_id'] ) ? (int) $meta['category_id'] : 0;

    update_post_meta( $post_id, 'viswiz_type', $type );
    update_post_meta( $post_id, 'viswiz_source', $source );
    update_post_meta( $post_id, 'viswiz_label', $label );
    update_post_meta( $post_id, 'viswiz_target', $target );
    update_post_meta( $post_id, 'viswiz_scope', $scope );
    update_post_meta( $post_id, 'viswiz_period_days', $period_days );
    update_post_meta( $post_id, 'viswiz_product_id', $product_id );
    update_post_meta( $post_id, 'viswiz_category_id', $category_id );

    $progress_json = viswiz_sanitize_progress_option( $meta['manual_progress'] ?? array() );
    $pie_json = viswiz_sanitize_pie_option( $meta['manual_pie'] ?? array() );
    $diagram_json = viswiz_sanitize_diagram_option( $meta['diagram_data'] ?? array() );
    $graph_json = viswiz_sanitize_graph_option( $meta['graph_data'] ?? array() );

    update_post_meta( $post_id, 'viswiz_manual_progress', $progress_json );
    update_post_meta( $post_id, 'viswiz_manual_pie', $pie_json );
    update_post_meta( $post_id, 'viswiz_diagram_data', $diagram_json );
    update_post_meta( $post_id, 'viswiz_graph_data', $graph_json );
}

function viswiz_get_visualization_meta( $post_id ) {
    return array(
        'type' => get_post_meta( $post_id, 'viswiz_type', true ) ?: 'progress',
        'source' => get_post_meta( $post_id, 'viswiz_source', true ) ?: 'auto',
        'label' => get_post_meta( $post_id, 'viswiz_label', true ) ?: '',
        'target' => (float) get_post_meta( $post_id, 'viswiz_target', true ),
        'scope' => get_post_meta( $post_id, 'viswiz_scope', true ) ?: 'total',
        'period_days' => (int) get_post_meta( $post_id, 'viswiz_period_days', true ) ?: 30,
        'product_id' => (int) get_post_meta( $post_id, 'viswiz_product_id', true ),
        'category_id' => (int) get_post_meta( $post_id, 'viswiz_category_id', true ),
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

function viswiz_render_visualization( $post_id ) {
    $meta = viswiz_get_visualization_meta( $post_id );
    $data_attrs = sprintf(
        'data-type="%s" data-label="%s" data-title="%s" data-target="%s" data-scope="%s" data-period-days="%s" data-product-id="%s" data-category-id="%s"',
        esc_attr( $meta['source'] ),
        esc_attr( $meta['label'] ),
        esc_attr( $meta['label'] ),
        esc_attr( $meta['target'] ),
        esc_attr( $meta['scope'] ),
        esc_attr( $meta['period_days'] ),
        esc_attr( $meta['product_id'] ),
        esc_attr( $meta['category_id'] )
    );

    if ( $meta['type'] === 'progress' ) {
        $manual = $meta['manual_progress'][0] ?? array();
        $manual_json = $meta['source'] === 'manual' ? esc_attr( viswiz_json_encode( $manual ) ) : '';
        return sprintf( '<div class="viswiz-progress" %s data-manual="%s"></div>', $data_attrs, $manual_json );
    }

    if ( $meta['type'] === 'pie' ) {
        $manual_json = $meta['source'] === 'manual' ? esc_attr( viswiz_json_encode( $meta['manual_pie'] ) ) : '';
        return sprintf( '<div class="viswiz-pie" %s data-manual="%s"></div>', $data_attrs, $manual_json );
    }

    if ( $meta['type'] === 'diagram' ) {
        $manual_json = esc_attr( viswiz_json_encode( $meta['diagram_data'] ) );
        return sprintf( '<div class="viswiz-diagram" data-manual="%s"></div>', $manual_json );
    }

    if ( $meta['type'] === 'graph' ) {
        $manual_json = esc_attr( viswiz_json_encode( $meta['graph_data'] ) );
        return sprintf( '<div class="viswiz-graph" data-manual="%s"></div>', $manual_json );
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

    wp_enqueue_script(
        'viswiz-admin',
        plugins_url( 'assets/viswiz-admin.js', __FILE__ ),
        array( 'jquery' ),
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

function viswiz_render_product_search_field( $name, $selected_id ) {
    $selected_id = (int) $selected_id;
    $product_title = $selected_id ? get_the_title( $selected_id ) : '';
    $field_id = sanitize_html_class( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );

    $options = '';
    if ( $selected_id ) {
        $options = sprintf( '<option value="%d" selected="selected">%s</option>', $selected_id, esc_html( $product_title ) );
    }

    return sprintf(
        '<select id="%s" name="%s" class="wc-product-search" data-action="woocommerce_json_search_products_and_variations" data-placeholder="Search for a product" style="width: 300px;">%s</select>',
        esc_attr( $field_id ),
        esc_attr( $name ),
        $options
    );
}

function viswiz_render_category_select_field( $name, $selected_id, $field_id = '' ) {
    $field_id = $field_id ?: sanitize_html_class( str_replace( array( '[', ']' ), array( '-', '' ), $name ) );
    return wp_dropdown_categories(
        array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'name' => $name,
            'id' => $field_id,
            'selected' => $selected_id,
            'show_option_none' => 'Select category',
            'option_none_value' => 0,
            'class' => 'wc-enhanced-select',
            'echo' => false,
        )
    );
}
