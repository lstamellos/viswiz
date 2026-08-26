<?php
namespace VisWiz\Runtime;

final class GraphRuntime {
    private const SCRIPT_HANDLE = 'viswiz-graph-runtime';

    public static function register(): void {
        add_action( 'init', array( self::class, 'register_assets' ), 30 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ), 100 );
        add_action( 'wp_footer', array( self::class, 'enqueue_frontend_footer' ), 1 );
    }

    public static function register_assets(): void {
        /*
         * Graph CSS is attached to the primary VisWiz stylesheet because a
         * dynamic block/shortcode can enqueue the frontend assets after
         * wp_head. Keeping one style handle avoids a late standalone stylesheet.
         */
        if ( wp_style_is( 'viswiz-frontend', 'registered' ) ) {
            $css_file = VISWIZ_DIR . 'assets/viswiz-graph-runtime.css';
            if ( is_readable( $css_file ) ) {
                $css = file_get_contents( $css_file );
                if ( false !== $css && '' !== $css ) {
                    wp_add_inline_style( 'viswiz-frontend', $css );
                }
            }
        }

        wp_register_script(
            self::SCRIPT_HANDLE,
            VISWIZ_URL . 'assets/viswiz-graph-runtime.js',
            array( 'viswiz-frontend' ),
            VISWIZ_VERSION,
            true
        );
    }

    private static function enqueue_assets(): void {
        wp_enqueue_script( self::SCRIPT_HANDLE );
    }

    public static function enqueue_admin(): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'viswiz-datasets' !== $page || ! isset( $_GET['dataset_id'] ) ) {
            return;
        }
        if ( wp_script_is( 'viswiz-frontend', 'enqueued' ) ) {
            self::enqueue_assets();
        }
    }

    public static function enqueue_frontend_footer(): void {
        if ( wp_script_is( 'viswiz-frontend', 'enqueued' ) ) {
            self::enqueue_assets();
        }
    }
}
