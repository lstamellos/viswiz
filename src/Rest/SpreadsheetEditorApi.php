<?php
namespace VisWiz\Rest;

use VisWiz\Database\DatasetRepository;
use VisWiz\Database\RowBatchRepository;
use VisWiz\Domain\RowSchema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class SpreadsheetEditorApi {
    public static function register(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function routes(): void {
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/editor/rows/batch',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'save' ),
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_datasets' ),
                'args'                => array(
                    'id' => array( 'sanitize_callback' => 'absint' ),
                ),
            )
        );
    }

    public static function save( WP_REST_Request $request ) {
        $dataset_id = absint( $request['id'] );
        $repo       = new DatasetRepository();
        $dataset    = $repo->get( $dataset_id );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( 'graph' === $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }

        $rows    = is_array( $request->get_param( 'rows' ) ) ? array_values( $request->get_param( 'rows' ) ) : array();
        $deletes = is_array( $request->get_param( 'delete_uuids' ) ) ? array_values( $request->get_param( 'delete_uuids' ) ) : array();
        if ( count( $rows ) + count( $deletes ) > RowBatchRepository::MAX_BATCH ) {
            return new WP_Error(
                'viswiz_row_batch_too_large',
                sprintf( __( 'A single row edit batch is limited to %d changes.', 'viswiz' ), RowBatchRepository::MAX_BATCH ),
                array( 'status' => 413 )
            );
        }

        $normalized = array();
        $issues     = array();
        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                $issues[] = array(
                    'index'   => $index,
                    'uuid'    => '',
                    'field'   => '',
                    'message' => __( 'Every row change must be an object.', 'viswiz' ),
                );
                continue;
            }
            $result = RowSchema::normalize_for_editor( (string) $dataset['schema_type'], $row );
            if ( is_wp_error( $result ) ) {
                $data = $result->get_error_data();
                $issues[] = array(
                    'index'   => $index,
                    'uuid'    => sanitize_text_field( (string) ( $row['uuid'] ?? '' ) ),
                    'field'   => sanitize_text_field( (string) ( is_array( $data ) ? ( $data['field'] ?? '' ) : '' ) ),
                    'message' => $result->get_error_message(),
                );
                continue;
            }
            $normalized[] = $result;
        }
        if ( $issues ) {
            return new WP_Error(
                'viswiz_row_batch_validation',
                __( 'Some spreadsheet rows contain validation errors.', 'viswiz' ),
                array( 'status' => 422, 'issues' => $issues )
            );
        }

        $expected_revision = $request->get_param( 'expected_revision' );
        $expected_revision = null === $expected_revision || '' === $expected_revision ? null : absint( $expected_revision );
        $batch_repo        = new RowBatchRepository();
        $result            = $batch_repo->save( $dataset_id, $normalized, $deletes, $expected_revision );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return rest_ensure_response( $result );
    }
}
