<?php
/**
 * Plugin Name: VisWiz WooCommerce Visualizer
 * Description: Dataset-first charts, progress visualizations and investigative node graphs for WordPress and WooCommerce.
 * Version: 2.0.2
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: cremedia.studio
 * Update URI: https://github.com/lstamellos/viswiz
 * Text Domain: viswiz
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VISWIZ_VERSION', '2.0.2' );
define( 'VISWIZ_DB_VERSION', 20000 );
define( 'VISWIZ_FILE', __FILE__ );
define( 'VISWIZ_DIR', plugin_dir_path( __FILE__ ) );
define( 'VISWIZ_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
    static function ( string $class ): void {
        $prefix = 'VisWiz\\';
        if ( ! str_starts_with( $class, $prefix ) ) {
            return;
        }
        $relative = substr( $class, strlen( $prefix ) );
        $path     = VISWIZ_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
        if ( is_readable( $path ) ) {
            require_once $path;
        }
    }
);

VisWiz\Plugin::register();
VisWiz\Runtime\RenderingCompatibility::register();
