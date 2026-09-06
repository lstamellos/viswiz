<?php
namespace VisWiz\Admin;

use VisWiz\Support;

final class WooSourceSelection {
    public static function register(): void {
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 95 );
    }

    public static function assets(): void {
        $screen = get_current_screen();
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $is_visualization = $screen && 'viswiz_visualization' === $screen->post_type;
        $is_dataset_detail = 'viswiz-datasets' === $page && isset( $_GET['dataset_id'] );
        if ( ! $is_visualization && ! $is_dataset_detail ) {
            return;
        }

        $woo_available = class_exists( '\\WooCommerce' ) && function_exists( 'WC' ) && WC();
        $searchable = $woo_available && current_user_can( 'edit_products' );
        $snapshot_allowed = $woo_available && current_user_can( 'manage_woocommerce' );
        $dependencies = array('viswiz-admin-v2', 'wp-i18n');

        if ( $searchable && wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
            wp_enqueue_script( 'wc-enhanced-select' );
            $dependencies[] = 'wc-enhanced-select';
            self::enqueue_select2_style();
        }

        wp_enqueue_script(
            'viswiz-woo-source-selection',
            VISWIZ_URL . 'assets/viswiz-woo-source-selection.js',
            $dependencies,
            VISWIZ_VERSION,
            true
        );
        wp_set_script_translations( 'viswiz-woo-source-selection', 'viswiz', VISWIZ_DIR . 'languages' );
        wp_localize_script(
            'viswiz-woo-source-selection',
            'VisWizWooSourceSelection',
            array(
                'available'       => (bool) $woo_available,
                'searchable'      => (bool) $searchable,
                'snapshotAllowed' => (bool) $snapshot_allowed,
                'products'        => self::selected_product_labels( $is_visualization ),
                'categories'      => self::selected_category_labels( $is_visualization ),
                    'categories'             => __( 'Categories', 'viswiz' ),
                    'searchProducts'         => __( 'Search products…', 'viswiz' ),
                    'searchCategories'       => __( 'Search product categories…', 'viswiz' ),
                    'liveOption'             => __( 'WooCommerce live query', 'viswiz' ),
                    'liveDescription'        => __( 'Live query: recalculates from current WooCommerce orders when requested and uses the configured cache/refresh interval. No rows are copied into a dataset.', 'viswiz' ),
                    'snapshotDescription'    => __( 'Snapshot: runs the WooCommerce query once and replaces this canonical dataset with the current results. The copied rows can then be edited independently and do not stay synchronized with WooCommerce.', 'viswiz' ),
                    'snapshotButton'         => __( 'Replace dataset with current snapshot', 'viswiz' ),
                    'woocommerceInactive'    => __( 'WooCommerce is not active. Existing WooCommerce filter values are preserved, but new live queries or snapshots cannot be run.', 'viswiz' ),
                    'manualIdsFallback'      => __( 'WooCommerce search pickers are not available for this account. Product and category IDs remain editable manually.', 'viswiz' ),
                    'snapshotPermission'     => __( 'Your account does not have permission to run WooCommerce snapshots.', 'viswiz' ),
                    'graphSnapshotDisabled'  => __( 'WooCommerce snapshots require a row-based dataset and cannot replace graph data.', 'viswiz' ),
                ),
            )
        );
    }

    private static function enqueue_select2_style(): void {
        if ( ! function_exists( 'WC' ) || ! WC() ) {
            return;
        }
        $handle = 'viswiz-woocommerce-select2';
        if ( ! wp_style_is( $handle, 'registered' ) ) {
            wp_register_style(
                $handle,
                WC()->plugin_url() . '/assets/css/select2.css',
                array(),
                defined( 'WC_VERSION' ) ? WC_VERSION : null
            );
        }
        wp_enqueue_style( $handle );
    }

    private static function selected_config( bool $is_visualization ): array {
        if ( ! $is_visualization ) {
            return array();
        }
        $post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        if ( ! $post_id ) {
            return array();
        }
        return Support::json_decode_array( get_post_meta( $post_id, '_viswiz_woo_config', true ) );
    }

    private static function selected_product_labels( bool $is_visualization ): array {
        $labels = array();
        $config = self::selected_config( $is_visualization );
        foreach ( Support::int_list( $config['product_ids'] ?? array() ) as $id ) {
            $label = sprintf( __( 'Product #%d', 'viswiz' ), $id );
            if ( function_exists( 'wc_get_product' ) ) {
                $product = wc_get_product( $id );
                if ( $product ) {
                    $label = wp_strip_all_tags( $product->get_formatted_name() );
                }
            }
            $labels[ (string) $id ] = $label;
        }
        return $labels;
    }

    private static function selected_category_labels( bool $is_visualization ): array {
        $labels = array();
        $config = self::selected_config( $is_visualization );
        foreach ( Support::int_list( $config['category_ids'] ?? array() ) as $id ) {
            $label = sprintf( __( 'Category #%d', 'viswiz' ), $id );
            if ( taxonomy_exists( 'product_cat' ) ) {
                $term = get_term( $id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $label = $term->name;
                }
            }
            $labels[ (string) $id ] = $label;
        }
        return $labels;
    }
}
