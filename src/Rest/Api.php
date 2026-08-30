<?php
namespace VisWiz\Rest;

use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\RowWriteGuard;
use VisWiz\Frontend\Frontend;
use VisWiz\WooCommerce\SalesQuery;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class Api {
    public static function register(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function routes(): void {
        register_rest_route(
            'viswiz/v2',
            '/visualizations/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( self::class, 'visualization' ),
                'permission_callback' => array( self::class, 'can_read_visualization' ),
                'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/visualizations',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( self::class, 'visualizations' ),
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_visualizations' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)',
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( self::class, 'dataset' ),
                    'permission_callback' => array( self::class, 'can_edit_datasets' ),
                ),
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( self::class, 'replace_dataset' ),
                    'permission_callback' => array( self::class, 'can_edit_datasets' ),
                ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/rows',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'save_row' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/rows/(?P<uuid>[a-f0-9-]{36})',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( self::class, 'delete_row' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/nodes',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'save_node' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/nodes/(?P<uuid>[a-f0-9-]{36})',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( self::class, 'delete_node' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/relations',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'save_relation' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/relations/(?P<uuid>[a-f0-9-]{36})',
            array(
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => array( self::class, 'delete_relation' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/revisions',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( self::class, 'revisions' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/revisions/(?P<revision>\d+)/restore',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'restore_revision' ),
                'permission_callback' => array( self::class, 'can_edit_datasets' ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/commerce-snapshot',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'commerce_snapshot' ),
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_datasets' ) && current_user_can( 'manage_woocommerce' ),
            )
        );
    }

    public static function can_read_visualization( WP_REST_Request $request ): bool {
        $id = absint( $request['id'] );
        return 'publish' === get_post_status( $id ) || current_user_can( 'edit_post', $id );
    }

    public static function can_edit_datasets(): bool {
        return current_user_can( 'edit_viswiz_datasets' );
    }

    public static function visualization( WP_REST_Request $request ) {
        $payload = Frontend::get_payload( absint( $request['id'] ) );
        return is_wp_error( $payload ) ? $payload : rest_ensure_response( $payload );
    }

    public static function visualizations() {
        $posts = get_posts(
            array(
                'post_type'      => 'viswiz_visualization',
                'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
                'posts_per_page' => 200,
                'orderby'        => 'title',
                'order'          => 'ASC',
            )
        );
        $items = array();
        foreach ( $posts as $post ) {
            $items[] = array(
                'id'        => $post->ID,
                'title'     => get_the_title( $post ),
                'renderer'  => get_post_meta( $post->ID, '_viswiz_renderer', true ) ?: 'pie',
                'status'    => get_post_status( $post ),
                'editUrl'   => get_edit_post_link( $post->ID, 'raw' ),
                'shortcode' => sprintf( '[viswiz_visualization id="%d"]', $post->ID ),
            );
        }
        return rest_ensure_response( $items );
    }

    public static function dataset( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        $id   = absint( $request['id'] );
        if ( ! $repo->get( $id ) ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        return rest_ensure_response( $repo->response( $id ) );
    }

    public static function replace_dataset( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        $id   = absint( $request['id'] );
        $dataset = $repo->get( $id );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        $payload = (array) $request->get_param( 'payload' );
        if ( 'graph' !== (string) $dataset['schema_type'] ) {
            $payload = RowWriteGuard::normalize_payload( (string) $dataset['schema_type'], $payload );
            if ( is_wp_error( $payload ) ) {
                return $payload;
            }
        }
        return self::respond(
            $repo->replace_payload(
                $id,
                $payload,
                self::revision( $request ),
                sanitize_text_field( (string) ( $request->get_param( 'note' ) ?: 'Dataset import / replacement' ) )
            )
        );
    }

    public static function save_row( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        $id   = absint( $request['id'] );
        $dataset = $repo->get( $id );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( 'graph' === (string) $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $row = RowWriteGuard::normalize_row( (string) $dataset['schema_type'], (array) $request->get_param( 'row' ) );
        if ( is_wp_error( $row ) ) {
            return $row;
        }
        return self::respond( $repo->save_row( $id, $row, self::revision( $request ) ) );
    }

    public static function delete_row( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::respond( $repo->delete_row( absint( $request['id'] ), sanitize_text_field( (string) $request['uuid'] ), self::revision( $request ) ) );
    }

    public static function save_node( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::respond( $repo->save_node( absint( $request['id'] ), (array) $request->get_param( 'node' ), self::revision( $request ) ) );
    }

    public static function delete_node( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::respond( $repo->delete_node( absint( $request['id'] ), sanitize_text_field( (string) $request['uuid'] ), self::revision( $request ) ) );
    }

    public static function save_relation( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::respond( $repo->save_edge( absint( $request['id'] ), (array) $request->get_param( 'relation' ), self::revision( $request ) ) );
    }

    public static function delete_relation( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::respond( $repo->delete_edge( absint( $request['id'] ), sanitize_text_field( (string) $request['uuid'] ), self::revision( $request ) ) );
    }

    public static function revisions( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return rest_ensure_response( $repo->revisions( absint( $request['id'] ) ) );
    }

    public static function restore_revision( WP_REST_Request $request ) {
        $repo = new DatasetRepository();
        return self::respond(
            $repo->restore_revision(
                absint( $request['id'] ),
                absint( $request['revision'] ),
                self::revision( $request )
            )
        );
    }

    public static function commerce_snapshot( WP_REST_Request $request ) {
        $repo    = new DatasetRepository();
        $id      = absint( $request['id'] );
        $dataset = $repo->get( $id );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( 'graph' === $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_snapshot_schema', __( 'WooCommerce snapshots require a row-based dataset.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $query  = new SalesQuery();
        $result = $query->query( (array) $request->get_param( 'config' ), false );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        $payload = RowWriteGuard::normalize_payload( (string) $dataset['schema_type'], array( 'rows' => $result['rows'] ) );
        if ( is_wp_error( $payload ) ) {
            return new WP_Error(
                'viswiz_snapshot_schema',
                __( 'The WooCommerce snapshot does not provide the fields required by this dataset schema.', 'viswiz' ),
                array( 'status' => 422, 'schema' => (string) $dataset['schema_type'], 'issues' => $payload->get_error_data()['issues'] ?? array() )
            );
        }
        return self::respond( $repo->replace_payload( $id, $payload, self::revision( $request ), 'WooCommerce snapshot' ) );
    }

    private static function revision( WP_REST_Request $request ): ?int {
        $revision = $request->get_param( 'expected_revision' );
        return null === $revision || '' === $revision ? null : absint( $revision );
    }

    private static function respond( $result ) {
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }
}
