<?php
namespace VisWiz\Rest;

use VisWiz\Import\DatasetImporter;
use WP_REST_Request;
use WP_REST_Server;

final class ImportApi {
    public static function register(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function routes(): void {
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/import/preview',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'preview' ),
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_datasets' ),
                'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/import',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'commit' ),
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_datasets' ),
                'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
            )
        );
    }

    public static function preview( WP_REST_Request $request ) {
        $importer = new DatasetImporter();
        $result = $importer->preview( absint( $request['id'] ), self::import_args( $request ) );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    public static function commit( WP_REST_Request $request ) {
        $revision = $request->get_param( 'expected_revision' );
        $revision = null === $revision || '' === $revision ? null : absint( $revision );
        $importer = new DatasetImporter();
        $result = $importer->commit( absint( $request['id'] ), self::import_args( $request ), $revision );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    private static function import_args( WP_REST_Request $request ): array {
        return array(
            'kind'    => sanitize_key( (string) $request->get_param( 'kind' ) ),
            'mode'    => sanitize_key( (string) $request->get_param( 'mode' ) ),
            'mapping' => (array) $request->get_param( 'mapping' ),
            'records' => (array) $request->get_param( 'records' ),
        );
    }
}
