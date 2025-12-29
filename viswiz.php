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
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PROGRESS_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_progress_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_PIE_MANUAL, array( 'sanitize_callback' => 'viswiz_sanitize_pie_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_DIAGRAM, array( 'sanitize_callback' => 'viswiz_sanitize_diagram_option' ) );
    register_setting( 'viswiz_settings', VISWIZ_OPTION_GRAPH, array( 'sanitize_callback' => 'viswiz_sanitize_graph_option' ) );
}

function viswiz_render_settings_page() {
    $progress_items = viswiz_get_manual_progress();
    $pie_items = viswiz_get_manual_pie();
    $diagram_sections = viswiz_get_diagram_data();
    $graph_data = viswiz_get_graph_data();
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
                                    <input type="text" name="viswiz_manual_pie[color][]" placeholder="#4caf50" value="<?php echo esc_attr( $pie_item['color'] ?? '' ); ?>" class="regular-text" />
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
    <script>
      (function() {
        const addHandlers = {
          progress() {
            addRow('viswiz-progress-rows', ['Label', 'Value', 'Target'], 'viswiz_manual_progress', ['label', 'value', 'target']);
          },
          pie() {
            addRow('viswiz-pie-rows', ['Label', 'Value', '#4caf50'], 'viswiz_manual_pie', ['label', 'value', 'color']);
          },
          diagram() {
            const container = document.getElementById('viswiz-diagram-sections');
            const index = container.children.length;
            const section = document.createElement('div');
            section.className = 'viswiz-section';
            section.dataset.sectionIndex = index;
            section.innerHTML = `
              <input type="text" name="viswiz_diagram_data[title][]" placeholder="Section Title" class="regular-text" />
              <div class="viswiz-items">
                <div class="viswiz-item-row">
                  <input type="text" name="viswiz_diagram_data[items][${index}][]" placeholder="Item" class="regular-text" />
                  <button type="button" class="button viswiz-remove-item">Remove</button>
                </div>
              </div>
              <button type="button" class="button viswiz-add-item">Add Item</button>
              <button type="button" class="button viswiz-remove-section">Remove Section</button>
            `;
            container.appendChild(section);
          },
          'graph-node': function () {
            addRow('viswiz-graph-nodes', ['Node ID', 'Label'], 'viswiz_graph_data[nodes]', ['id', 'label']);
          },
          'graph-link': function () {
            addRow('viswiz-graph-links', ['From ID', 'To ID', 'Label'], 'viswiz_graph_data[links]', ['from', 'to', 'label']);
          },
        };

        function addRow(containerId, placeholders, namePrefix, keys) {
          const container = document.getElementById(containerId);
          const row = document.createElement('div');
          row.className = 'viswiz-row';
          const inputs = keys.map((key, index) => {
            const input = document.createElement('input');
            input.type = 'text';
            input.name = `${namePrefix}[${key}][]`;
            input.placeholder = placeholders[index];
            input.className = 'regular-text';
            return input;
          });
          if (inputs.some((input) => input.placeholder === 'Value' || input.placeholder === 'Target')) {
            inputs.forEach((input) => {
              if (input.placeholder === 'Value' || input.placeholder === 'Target') {
                input.type = 'number';
                input.step = '0.01';
              }
            });
          }
          inputs.forEach((input) => row.appendChild(input));
          const remove = document.createElement('button');
          remove.type = 'button';
          remove.className = 'button viswiz-remove-row';
          remove.textContent = 'Remove';
          row.appendChild(remove);
          container.appendChild(row);
        }

        document.querySelectorAll('[data-viswiz-add]').forEach((button) => {
          button.addEventListener('click', () => {
            const key = button.getAttribute('data-viswiz-add');
            if (addHandlers[key]) {
              addHandlers[key]();
            }
          });
        });

        document.addEventListener('click', (event) => {
          if (event.target.classList.contains('viswiz-remove-row')) {
            event.target.closest('.viswiz-row').remove();
          }
          if (event.target.classList.contains('viswiz-remove-section')) {
            event.target.closest('.viswiz-section').remove();
          }
          if (event.target.classList.contains('viswiz-add-item')) {
            const section = event.target.closest('.viswiz-section');
            const index = section.dataset.sectionIndex;
            const items = section.querySelector('.viswiz-items');
            const row = document.createElement('div');
            row.className = 'viswiz-item-row';
            row.innerHTML = `
              <input type="text" name="viswiz_diagram_data[items][${index}][]" placeholder="Item" class="regular-text" />
              <button type="button" class="button viswiz-remove-item">Remove</button>
            `;
            items.appendChild(row);
          }
          if (event.target.classList.contains('viswiz-remove-item')) {
            event.target.closest('.viswiz-item-row').remove();
          }
        });
      })();
    </script>
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

    return wp_json_encode( $sanitized );
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

    return wp_json_encode( $sanitized );
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

    return wp_json_encode( $sanitized );
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

    return wp_json_encode(
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
