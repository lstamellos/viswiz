<?php
namespace VisWiz\Admin;

use VisWiz\Frontend\Frontend;

final class VisualizationPreview {
    private const BOOLEAN_SETTINGS = array(
        'full_screen',
        'show_legend',
        'show_graph_toolbar',
        'show_graph_search',
        'show_graph_filters',
        'show_graph_zoom',
        'show_node_images',
        'show_type_badges',
        'show_relation_labels',
    );

    public static function register(): void {
        add_action( 'add_meta_boxes_viswiz_visualization', array( self::class, 'meta_box' ), 20 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 90 );
        add_action( 'save_post_viswiz_visualization', array( self::class, 'normalize_saved_settings' ), 20, 2 );
    }

    public static function meta_box(): void {
        add_meta_box(
            'viswiz-live-preview',
            __( 'Live preview', 'viswiz' ),
            array( self::class, 'render' ),
            'viswiz_visualization',
            'normal',
            'high'
        );
    }

    public static function assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'viswiz_visualization' !== $screen->post_type ) {
            return;
        }

        wp_enqueue_style( 'viswiz-frontend' );
        wp_enqueue_script( 'viswiz-frontend' );
        wp_enqueue_script( 'viswiz-graph-runtime' );
        wp_enqueue_script(
            'viswiz-visualization-preview',
            VISWIZ_URL . 'assets/viswiz-visualization-preview.js',
            array( 'viswiz-admin-v2', 'viswiz-frontend', 'viswiz-graph-runtime' ),
            VISWIZ_VERSION,
            true
        );
        wp_add_inline_style(
            'viswiz-admin-v2',
            '.viswiz-live-preview-note{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px}.viswiz-live-preview-badge{display:inline-block;padding:2px 7px;border:1px solid #dba617;border-radius:999px;background:#fcf9e8;font-weight:600}.viswiz-admin-live-preview{min-height:180px;max-width:100%;overflow:hidden;border:1px solid #dcdcde;border-radius:4px;padding:12px;background:#fff;box-sizing:border-box}.viswiz-live-preview-status{margin:8px 0 0}.viswiz-live-preview-status.is-error{color:#b32d2e}'
        );
    }

    public static function render(): void {
        ?>
        <div data-viswiz-live-preview>
            <p class="viswiz-live-preview-note">
                <span class="viswiz-live-preview-badge"><?php esc_html_e( 'Unsaved preview', 'viswiz' ); ?></span>
                <span><?php esc_html_e( 'This uses the public renderer. Changes are not published until you save or update the visualization.', 'viswiz' ); ?></span>
            </p>
            <div class="viswiz-visualization viswiz-admin-live-preview" data-viswiz-preview-canvas>
                <p class="viswiz-loading"><?php esc_html_e( 'Loading preview…', 'viswiz' ); ?></p>
            </div>
            <p class="description viswiz-live-preview-status" data-viswiz-preview-status aria-live="polite"></p>
        </div>
        <?php
    }

    public static function normalize_saved_settings( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['viswiz_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['viswiz_nonce'] ) ), 'viswiz_save_visualization' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) || ! isset( $_POST['viswiz_settings'] ) ) {
            return;
        }

        $raw = (array) wp_unslash( $_POST['viswiz_settings'] );
        foreach ( self::BOOLEAN_SETTINGS as $key ) {
            $raw[ $key ] = isset( $raw[ $key ] ) ? rest_sanitize_boolean( $raw[ $key ] ) : false;
        }
        update_post_meta( $post_id, '_viswiz_settings', Frontend::sanitize_settings( $raw ) );
    }
}
