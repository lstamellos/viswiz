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

add_action( 'init', 'viswiz_register_shortcodes' );
add_action( 'rest_api_init', 'viswiz_register_rest_routes' );
add_action( 'wp_enqueue_scripts', 'viswiz_enqueue_assets' );
add_action( 'admin_menu', 'viswiz_register_admin_menu' );
add_action( 'admin_init', 'viswiz_register_settings' );

function viswiz_register_shortcodes() {
    add_shortcode( 'viswiz_progress', 'viswiz_progress_shortcode' );
    add_shortcode( 'viswiz_pie', 'viswiz_pie_shortcode' );
    add_shortcode( 'viswiz_diagram', 'viswiz_diagram_shortcode' );
    add_shortcode( 'viswiz_graph', 'viswiz_graph_shortcode' );
}

function viswiz_enqueue_assets() {
    wp_register_style(
        'viswiz-style',
        plugins_url( 'assets/viswiz.css', __FILE__ ),
        array(),
        VISWIZ_VERSION
    );
    wp_register_script(
        'viswiz-script',
        plugins_url( 'assets/viswiz.js', __FILE__ ),
        array(),
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
        '/sales-status',
        array(
            'methods' => 'GET',
            'callback' => 'viswiz_get_sales_status_data',
            'permission_callback' => '__return_true',
        )
    );
}

function viswiz_get_sales_data() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'viswiz_no_woocommerce', 'WooCommerce is not active.' );
    }

    $orders = wc_get_orders(
        array(
            'status' => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
            'limit' => -1,
            'date_created' => '>' . ( new DateTime( '-30 days' ) )->format( 'Y-m-d' ),
            'return' => 'ids',
        )
    );

    $total_sales = 0.0;
    foreach ( $orders as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $total_sales += (float) $order->get_total();
        }
    }

    return array(
        'totalSales' => $total_sales,
        'orderCount' => count( $orders ),
    );
}

function viswiz_get_sales_status_data() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return new WP_Error( 'viswiz_no_woocommerce', 'WooCommerce is not active.' );
    }

    $statuses = wc_get_order_statuses();
    $counts = array();

    foreach ( $statuses as $status_key => $label ) {
        $orders = wc_get_orders(
            array(
                'status' => array( $status_key ),
                'limit' => -1,
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
        ),
        $atts,
        'viswiz_progress'
    );

    $target = $atts['target'] !== '' ? (float) $atts['target'] : (float) get_option( VISWIZ_OPTION_TARGET, 0 );

    return sprintf(
        '<div class="viswiz-progress" data-type="%s" data-label="%s" data-target="%s"></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['label'] ),
        esc_attr( $target )
    );
}

function viswiz_pie_shortcode( $atts ) {
    $atts = shortcode_atts(
        array(
            'type' => 'auto',
            'title' => 'Sales Breakdown',
        ),
        $atts,
        'viswiz_pie'
    );

    return sprintf(
        '<div class="viswiz-pie" data-type="%s" data-title="%s"></div>',
        esc_attr( $atts['type'] ),
        esc_attr( $atts['title'] )
    );
}

function viswiz_diagram_shortcode() {
    return '<div class="viswiz-diagram"></div>';
}

function viswiz_graph_shortcode() {
    return '<div class="viswiz-graph"></div>';
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
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PROGRESS_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_json_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PIE_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_json_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_DIAGRAM, array( 'sanitize_callback' => 'viswiz_sanitize_json_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_GRAPH, array( 'sanitize_callback' => 'viswiz_sanitize_json_option' ) );
}

function viswiz_render_settings_page() {
    ?>
    <div class="wrap">
        <h1>VisWiz Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'viswiz_settings' ); ?>
            <table class="form-table" role="presentation">
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
                        <textarea name="viswiz_manual_progress" id="viswiz_manual_progress" rows="6" class="large-text code"><?php echo esc_textarea( get_option( VISWIZ_OPTION_PROGRESS_MANUAL, '[]' ) ); ?></textarea>
                        <p class="description">JSON array. Example: [{"label":"Campaign","value":45,"target":100}]</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_manual_pie">Manual Pie Data</label></th>
                    <td>
                        <textarea name="viswiz_manual_pie" id="viswiz_manual_pie" rows="6" class="large-text code"><?php echo esc_textarea( get_option( VISWIZ_OPTION_PIE_MANUAL, '[]' ) ); ?></textarea>
                        <p class="description">JSON array. Example: [{"label":"Retail","value":30,"color":"#4caf50"}]</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_diagram_data">Diagram Data</label></th>
                    <td>
                        <textarea name="viswiz_diagram_data" id="viswiz_diagram_data" rows="6" class="large-text code"><?php echo esc_textarea( get_option( VISWIZ_OPTION_DIAGRAM, '[]' ) ); ?></textarea>
                        <p class="description">JSON array. Example: [{"title":"Stage","items":["Idea","Build","Launch"]}]</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="viswiz_graph_data">Graph Data</label></th>
                    <td>
                        <textarea name="viswiz_graph_data" id="viswiz_graph_data" rows="6" class="large-text code"><?php echo esc_textarea( get_option( VISWIZ_OPTION_GRAPH, '[]' ) ); ?></textarea>
                        <p class="description">JSON array. Example: {"nodes":[{"id":"post-1","label":"Post"}],"links":[{"from":"post-1","to":"product-1","label":"references"}]}</p>
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

    return wp_json_encode( $decoded );
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
