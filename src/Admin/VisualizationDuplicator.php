<?php
namespace VisWiz\Admin;

final class VisualizationDuplicator {
    private const META_KEYS = array(
        '_viswiz_renderer',
        '_viswiz_source_type',
        '_viswiz_dataset_id',
        '_viswiz_settings',
        '_viswiz_woo_config',
    );

    public static function register(): void {
        add_action( 'admin_post_viswiz_visualization_duplicate', array( self::class, 'duplicate' ) );
        add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
        add_action( 'add_meta_boxes_viswiz_visualization', array( self::class, 'add_meta_box' ), 20 );
    }

    public static function row_actions( array $actions, \WP_Post $post ): array {
        if ( 'viswiz_visualization' !== $post->post_type || ! self::can_duplicate( $post->ID ) ) {
            return $actions;
        }

        $actions['viswiz_duplicate'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url( self::duplicate_url( $post->ID ) ),
            esc_html__( 'Duplicate', 'viswiz' )
        );

        return $actions;
    }

    public static function add_meta_box( \WP_Post $post ): void {
        if ( 'auto-draft' === $post->post_status || ! self::can_duplicate( $post->ID ) ) {
            return;
        }

        add_meta_box(
            'viswiz-visualization-actions',
            __( 'Visualization actions', 'viswiz' ),
            array( self::class, 'render_meta_box' ),
            'viswiz_visualization',
            'side',
            'low'
        );
    }

    public static function render_meta_box( \WP_Post $post ): void {
        ?>
        <p><?php esc_html_e( 'Create a draft with the same renderer, data source and display settings. Dataset data is reused, not copied.', 'viswiz' ); ?></p>
        <p><a class="button" data-viswiz-duplicate-visualization href="<?php echo esc_url( self::duplicate_url( $post->ID ) ); ?>"><?php esc_html_e( 'Duplicate visualization', 'viswiz' ); ?></a></p>
        <?php
    }

    public static function duplicate(): void {
        $source_id = absint( $_GET['post_id'] ?? 0 );
        $source    = get_post( $source_id );

        if ( ! $source || 'viswiz_visualization' !== $source->post_type ) {
            wp_die( esc_html__( 'Visualization not found.', 'viswiz' ) );
        }
        if ( ! self::can_duplicate( $source_id ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
        }

        check_admin_referer( 'viswiz_visualization_duplicate_' . $source_id );

        $title = trim( (string) $source->post_title );
        if ( '' === $title ) {
            $title = __( 'Untitled visualization', 'viswiz' );
        }

        $new_id = wp_insert_post(
            array(
                'post_type'   => 'viswiz_visualization',
                'post_status' => 'draft',
                'post_title'  => $title . ' — ' . __( 'Copy', 'viswiz' ),
                'post_author' => get_current_user_id(),
            ),
            true
        );

        if ( is_wp_error( $new_id ) ) {
            wp_die( esc_html( $new_id->get_error_message() ) );
        }

        foreach ( self::META_KEYS as $meta_key ) {
            if ( metadata_exists( 'post', $source_id, $meta_key ) ) {
                update_post_meta( $new_id, $meta_key, get_post_meta( $source_id, $meta_key, true ) );
            }
        }

        wp_safe_redirect(
            admin_url(
                'post.php?post=' . $new_id . '&action=edit&viswiz_duplicated_from=' . $source_id
            )
        );
        exit;
    }

    private static function can_duplicate( int $post_id ): bool {
        return $post_id > 0
            && current_user_can( 'edit_post', $post_id )
            && current_user_can( 'edit_viswiz_visualizations' );
    }

    private static function duplicate_url( int $post_id ): string {
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=viswiz_visualization_duplicate&post_id=' . $post_id ),
            'viswiz_visualization_duplicate_' . $post_id
        );
    }
}
