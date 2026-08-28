<?php
namespace VisWiz\Import;

use VisWiz\Database\DatasetRepository;
use VisWiz\Support;
use WP_Error;

final class ImportGuard {
    private const IMPORT_KEY = '_viswiz_import_key';

    public static function validate( int $dataset_id, array $args ) {
        $mode = sanitize_key( (string) ( $args['mode'] ?? 'append' ) );
        if ( ! in_array( $mode, array( 'append', 'upsert' ), true ) ) {
            return true;
        }

        $repo = new DatasetRepository();
        $dataset = $repo->get( $dataset_id );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }

        $kind = sanitize_key( (string) ( $args['kind'] ?? ( 'graph' === $dataset['schema_type'] ? 'nodes' : 'rows' ) ) );
        $mapping = is_array( $args['mapping'] ?? null ) ? $args['mapping'] : array();
        $records = is_array( $args['records'] ?? null ) ? $args['records'] : array();
        $target = 'rows' === $kind ? 'row_key' : 'external_key';
        $source = sanitize_text_field( (string) ( $mapping[ $target ] ?? '' ) );
        $issues = array();

        if ( 'upsert' === $mode && '' === $source ) {
            $issues[] = self::issue( 0, $target, 'rows' === $kind ? __( 'Upsert requires a mapped row key.', 'viswiz' ) : __( 'Upsert requires a mapped external key.', 'viswiz' ) );
            return self::error( $issues );
        }

        $existing = 'append' === $mode ? self::existing_keys( $repo->get_payload( $dataset_id ), $kind ) : array();
        foreach ( $records as $index => $record ) {
            if ( ! is_array( $record ) || '' === $source ) {
                continue;
            }
            $raw = trim( (string) ( $record[ $source ] ?? '' ) );
            $key = self::normalize_key( $raw );
            if ( 'upsert' === $mode && '' === $key ) {
                $issues[] = self::issue(
                    $index + 2,
                    $target,
                    'rows' === $kind ? __( 'Every upsert record needs a non-empty row key.', 'viswiz' ) : __( 'Every upsert record needs a non-empty external key.', 'viswiz' )
                );
                continue;
            }
            if ( 'append' === $mode && '' !== $key && isset( $existing[ $key ] ) ) {
                $issues[] = self::issue(
                    $index + 2,
                    $target,
                    sprintf( __( 'Key “%s” already exists. Use upsert to update it.', 'viswiz' ), sanitize_text_field( $raw ) )
                );
            }
        }

        return $issues ? self::error( $issues ) : true;
    }

    private static function existing_keys( array $payload, string $kind ): array {
        $keys = array();
        if ( 'rows' === $kind ) {
            foreach ( (array) ( $payload['rows'] ?? array() ) as $row ) {
                $key = self::normalize_key( (string) ( $row['row_key'] ?? '' ) );
                if ( '' !== $key ) {
                    $keys[ $key ] = true;
                }
            }
            return $keys;
        }

        if ( 'nodes' === $kind ) {
            foreach ( (array) ( $payload['nodes'] ?? array() ) as $node ) {
                $meta = is_array( $node['meta'] ?? null ) ? $node['meta'] : array();
                foreach ( array( $meta[ self::IMPORT_KEY ] ?? '', $node['slug'] ?? '', $node['uuid'] ?? '' ) as $value ) {
                    $key = self::normalize_key( (string) $value );
                    if ( '' !== $key ) {
                        $keys[ $key ] = true;
                    }
                }
            }
            return $keys;
        }

        foreach ( (array) ( $payload['relations'] ?? array() ) as $relation ) {
            $meta = is_array( $relation['meta'] ?? null ) ? $relation['meta'] : array();
            $key = self::normalize_key( (string) ( $meta[ self::IMPORT_KEY ] ?? '' ) );
            if ( '' !== $key ) {
                $keys[ $key ] = true;
            }
        }
        return $keys;
    }

    private static function normalize_key( string $value ): string {
        $value = strtolower( trim( $value ) );
        return Support::is_uuid( $value ) ? $value : sanitize_key( $value );
    }

    private static function issue( int $row, string $field, string $message ): array {
        return array( 'row' => $row, 'field' => $field, 'message' => $message );
    }

    private static function error( array $issues ): WP_Error {
        return new WP_Error(
            'viswiz_import_key_conflict',
            __( 'The import key mapping contains conflicts.', 'viswiz' ),
            array( 'status' => 422, 'issues' => $issues )
        );
    }
}
