<?php
namespace VisWiz\Domain;

use WP_Error;

final class RowWriteGuard {
    public static function normalize_row( string $schema, array $row ) {
        return RowSchema::normalize_for_editor( $schema, self::canonicalize_aliases( $schema, $row ) );
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

            $row    = self::canonicalize_aliases( $schema, $row );
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

    private static function canonicalize_aliases( string $schema, array $row ): array {
        if ( 'time_series' === $schema && ! array_key_exists( 'x_value', $row ) && array_key_exists( 'x', $row ) ) {
            $row['x_value'] = $row['x'];
        }

        if ( 'xy' === $schema ) {
            if ( ! array_key_exists( 'x_numeric', $row ) && array_key_exists( 'x', $row ) ) {
                $row['x_numeric'] = $row['x'];
            }
            if ( ! array_key_exists( 'y_value', $row ) && array_key_exists( 'y', $row ) ) {
                $row['y_value'] = $row['y'];
            }
        }

        if ( 'geo' === $schema ) {
            if ( ! array_key_exists( 'latitude', $row ) && array_key_exists( 'lat', $row ) ) {
                $row['latitude'] = $row['lat'];
            }
            if ( ! array_key_exists( 'longitude', $row ) && array_key_exists( 'lng', $row ) ) {
                $row['longitude'] = $row['lng'];
            }
        }

        return $row;
    }
}
