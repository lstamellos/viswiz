<?php
namespace VisWiz\WooCommerce;

final class Compatibility {
    public static function register(): void {
        add_action( 'before_woocommerce_init', array( self::class, 'declare_hpos' ) );
        add_action( 'woocommerce_order_status_changed', array( self::class, 'invalidate_cache' ) );
        add_action( 'woocommerce_order_refunded', array( self::class, 'invalidate_cache' ) );
        add_action( 'woocommerce_refund_created', array( self::class, 'invalidate_cache' ) );
        add_action( 'woocommerce_refund_deleted', array( self::class, 'invalidate_cache' ) );
        add_action( 'woocommerce_saved_order_items', array( self::class, 'invalidate_cache' ) );
        add_action( 'set_object_terms', array( self::class, 'maybe_invalidate_product_category_cache' ), 10, 6 );
    }

    public static function declare_hpos(): void {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VISWIZ_FILE, true );
        }
    }

    public static function invalidate_cache( ...$args ): void {
        update_option( 'viswiz_woo_cache_epoch', max( time(), (int) get_option( 'viswiz_woo_cache_epoch', 1 ) + 1 ), false );
    }

    public static function maybe_invalidate_product_category_cache( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
        if ( 'product_cat' === $taxonomy && 'product' === get_post_type( $object_id ) ) {
            self::invalidate_cache();
        }
    }
}
