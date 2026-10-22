<?php
namespace VisWiz\Rest;

use VisWiz\Access\Permissions;

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
                'permission_callback' => static function ( WP_REST_Request $request ): bool {
                    if ( ! Permissions::can_create_visualization() ) {
                        return false;
                    }
                    $config = (array) $request->get_param( 'config' );
                    return sanitize_key( (string) ( $config['source_type'] ?? 'dataset' ) ) !== 'dataset'
                        || Permissions::can_edit_dataset( absint( $config['dataset_id'] ?? 0 ) );
                },
            )
        );
    }

    public static function preview( WP_REST_Request $request ) {
        $payload = Frontend::preview_payload( (array) $request->get_param( 'config' ) );
        return is_wp_error( $payload ) ? $payload : rest_ensure_response( $payload );
    }
}
