<?php
/**
 * Commerce Data Builder for VisWiz.
 *
 * Builds snapshot datasets from WooCommerce CRUD APIs and optionally combines
 * them with user-entered values (for example annual subscription revenue vs
 * operating expenses).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', 'viswiz_commerce_builder_register_routes' );
add_action( 'add_meta_boxes_viswiz_visualization', 'viswiz_commerce_builder_add_meta_box' );
add_action( 'admin_enqueue_scripts', 'viswiz_commerce_builder_enqueue_assets' );

function viswiz_commerce_builder_register_routes() {
    register_rest_route(
        'viswiz/v1',
        '/commerce-builder',
        array(
            'methods'             => 'POST',
            'callback'            => 'viswiz_commerce_builder_rest_build',
            'permission_callback' => function () {
                return current_user_can( 'edit_posts' ) && current_user_can( 'manage_woocommerce' );
            },
        )
    );
}

function viswiz_commerce_builder_add_meta_box() {
    add_meta_box(
        'viswiz-commerce-builder',
        __( 'Commerce Data Builder', 'viswiz' ),
        'viswiz_commerce_builder_render_meta_box',
        'viswiz_visualization',
        'normal',
        'high'
    );
}

function viswiz_commerce_builder_enqueue_assets( $hook ) {
    global $post;

    if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $post || 'viswiz_visualization' !== $post->post_type ) {
        return;
    }

    if ( class_exists( 'WooCommerce' ) ) {
        wp_enqueue_script( 'selectWoo' );
        wp_enqueue_style( 'selectWoo' );
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_script( 'wc-product-search' );
        wp_enqueue_style( 'woocommerce_admin_styles' );
    }

    wp_enqueue_script(
        'viswiz-commerce-builder',
        plugins_url( '../assets/viswiz-commerce-builder.js', __FILE__ ),
        array( 'jquery' ),
        defined( 'VISWIZ_VERSION' ) ? VISWIZ_VERSION : '1.0.0',
        true
    );

    wp_localize_script(
        'viswiz-commerce-builder',
        'VisWizCommerceBuilder',
        array(
            'restUrl'        => esc_url_raw( rest_url( 'viswiz/v1/commerce-builder' ) ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'currencySymbol' => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '',
            'subscriptions'  => function_exists( 'wcs_is_subscription' ),
            'i18n'           => array(
                'building'    => __( 'Building data…', 'viswiz' ),
                'build'       => __( 'Build visualization data', 'viswiz' ),
                'noRows'      => __( 'No matching WooCommerce data was found for these filters.', 'viswiz' ),
                'success'     => __( 'The generated snapshot has been copied into the visualization data rows. Review it and save/update the visualization.', 'viswiz' ),
                'error'       => __( 'Could not build WooCommerce data.', 'viswiz' ),
                'unsupported' => __( 'Choose a chart-like visualization (Pie, Bar, Column, Line, Area, Scatter, Counter, Timeline or Map) before building data.', 'viswiz' ),
            ),
        )
    );
}

function viswiz_commerce_builder_render_meta_box( $post ) {
    if ( ! class_exists( 'WooCommerce' ) ) {
        echo '<p>' . esc_html__( 'WooCommerce is not active. The Commerce Data Builder requires WooCommerce.', 'viswiz' ) . '</p>';
        return;
    }

    $year                 = (int) wp_date( 'Y' );
    $subscriptions_active = function_exists( 'wcs_is_subscription' );
    ?>
    <div class="viswiz-commerce-builder" data-viswiz-commerce-builder>
        <p class="description">
            <?php esc_html_e( 'Create a snapshot from WooCommerce data, optionally add manual values such as expenses, and copy the result directly into the visualization rows. WooCommerce data is read through its CRUD/query APIs, keeping the builder compatible with HPOS.', 'viswiz' ); ?>
        </p>

        <table class="form-table" role="presentation">
            <tbody>
            <tr>
                <th scope="row"><label for="viswiz_cb_preset"><?php esc_html_e( 'Preset', 'viswiz' ); ?></label></th>
                <td>
                    <select id="viswiz_cb_preset" data-viswiz-cb-preset>
                        <option value="custom"><?php esc_html_e( 'Custom WooCommerce dataset', 'viswiz' ); ?></option>
                        <option value="annual_income_expenses"><?php esc_html_e( 'Annual income + manual expenses', 'viswiz' ); ?></option>
                        <?php if ( $subscriptions_active ) : ?>
                            <option value="annual_subscriptions_expenses"><?php esc_html_e( 'Annual subscription revenue + manual expenses', 'viswiz' ); ?></option>
                        <?php endif; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Presets only fill sensible defaults; every filter can still be changed before building the snapshot.', 'viswiz' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="viswiz_cb_year"><?php esc_html_e( 'Year', 'viswiz' ); ?></label></th>
                <td><input id="viswiz_cb_year" data-viswiz-cb-year type="number" min="2000" max="2100" value="<?php echo esc_attr( $year ); ?>" class="small-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label for="viswiz_cb_metric"><?php esc_html_e( 'Metric', 'viswiz' ); ?></label></th>
                <td>
                    <select id="viswiz_cb_metric" data-viswiz-cb-metric>
                        <option value="revenue"><?php esc_html_e( 'Revenue', 'viswiz' ); ?></option>
                        <option value="orders"><?php esc_html_e( 'Order count', 'viswiz' ); ?></option>
                        <option value="quantity"><?php esc_html_e( 'Items sold', 'viswiz' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="viswiz_cb_group_by"><?php esc_html_e( 'Group by', 'viswiz' ); ?></label></th>
                <td>
                    <select id="viswiz_cb_group_by" data-viswiz-cb-group>
                        <option value="total"><?php esc_html_e( 'Single total', 'viswiz' ); ?></option>
                        <option value="month"><?php esc_html_e( 'Month', 'viswiz' ); ?></option>
                        <option value="product"><?php esc_html_e( 'Product', 'viswiz' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'WooCommerce filters', 'viswiz' ); ?></th>
                <td>
                    <?php if ( $subscriptions_active ) : ?>
                        <p><label><input type="checkbox" value="1" data-viswiz-cb-subscriptions /> <strong><?php esc_html_e( 'Subscription products only', 'viswiz' ); ?></strong></label></p>
                    <?php endif; ?>
                    <p><strong><?php esc_html_e( 'Products', 'viswiz' ); ?></strong><br />
                        <span data-viswiz-cb-products><?php echo viswiz_render_product_search_field( 'viswiz_cb_product_ids[]', array(), true ); ?></span>
                    </p>
                    <p><strong><?php esc_html_e( 'Product categories', 'viswiz' ); ?></strong><br />
                        <span data-viswiz-cb-categories><?php echo viswiz_render_category_select_field( 'viswiz_cb_category_ids[]', array(), 'viswiz_cb_category_ids', true ); ?></span>
                    </p>
                    <p class="description"><?php esc_html_e( 'Leave filters empty to include all paid WooCommerce orders. Product/category filters are applied at line-item level. Category filters include child categories.', 'viswiz' ); ?></p>
                </td>
            </tr>
            </tbody>
        </table>

        <div class="viswiz-cb-manual">
            <h4><?php esc_html_e( 'Additional manual values', 'viswiz' ); ?></h4>
            <p class="description"><?php esc_html_e( 'Use positive or negative numbers. Example: label “Expenses”, value “-18500”. These rows are appended to the WooCommerce result.', 'viswiz' ); ?></p>
            <div data-viswiz-cb-manual-rows>
                <p class="viswiz-cb-manual-row">
                    <input type="text" data-viswiz-cb-manual-label class="regular-text" placeholder="<?php echo esc_attr__( 'Expenses', 'viswiz' ); ?>" />
                    <input type="number" data-viswiz-cb-manual-value step="0.01" placeholder="-18500" />
                    <button type="button" class="button" data-viswiz-cb-remove-manual><?php esc_html_e( 'Remove', 'viswiz' ); ?></button>
                </p>
            </div>
            <p><button type="button" class="button" data-viswiz-cb-add-manual><?php esc_html_e( 'Add manual value', 'viswiz' ); ?></button></p>
        </div>

        <p>
            <button type="button" class="button button-primary" data-viswiz-cb-build><?php esc_html_e( 'Build visualization data', 'viswiz' ); ?></button>
            <span class="spinner" data-viswiz-cb-spinner></span>
        </p>
        <div class="notice inline" data-viswiz-cb-status hidden><p></p></div>
    </div>
    <?php
}

function viswiz_commerce_builder_rest_build( WP_REST_Request $request ) {
    if ( ! function_exists( 'wc_get_orders' ) ) {
        return new WP_Error( 'viswiz_no_woocommerce', __( 'WooCommerce is not active.', 'viswiz' ), array( 'status' => 400 ) );
    }

    $year              = max( 2000, min( 2100, absint( $request->get_param( 'year' ) ) ) );
    $metric            = sanitize_key( $request->get_param( 'metric' ) ?: 'revenue' );
    $group_by          = sanitize_key( $request->get_param( 'group_by' ) ?: 'total' );
    $product_ids       = viswiz_commerce_builder_parse_ids( $request->get_param( 'product_ids' ) );
    $category_ids      = viswiz_commerce_builder_parse_ids( $request->get_param( 'category_ids' ) );
    $subscription_only = rest_sanitize_boolean( $request->get_param( 'subscription_only' ) );
    $manual_rows       = viswiz_commerce_builder_sanitize_manual_rows( $request->get_param( 'manual_rows' ) );

    if ( function_exists( 'viswiz_get_category_ids_with_children' ) && $category_ids ) {
        $category_ids = viswiz_get_category_ids_with_children( $category_ids );
    }

    if ( ! in_array( $metric, array( 'revenue', 'orders', 'quantity' ), true ) ) {
        $metric = 'revenue';
    }
    if ( ! in_array( $group_by, array( 'total', 'month', 'product' ), true ) ) {
        $group_by = 'total';
    }

    $timezone = wp_timezone();
    $start    = new DateTimeImmutable( sprintf( '%04d-01-01 00:00:00', $year ), $timezone );
    $end      = new DateTimeImmutable( sprintf( '%04d-12-31 23:59:59', $year ), $timezone );

    $order_ids      = viswiz_commerce_builder_get_order_ids( $start, $end );
    $series         = array();
    $matched_orders = array();

    foreach ( $order_ids as $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            continue;
        }

        $items = viswiz_commerce_builder_matching_items( $order, $product_ids, $category_ids, $subscription_only );
        $has_item_filter = $product_ids || $category_ids || $subscription_only;
        if ( $has_item_filter && empty( $items ) ) {
            continue;
        }

        $matched_orders[ $order->get_id() ] = true;

        if ( 'orders' === $metric ) {
            if ( 'product' === $group_by ) {
                $seen_products = array();
                foreach ( $items as $item ) {
                    $key = viswiz_commerce_builder_group_key( 'product', $order, $item );
                    if ( isset( $seen_products[ $key ] ) ) {
                        continue;
                    }
                    $seen_products[ $key ] = true;
                    $series[ $key ] = isset( $series[ $key ] ) ? $series[ $key ] + 1 : 1;
                }
            } else {
                $key = viswiz_commerce_builder_group_key( $group_by, $order, null );
                $series[ $key ] = isset( $series[ $key ] ) ? $series[ $key ] + 1 : 1;
            }
            continue;
        }

        if ( ! $has_item_filter && 'product' !== $group_by && 'revenue' === $metric ) {
            $key = viswiz_commerce_builder_group_key( $group_by, $order, null );
            $series[ $key ] = isset( $series[ $key ] ) ? $series[ $key ] + (float) $order->get_total() : (float) $order->get_total();
            continue;
        }

        foreach ( $items as $item ) {
            $key = viswiz_commerce_builder_group_key( $group_by, $order, $item );
            if ( ! isset( $series[ $key ] ) ) {
                $series[ $key ] = 0.0;
            }
            if ( 'quantity' === $metric ) {
                $series[ $key ] += (float) $item->get_quantity();
            } else {
                $series[ $key ] += (float) $item->get_total() + (float) $item->get_total_tax();
            }
        }
    }

    if ( 'month' === $group_by ) {
        $ordered = array();
        for ( $month = 1; $month <= 12; $month++ ) {
            $key = sprintf( '%04d-%02d', $year, $month );
            $ordered[ $key ] = isset( $series[ $key ] ) ? $series[ $key ] : 0.0;
        }
        $series = $ordered;
    } elseif ( 'product' === $group_by ) {
        arsort( $series, SORT_NUMERIC );
    }

    $rows = array();
    foreach ( $series as $key => $value ) {
        $rows[] = array(
            'label'  => viswiz_commerce_builder_group_label( $group_by, $key ),
            'value'  => round( (float) $value, 2 ),
            'color'  => '',
            'source' => 'woocommerce',
        );
    }

    foreach ( $manual_rows as $row ) {
        $rows[] = array(
            'label'  => $row['label'],
            'value'  => $row['value'],
            'color'  => '',
            'source' => 'manual',
        );
    }

    return rest_ensure_response(
        array(
            'rows' => $rows,
            'meta' => array(
                'year'              => $year,
                'metric'            => $metric,
                'group_by'          => $group_by,
                'subscription_only' => $subscription_only,
                'orders_scanned'    => count( $order_ids ),
                'orders_matched'    => count( $matched_orders ),
                'currency'          => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
                'snapshot_at'       => current_time( 'mysql' ),
            ),
        )
    );
}

function viswiz_commerce_builder_get_order_ids( DateTimeImmutable $start, DateTimeImmutable $end ) {
    $page     = 1;
    $ids      = array();
    $statuses = function_exists( 'wc_get_is_paid_statuses' ) ? wc_get_is_paid_statuses() : array( 'processing', 'completed' );
    $statuses = array_map(
        function ( $status ) {
            return 0 === strpos( $status, 'wc-' ) ? $status : 'wc-' . $status;
        },
        $statuses
    );

    do {
        $result = wc_get_orders(
            array(
                'status'       => $statuses,
                'date_created' => $start->format( 'Y-m-d H:i:s' ) . '...' . $end->format( 'Y-m-d H:i:s' ),
                'limit'        => 200,
                'page'         => $page,
                'paginate'     => true,
                'return'       => 'ids',
                'orderby'      => 'date',
                'order'        => 'ASC',
            )
        );

        if ( empty( $result->orders ) ) {
            break;
        }

        $ids = array_merge( $ids, array_map( 'absint', $result->orders ) );
        $page++;
    } while ( $page <= (int) $result->max_num_pages );

    return $ids;
}

function viswiz_commerce_builder_matching_items( WC_Order $order, $product_ids, $category_ids, $subscription_only = false ) {
    $matching     = array();
    $product_ids  = array_map( 'absint', (array) $product_ids );
    $category_ids = array_map( 'absint', (array) $category_ids );

    foreach ( $order->get_items( 'line_item' ) as $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }

        $product_id   = (int) $item->get_product_id();
        $variation_id = (int) $item->get_variation_id();
        $product      = $item->get_product();
        $matches_product = empty( $product_ids ) || in_array( $product_id, $product_ids, true ) || ( $variation_id && in_array( $variation_id, $product_ids, true ) );
        $matches_category = empty( $category_ids );

        if ( $category_ids ) {
            $term_ids = wc_get_product_term_ids( $product_id, 'product_cat' );
            $matches_category = (bool) array_intersect( $category_ids, array_map( 'absint', $term_ids ) );
        }

        $matches_subscription = true;
        if ( $subscription_only ) {
            $matches_subscription = false;
            if ( $product && function_exists( 'wcs_is_subscription' ) ) {
                $matches_subscription = (bool) wcs_is_subscription( $product );
            } elseif ( $product ) {
                $matches_subscription = $product->is_type( array( 'subscription', 'variable-subscription', 'subscription_variation' ) );
            }
        }

        if ( $matches_product && $matches_category && $matches_subscription ) {
            $matching[] = $item;
        }
    }

    return $matching;
}

function viswiz_commerce_builder_group_key( $group_by, WC_Order $order, $item = null ) {
    if ( 'month' === $group_by ) {
        $date = $order->get_date_created();
        return $date ? $date->date_i18n( 'Y-m' ) : '';
    }

    if ( 'product' === $group_by && $item instanceof WC_Order_Item_Product ) {
        $product_id = (int) ( $item->get_variation_id() ?: $item->get_product_id() );
        return 'product:' . $product_id;
    }

    return 'total';
}

function viswiz_commerce_builder_group_label( $group_by, $key ) {
    if ( 'month' === $group_by && preg_match( '/^(\d{4})-(\d{2})$/', $key, $match ) ) {
        $timestamp = mktime( 12, 0, 0, (int) $match[2], 1, (int) $match[1] );
        return wp_date( 'F Y', $timestamp );
    }

    if ( 'product' === $group_by && 0 === strpos( $key, 'product:' ) ) {
        $product_id = absint( substr( $key, 8 ) );
        $product = wc_get_product( $product_id );
        return $product ? $product->get_name() : sprintf( __( 'Product #%d', 'viswiz' ), $product_id );
    }

    return __( 'WooCommerce total', 'viswiz' );
}

function viswiz_commerce_builder_parse_ids( $value ) {
    if ( is_array( $value ) ) {
        $parts = $value;
    } else {
        $parts = preg_split( '/[\s,;]+/', (string) $value );
    }

    return array_values( array_unique( array_filter( array_map( 'absint', (array) $parts ) ) ) );
}

function viswiz_commerce_builder_sanitize_manual_rows( $rows ) {
    $clean = array();
    foreach ( (array) $rows as $row ) {
        $label = sanitize_text_field( $row['label'] ?? '' );
        if ( '' === $label || ! isset( $row['value'] ) || ! is_numeric( $row['value'] ) ) {
            continue;
        }
        $clean[] = array(
            'label' => $label,
            'value' => round( (float) $row['value'], 2 ),
        );
    }
    return $clean;
}
