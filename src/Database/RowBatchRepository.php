<?php
namespace VisWiz\Database;

use VisWiz\Support;
use WP_Error;

final class RowBatchRepository {
    public const MAX_BATCH = 500;

    public function save( int $dataset_id, array $rows, array $delete_uuids, ?int $expected_revision = null ) {
        $dataset_repo = new DatasetRepository();
        $dataset      = $dataset_repo->get( $dataset_id );
        if ( ! $dataset || 'graph' === $dataset['schema_type'] ) {
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 400 ) );
        }

        if ( count( $rows ) + count( $delete_uuids ) > self::MAX_BATCH ) {
            return new WP_Error(
                'viswiz_row_batch_too_large',
                sprintf( __( 'A single row edit batch is limited to %d changes.', 'viswiz' ), self::MAX_BATCH ),
                array( 'status' => 413 )
            );
        }

        $prepared_rows = array();
        $row_uuids     = array();
        foreach ( $rows as $index => $row ) {
            if ( ! is_array( $row ) ) {
                return new WP_Error( 'viswiz_invalid_row_batch', __( 'Every row change must be an object.', 'viswiz' ), array( 'status' => 400, 'index' => $index ) );
            }
            $raw_uuid = strtolower( trim( (string) ( $row['uuid'] ?? '' ) ) );
            if ( '' !== $raw_uuid && ! Support::is_uuid( $raw_uuid ) ) {
                return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid row UUID.', 'viswiz' ), array( 'status' => 400, 'index' => $index ) );
            }
            $clean = $this->sanitize_row( $row );
            if ( isset( $row_uuids[ $clean['uuid'] ] ) ) {
                return new WP_Error( 'viswiz_duplicate_row_batch_uuid', __( 'The same row appears more than once in this edit batch.', 'viswiz' ), array( 'status' => 422, 'uuid' => $clean['uuid'] ) );
            }
            $row_uuids[ $clean['uuid'] ] = true;
            $prepared_rows[]              = $clean;
        }

        $deletes = array();
        foreach ( $delete_uuids as $uuid ) {
            $uuid = strtolower( trim( (string) $uuid ) );
            if ( ! Support::is_uuid( $uuid ) ) {
                return new WP_Error( 'viswiz_invalid_uuid', __( 'Invalid row UUID.', 'viswiz' ), array( 'status' => 400 ) );
            }
            if ( isset( $row_uuids[ $uuid ] ) ) {
                return new WP_Error( 'viswiz_row_batch_overlap', __( 'A row cannot be saved and deleted in the same batch.', 'viswiz' ), array( 'status' => 422, 'uuid' => $uuid ) );
            }
            $deletes[ $uuid ] = true;
        }
        $deletes = array_keys( $deletes );

        if ( ! $prepared_rows && ! $deletes ) {
            return array(
                'dataset'  => $dataset,
                'revision' => (int) $dataset['revision'],
                'saved'    => 0,
                'deleted'  => 0,
            );
        }

        global $wpdb;
        $wpdb->query( 'START TRANSACTION' );
        $datasets_table = Support::table( 'datasets' );
        $locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$datasets_table} WHERE id=%d FOR UPDATE", $dataset_id ), ARRAY_A );
        if ( ! is_array( $locked ) ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }
        if ( null !== $expected_revision && $expected_revision !== (int) $locked['revision'] ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->conflict_error( $locked );
        }
        if ( 'graph' === (string) $locked['schema_type'] ) {
            $wpdb->query( 'ROLLBACK' );
            return new WP_Error( 'viswiz_row_dataset_required', __( 'A non-graph dataset is required.', 'viswiz' ), array( 'status' => 409 ) );
        }

        $rows_table = Support::table( 'rows' );
        $deleted    = 0;
        foreach ( $deletes as $uuid ) {
            $result = $wpdb->delete( $rows_table, array( 'dataset_id' => $dataset_id, 'uuid' => $uuid ) );
            if ( false === $result ) {
                $wpdb->query( 'ROLLBACK' );
                return $this->db_error();
            }
            if ( 0 === $result ) {
                $wpdb->query( 'ROLLBACK' );
                return new WP_Error( 'viswiz_row_not_found', __( 'A row marked for deletion no longer exists.', 'viswiz' ), array( 'status' => 404, 'uuid' => $uuid ) );
            }
            $deleted += $result;
        }

        $next_sort = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(sort_order),-1)+1 FROM {$rows_table} WHERE dataset_id=%d", $dataset_id ) );
        $now       = current_time( 'mysql' );
        $saved     = 0;
        foreach ( $prepared_rows as $row ) {
            $id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rows_table} WHERE dataset_id=%d AND uuid=%s", $dataset_id, $row['uuid'] ) );
            $data = $this->row_db_data( $dataset_id, $row, $now );
            if ( $id ) {
                unset( $data['created_at'] );
                $ok = $wpdb->update( $rows_table, $data, array( 'id' => $id ) );
            } else {
                $data['sort_order'] = $next_sort++;
                $ok                 = $wpdb->insert( $rows_table, $data );
            }
            if ( false === $ok ) {
                $wpdb->query( 'ROLLBACK' );
                return $this->db_error();
            }
            ++$saved;
        }

        $current_revision = (int) $locked['revision'];
        $new_revision     = $current_revision + 1;
        $bumped = $wpdb->update(
            $datasets_table,
            array( 'revision' => $new_revision, 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $dataset_id, 'revision' => $current_revision ),
            array( '%d', '%s' ),
            array( '%d', '%d' )
        );
        if ( 1 !== $bumped ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }

        $snapshot = array( 'rows' => $dataset_repo->get_rows( $dataset_id ) );
        $note = sprintf(
            'Spreadsheet edit: %1$d saved, %2$d deleted',
            $saved,
            $deleted
        );
        if ( ! $this->store_revision( $dataset_id, $new_revision, $snapshot, $note ) ) {
            $wpdb->query( 'ROLLBACK' );
            return $this->db_error();
        }

        $wpdb->query( 'COMMIT' );
        return array(
            'dataset'  => $dataset_repo->get( $dataset_id ),
            'revision' => $new_revision,
            'saved'    => $saved,
            'deleted'  => $deleted,
        );
    }

    private function sanitize_row( array $row ): array {
        $value = array_key_exists( 'value', $row ) && '' !== $row['value'] && null !== $row['value'] ? (float) $row['value'] : null;
        return array(
            'uuid'      => Support::uuid( (string) ( $row['uuid'] ?? '' ) ),
            'row_key'   => sanitize_key( (string) ( $row['row_key'] ?? $row['key'] ?? '' ) ),
            'label'     => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
            'value'     => $value,
            'x_value'   => sanitize_text_field( (string) ( $row['x_value'] ?? $row['x'] ?? '' ) ),
            'x_numeric' => isset( $row['x_numeric'] ) && '' !== $row['x_numeric'] ? (float) $row['x_numeric'] : ( is_numeric( $row['x'] ?? null ) ? (float) $row['x'] : null ),
            'y_value'   => isset( $row['y_value'] ) && '' !== $row['y_value'] ? (float) $row['y_value'] : ( isset( $row['y'] ) && '' !== $row['y'] ? (float) $row['y'] : null ),
            'latitude'  => isset( $row['latitude'] ) && '' !== $row['latitude'] ? max( -90, min( 90, (float) $row['latitude'] ) ) : ( isset( $row['lat'] ) ? max( -90, min( 90, (float) $row['lat'] ) ) : null ),
            'longitude' => isset( $row['longitude'] ) && '' !== $row['longitude'] ? max( -180, min( 180, (float) $row['longitude'] ) ) : ( isset( $row['lng'] ) ? max( -180, min( 180, (float) $row['lng'] ) ) : null ),
            'color'     => Support::sanitize_color( $row['color'] ?? '' ),
            'meta'      => Support::sanitize_meta( $row['meta'] ?? array() ),
        );
    }

    private function row_db_data( int $dataset_id, array $row, string $now ): array {
        return array(
            'uuid'       => $row['uuid'],
            'dataset_id' => $dataset_id,
            'row_key'    => $row['row_key'],
            'label'      => $row['label'],
            'value'      => $row['value'],
            'x_value'    => $row['x_value'],
            'x_numeric'  => $row['x_numeric'],
            'y_value'    => $row['y_value'],
            'latitude'   => $row['latitude'],
            'longitude'  => $row['longitude'],
            'color'      => $row['color'],
            'meta'       => Support::json( $row['meta'] ),
            'created_at' => $now,
            'updated_at' => $now,
        );
    }

    private function store_revision( int $dataset_id, int $revision, array $payload, string $note ): bool {
        global $wpdb;
        $ok = $wpdb->replace(
            Support::table( 'dataset_revisions' ),
            array(
                'dataset_id'    => $dataset_id,
                'revision'      => $revision,
                'snapshot'      => Support::json( $payload ),
                'actor_user_id' => get_current_user_id(),
                'note'          => sanitize_text_field( $note ),
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' )
        );
        return false !== $ok;
    }

    private function conflict_error( array $dataset ): WP_Error {
        return new WP_Error(
            'viswiz_revision_conflict',
            __( 'This dataset changed after the editor was opened. Reload it before saving to avoid overwriting newer work.', 'viswiz' ),
            array( 'status' => 409, 'current_revision' => (int) ( $dataset['revision'] ?? 0 ) )
        );
    }

    private function db_error(): WP_Error {
        global $wpdb;
        return new WP_Error( 'viswiz_database_error', __( 'The dataset could not be saved.', 'viswiz' ), array( 'status' => 500, 'detail' => sanitize_text_field( $wpdb->last_error ) ) );
    }
}
