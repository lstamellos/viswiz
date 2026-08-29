<?php
namespace VisWiz\Domain;

use WP_Error;

final class RowWriteGuard {
    public static function normalize_row( string $schema, array $row ) {
        return RowSchema::normalize_for_editor( $schema, $row );
    }

    public static function normalize_payload( string $schema, array $payload ) {
        if ( 'graph' === $schema || ! Registry::schema_exists( $schema ) ) {
            return new WP_Error(
                'viswiz_row_schema_invalid',
                __( 'A valid row dataset schema is required.', 'viswiz' ),
                array( 'status' => 400 )
            );
        }

        $rows = array_key_exists( 'rows', $payload ) ? $payload['rows'] : $payload;
        if ( ! is_array( $rows ) ) {
            return new WP_Error(
                'viswiz_row_payload_invalid',
                __( 'The row payload must be an array.', 'viswiz' ),
                array( 'status' => 400 )
            );
        }

        $normalized = array();
        $issues     = array();
        foreach ( array_values( $rows ) as $index => $row ) {
            if ( ! is_array( $row ) ) {
                $issues[] = array(
                    'index'   => $index,
                    'field'   => '',
                    'message' => __( 'Each row must be an object.', 'viswiz' ),
                );
                continue;
            }

            $result = RowSchema::normalize_for_editor( $schema, $row );
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
                'viswiz_row_payload_validation',
                __( 'The row payload does not match the dataset schema.', 'viswiz' ),
                array( 'status' => 422, 'issues' => $issues )
            );
        }

        return array( 'rows' => $normalized );
    }
}
