<?php
namespace VisWiz\Domain;

use WP_Error;

final class RowSchema {
    public static function normalize_for_editor( string $schema, array $row ) {
        if ( 'graph' === $schema || ! Registry::schema_exists( $schema ) ) {
            return new WP_Error( 'viswiz_row_schema_invalid', __( 'A valid row dataset schema is required.', 'viswiz' ), array( 'status' => 400 ) );
        }

        $row['meta'] = is_array( $row['meta'] ?? null ) ? $row['meta'] : array();

        if ( 'time_series' === $schema ) {
            $x_value = trim( (string) ( $row['x_value'] ?? '' ) );
            if ( '' !== $x_value ) {
                $date = date_create_immutable( $x_value, wp_timezone() );
                if ( false === $date ) {
                    return self::field_error( 'x_value', __( 'Date / time must be a valid date or time value.', 'viswiz' ) );
                }
                $row['x_numeric'] = (float) $date->getTimestamp();
            }
        }

        if ( 'progress' === $schema ) {
            if ( array_key_exists( 'target', $row['meta'] ) ) {
                if ( '' === $row['meta']['target'] || null === $row['meta']['target'] ) {
                    unset( $row['meta']['target'] );
                } elseif ( ! is_numeric( $row['meta']['target'] ) ) {
                    return self::field_error( 'meta.target', __( 'Target must be a number.', 'viswiz' ) );
                } else {
                    $row['meta']['target'] = (float) $row['meta']['target'];
                }
            }
            if ( array_key_exists( 'text', $row['meta'] ) ) {
                $row['meta']['text'] = sanitize_textarea_field( (string) $row['meta']['text'] );
            }
        }

        if ( 'diagram' === $schema && array_key_exists( 'text', $row['meta'] ) ) {
            $row['meta']['text'] = sanitize_textarea_field( (string) $row['meta']['text'] );
        }

        $required = array(
            'categorical' => array( 'label', 'value' ),
            'time_series' => array( 'x_value', 'value' ),
            'xy'          => array( 'x_numeric', 'y_value' ),
            'geo'         => array( 'latitude', 'longitude' ),
            'progress'    => array( 'label', 'value' ),
            'diagram'     => array( 'label' ),
        );
        foreach ( $required[ $schema ] ?? array() as $field ) {
            $value = $row[ $field ] ?? null;
            if ( null === $value || ( is_string( $value ) && '' === trim( $value ) ) ) {
                return self::field_error(
                    $field,
                    sprintf( __( '“%s” is required for this dataset schema.', 'viswiz' ), self::field_label( $schema, $field ) )
                );
            }
        }

        foreach ( self::numeric_fields( $schema ) as $field ) {
            if ( ! array_key_exists( $field, $row ) || null === $row[ $field ] || '' === $row[ $field ] ) {
                continue;
            }
            if ( ! is_numeric( $row[ $field ] ) ) {
                return self::field_error( $field, sprintf( __( '“%s” must be a number.', 'viswiz' ), self::field_label( $schema, $field ) ) );
            }
            $row[ $field ] = (float) $row[ $field ];
        }

        if ( isset( $row['latitude'] ) && ( (float) $row['latitude'] < -90 || (float) $row['latitude'] > 90 ) ) {
            return self::field_error( 'latitude', __( 'Latitude must be between -90 and 90.', 'viswiz' ) );
        }
        if ( isset( $row['longitude'] ) && ( (float) $row['longitude'] < -180 || (float) $row['longitude'] > 180 ) ) {
            return self::field_error( 'longitude', __( 'Longitude must be between -180 and 180.', 'viswiz' ) );
        }

        return $row;
    }

    private static function numeric_fields( string $schema ): array {
        return array(
            'categorical' => array( 'value' ),
            'time_series' => array( 'value', 'x_numeric' ),
            'xy'          => array( 'x_numeric', 'y_value' ),
            'geo'         => array( 'latitude', 'longitude', 'value' ),
            'progress'    => array( 'value' ),
            'diagram'     => array(),
        )[ $schema ] ?? array();
    }

    private static function field_label( string $schema, string $field ): string {
        $editor = Registry::schemas()[ $schema ]['editor']['fields'] ?? array();
        foreach ( $editor as $definition ) {
            if ( $field === (string) ( $definition['path'] ?? '' ) ) {
                return (string) ( $definition['label'] ?? $field );
            }
        }
        return $field;
    }

    private static function field_error( string $field, string $message ): WP_Error {
        return new WP_Error( 'viswiz_invalid_row_schema', $message, array( 'status' => 422, 'field' => $field ) );
    }
}
