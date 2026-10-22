<?php
namespace VisWiz\Rest;

use VisWiz\Access;
use VisWiz\Frontend\Frontend;
use WP_REST_Request;
use WP_REST_Server;

final class VisualizationPreviewApi {
    public static function register(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function routes(): void {
        register_rest_route(
            'viswiz/v2',
            '/visualizations/preview',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'preview' ),
                'permission_callback' => static function( WP_REST_Request $request ): bool {
                    if ( ! current_user_can( 'edit_viswiz_visualizations' ) ) {
                        return false;
                    }
                    $config = (array) $request->get_param( 'config' );
                    $post_id = absint( $config['id'] ?? 0 );
                    if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
                        return false;
                    }
                    if ( 'dataset' !== sanitize_key( (string) ( $config['source_type'] ?? 'dataset' ) ) ) {
                        return current_user_can( 'manage_woocommerce' );
                    }
                    $id = absint( $config['dataset_id'] ?? 0 );
                    // An assigned visualization editor may preview its existing data source,
                    // without automatically being granted editing rights on that dataset.
                    return Access::can_edit_dataset( $id ) ||
                        ( $post_id && current_user_can( 'edit_post', $post_id ) &&
                          $id === absint( get_post_meta( $post_id, '_viswiz_dataset_id', true ) ) );
                },
            )
        );
    }

    public static function preview( WP_REST_Request $request ) {
        $payload = Frontend::preview_payload( (array) $request->get_param( 'config' ) );
        return is_wp_error( $payload ) ? $payload : rest_ensure_response( $payload );
    }
}
