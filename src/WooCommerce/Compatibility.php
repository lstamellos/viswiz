<?php
namespace VisWiz\WooCommerce;

final class Compatibility {
    public static function register(): void {
        add_action( 'before_woocommerce_init', array( self::class, 'declare_hpos' ) );
    }

    public static function declare_hpos(): void {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', VISWIZ_FILE, true );
        }
    }
}
