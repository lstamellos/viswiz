<?php
namespace VisWiz\Rest;

use VisWiz\Database\DatasetCollectionRepository;
use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\RowSchema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class DatasetEditorApi {
    public static function register(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function routes(): void {
        foreach ( array( 'rows', 'nodes', 'relations' ) as $collection ) {
            register_rest_route(
                'viswiz/v2',
                '/datasets/(?P<id>\d+)/' . $collection,
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( self::class, $collection ),
                    'permission_callback' => array( self::class, 'can_edit_datasets' ),
                    'args'                => self::collection_args(),
                )
            );
        }

        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/nodes/options',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( self::class, 'node_options' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
                'args'                => array(
                    'id'       => array( 'sanitize_callback' => 'absint' ),
                    'search'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
                    'per_page' => array(
                        'type'              => 'integer',
                        'default'           => 20,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn( $value ): bool => (int) $value >= 1 && (int) $value <= 50,
                    ),
                ),
            )
        );

        self::register_write_routes();
    }

    public static function can_edit_datasets(): bool {
        return current_user_can( 'edit_viswiz_datasets' );
    }

    public static function rows( WP_REST_Request $request ) {
        $dataset = self::dataset_for_collection( $request, false );
        if ( is_wp_error( $dataset ) ) {
            return $dataset;
        }
        $repo = new DatasetCollectionRepository();
        return self::collection_response( $repo->rows( (int) $dataset['id'], self::page( $request ), self::per_page( $request ), (string) $request->get_param( 'search' ) ), (int) $dataset['revision'] );
    }

    public static function nodes( WP_REST_Request $request ) {
        $dataset = self::dataset_for_collection( $request, true );
        if ( is_wp_error( $dataset ) ) {
            return $dataset;
        }
        $repo = new DatasetCollectionRepository();
        return self::collection_response( $repo->nodes( (int) $dataset['id'], self::page( $request ), self::per_page( $request ), (string) $request->get_param( 'search' ) ), (int) $dataset['revision'] );
    }

    public static function relations( WP_REST_Request $request ) {
        $dataset = self::dataset_for_collection( $request, true );
        if ( is_wp_error( $dataset ) ) {
            return $dataset;
        }
        $repo = new DatasetCollectionRepository();
        return self::collection_response( $repo->relations( (int) $dataset['id'], self::page( $request ), self::per_page( $request ), (string) $request->get_param( 'search' ) ), (int) $dataset['revision'] );
    }

    public static function node_options( WP_REST_Request $request ) {
        $dataset = self::dataset_for_collection( $request, true );
        if ( is_wp_error( $dataset ) ) {
            return $dataset;
        }
        $repo = new DatasetCollectionRepository();
        return rest_ensure_response(
            $repo->node_options(
                (int) $dataset['id'],
                (string) $request->get_param( 'search' ),
                min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) )
            )
        );
    }

    public static function save_row( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        $dataset = $repo->get( absint( $request['id'] ) );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( 'graph' === (string) $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $row = RowSchema::normalize_for_editor( (string) $dataset['schema_type'], (array) $request->get_param( 'row' ) );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        return self::compact_write_response( $repo->save_row( (int) $dataset['id'], $row, self::revision( $request ) ) );
    }

    public static function delete_row( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::compact_write_response( $repo->delete_row( absint( $request['id'] ), sanitize_text_field( (string) $request['uuid'] ), self::revision( $request ) ) );
    }

    public static function save_node( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::compact_write_response( $repo->save_node( absint( $request['id'] ), (array) $request->get_param( 'node' ), self::revision( $request ) ) );
    }

    public static function delete_node( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::compact_write_response( $repo->delete_node( absint( $request['id'] ), sanitize_text_field( (string) $request['uuid'] ), self::revision( $request ) ) );
    }

    public static function save_relation( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::compact_write_response( $repo->save_edge( absint( $request['id'] ), (array) $request->get_param( 'relation' ), self::revision( $request ) ) );
    }

    public static function delete_relation( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::compact_write_response( $repo->delete_edge( absint( $request['id'] ), sanitize_text_field( (string) $request['uuid'] ), self::revision( $request ) ) );
    }

    private static function register_write_routes(): void {
        foreach (
            array(
                'rows'      => array( 'save' => 'save_row', 'delete' => 'delete_row' ),
                'nodes'     => array( 'save' => 'save_node', 'delete' => 'delete_node' ),
                'relations' => array( 'save' => 'save_relation', 'delete' => 'delete_relation' ),
            ) as $collection => $callbacks
        ) {
            register_rest_route(
                'viswiz/v2',
                '/datasets/(?P<id>\d+)/editor/' . $collection,
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( self::class, $callbacks['save'] ),
                    'permission_callback' => array( self::class, 'can_edit_datasets' ),
                    'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
                )
            );
            register_rest_route(
                'viswiz/v2',
                '/datasets/(?P<id>\d+)/editor/' . $collection . '/(?P<uuid>[a-f0-9-]{36})',
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( self::class, $callbacks['delete'] ),
                    'permission_callback' => array( self::class, 'can_edit_datasets' ),
                    'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
                )
            );
        }
    }

    private static function collection_args(): array {
        return array(
            'id'       => array( 'sanitize_callback' => 'absint' ),
            'page'     => array(
                'description'       => 'Current page of the dataset collection.',
                'type'              => 'integer',
                'default'           => 1,
                'sanitize_callback' => 'absint',
                'validate_callback' => static fn( $value ): bool => (int) $value >= 1,
            ),
            'per_page' => array(
                'description'       => 'Maximum number of dataset items returned.',
                'type'              => 'integer',
                'default'           => 100,
                'sanitize_callback' => 'absint',
                'validate_callback' => static fn( $value ): bool => (int) $value >= 1 && (int) $value <= DatasetCollectionRepository::MAX_PER_PAGE,
            ),
            'search'   => array(
                'description'       => 'Limit results to items matching this string.',
                'type'              => 'string',
                'default'           => '',
                'sanitize_callback' => 'sanitize_text_field',
            ),
        );
    }

    private static function dataset_for_collection( WP_REST_Request $request, bool $graph ) {
        $repo = new DatasetRepository();
        $dataset = $repo->get( absint( $request['id'] ) );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( $graph && 'graph' !== $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_graph_dataset_required', __( 'A graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }
        if ( ! $graph && 'graph' === $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }
        return $dataset;
    }

    private static function collection_response( array $result, int $revision ): WP_REST_Response {
        $response = new WP_REST_Response( $result['items'] );
        $response->header( 'X-WP-Total', (string) $result['total'] );
        $response->header( 'X-WP-TotalPages', (string) $result['total_pages'] );
        $response->header( 'X-VisWiz-Page', (string) $result['page'] );
        $response->header( 'X-VisWiz-Per-Page', (string) $result['per_page'] );
        $response->header( 'X-VisWiz-Revision', (string) $revision );
        return $response;
    }

    private static function compact_write_response( mixed $result ) {
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response(
            array(
                'dataset'  => $result['dataset'] ?? null,
                'revision' => (int) ( $result['revision'] ?? 0 ),
            )
        );
    }

    private static function page( WP_REST_Request $request ): int {
        return max( 1, absint( $request->get_param( 'page' ) ?: 1 ) );
    }

    private static function per_page( WP_REST_Request $request ): int {
        return min( DatasetCollectionRepository::MAX_PER_PAGE, max( 1, absint( $request->get_param( 'per_page' ) ?: 100 ) ) );
    }

    private static function revision( WP_REST_Request $request ): ?int {
        $revision = $request->get_param( 'expected_revision' );
        return null === $revision || '' === $revision ? null : absint( $revision );
    }
}
