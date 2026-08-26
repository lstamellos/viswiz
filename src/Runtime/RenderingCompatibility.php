<?php
namespace VisWiz\Runtime;

final class RenderingCompatibility {
    private const SCRIPT_HANDLE        = 'viswiz-rendering-compat';
    private const NODE_CARD_HANDLE     = 'viswiz-node-cards';
    private const GRAPH_CONTEXT_HANDLE = 'viswiz-graph-context-fix';
    private const TOOLBAR_UX_HANDLE    = 'viswiz-toolbar-ux';

    public static function register(): void {
        add_action( 'init', array( self::class, 'register_assets' ), 30 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ), 100 );
        add_action( 'wp_footer', array( self::class, 'enqueue_frontend_footer' ), 1 );
    }

    public static function register_assets(): void {
        // The compatibility stylesheet must travel with the main frontend style.
        // Graph blocks may enqueue their assets after wp_head; a new stylesheet
        // first enqueued from wp_footer is therefore not guaranteed to be printed.
        if ( wp_style_is( 'viswiz-frontend', 'registered' ) ) {
            $css_file = VISWIZ_DIR . 'assets/viswiz-rendering-compat.css';
            if ( is_readable( $css_file ) ) {
                $css = file_get_contents( $css_file );
                if ( false !== $css && '' !== $css ) {
                    wp_add_inline_style( 'viswiz-frontend', $css );
                }
            }
        }

        wp_register_script(
            self::SCRIPT_HANDLE,
            VISWIZ_URL . 'assets/viswiz-rendering-compat.js',
            array( 'viswiz-frontend' ),
            VISWIZ_VERSION,
            true
        );
        wp_register_script(
            self::NODE_CARD_HANDLE,
            VISWIZ_URL . 'assets/viswiz-node-cards.js',
            array( self::SCRIPT_HANDLE ),
            VISWIZ_VERSION,
            true
        );
        wp_register_script(
            self::GRAPH_CONTEXT_HANDLE,
            VISWIZ_URL . 'assets/viswiz-graph-context-fix.js',
            array( self::NODE_CARD_HANDLE ),
            VISWIZ_VERSION,
            true
        );
        wp_register_script(
            self::TOOLBAR_UX_HANDLE,
            VISWIZ_URL . 'assets/viswiz-toolbar-ux.js',
            array( self::GRAPH_CONTEXT_HANDLE ),
            VISWIZ_VERSION,
            true
        );
    }

    private static function enqueue_assets(): void {
        wp_enqueue_script( self::SCRIPT_HANDLE );
        wp_enqueue_script( self::NODE_CARD_HANDLE );
        wp_enqueue_script( self::GRAPH_CONTEXT_HANDLE );
        wp_enqueue_script( self::TOOLBAR_UX_HANDLE );
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
