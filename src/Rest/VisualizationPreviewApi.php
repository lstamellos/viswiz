<?php
namespace VisWiz\Rest;

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
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_visualizations' ),
            )
        );
    }

    public static function preview( WP_REST_Request $request ) {
        $payload = Frontend::preview_payload( (array) $request->get_param( 'config' ) );
        return is_wp_error( $payload ) ? $payload : rest_ensure_response( $payload );
    }
}
