<?php
namespace VisWiz\Import;

use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\GraphValidator;
use VisWiz\Domain\Registry;
use VisWiz\Support;
use WP_Error;

final class DatasetImporter {
    private const IMPORT_KEY = '_viswiz_import_key';
    private const MAX_RECORDS = 20000;

    public function preview( int $dataset_id, array $args ) {
        $prepared = $this->prepare( $dataset_id, $args );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }
        unset( $prepared['payload'] );
        return $prepared;
    }

    public function commit( int $dataset_id, array $args, ?int $expected_revision = null ) {
        $prepared = $this->prepare( $dataset_id, $args );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }
        if ( ! empty( $prepared['errors'] ) ) {
            return new WP_Error(
                'viswiz_import_validation_failed',
                __( 'The import contains validation errors.', 'viswiz' ),
                array( 'status' => 422, 'preview' => $this->without_payload( $prepared ) )
            );
        }

        $repo = new DatasetRepository();
        $note = sprintf(
            'Delimited import: %s %s (%d source records)',
            (string) $prepared['mode'],
            (string) $prepared['kind'],
            (int) $prepared['summary']['source_records']
        );
        $response = $repo->replace_payload( $dataset_id, $prepared['payload'], $expected_revision, $note );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $response['import'] = $this->without_payload( $prepared );
        return $response;
    }

    private function prepare( int $dataset_id, array $args ) {
        $repo    = new DatasetRepository();
        $dataset = $repo->get( $dataset_id );
        if ( ! $dataset ) {
            return new WP_Error( 'viswiz_dataset_not_found', __( 'Dataset not found.', 'viswiz' ), array( 'status' => 404 ) );
        }

        $mode = sanitize_key( (string) ( $args['mode'] ?? 'append' ) );
        if ( ! in_array( $mode, array( 'append', 'upsert', 'replace' ), true ) ) {
            return new WP_Error( 'viswiz_import_mode', __( 'Unsupported import mode.', 'viswiz' ), array( 'status' => 400 ) );
        }
        $kind = sanitize_key( (string) ( $args['kind'] ?? ( 'graph' === $dataset['schema_type'] ? 'nodes' : 'rows' ) ) );
        $allowed = 'graph' === $dataset['schema_type'] ? array( 'nodes', 'relations' ) : array( 'rows' );
        if ( ! in_array( $kind, $allowed, true ) ) {
            return new WP_Error( 'viswiz_import_kind', __( 'This import type does not match the dataset schema.', 'viswiz' ), array( 'status' => 400 ) );
        }

        $records = is_array( $args['records'] ?? null ) ? array_values( $args['records'] ) : array();
        if ( ! $records ) {
            return new WP_Error( 'viswiz_import_empty', __( 'No import records were supplied.', 'viswiz' ), array( 'status' => 400 ) );
        }
        if ( count( $records ) > self::MAX_RECORDS ) {
            return new WP_Error(
                'viswiz_import_too_large',
                sprintf( __( 'A single import is limited to %d records.', 'viswiz' ), self::MAX_RECORDS ),
                array( 'status' => 413 )
            );
        }

        $mapping = $this->sanitize_mapping( $args['mapping'] ?? array() );
        $payload = $repo->get_payload( $dataset_id );

        if ( 'rows' === $kind ) {
            return $this->prepare_rows( $dataset, $payload, $records, $mapping, $mode );
        }
        if ( 'nodes' === $kind ) {
            return $this->prepare_nodes( $dataset, $payload, $records, $mapping, $mode );
        }
        return $this->prepare_relations( $dataset, $payload, $records, $mapping, $mode );
    }

    private function prepare_rows( array $dataset, array $payload, array $records, array $mapping, string $mode ): array {
        $existing = array_values( (array) ( $payload['rows'] ?? array() ) );
        $errors   = array();
        $warnings = array();
        $preview  = array();
        $incoming = array();
        $seen_keys = array();
        $schema = (string) $dataset['schema_type'];

        if ( 'upsert' === $mode && empty( $mapping['row_key'] ) ) {
            $errors[] = $this->issue( 0, 'row_key', __( 'Upsert requires a mapped row key.', 'viswiz' ) );
        }

        foreach ( $records as $index => $record ) {
            if ( ! is_array( $record ) ) {
                $errors[] = $this->issue( $index + 2, '', __( 'The source row is invalid.', 'viswiz' ) );
                continue;
            }
            $mapped = $this->mapped_values( $record, $mapping );
            $row_errors = array();
            $mapped = $this->normalize_row_values( $mapped, $index + 2, $schema, $row_errors );
            $errors = array_merge( $errors, $row_errors );
            $key = (string) ( $mapped['row_key'] ?? '' );
            if ( '' !== $key && isset( $seen_keys[ $key ] ) ) {
                $errors[] = $this->issue( $index + 2, 'row_key', sprintf( __( 'Duplicate import key “%s”.', 'viswiz' ), $key ) );
            }
            if ( '' !== $key ) {
                $seen_keys[ $key ] = true;
            }
            $incoming[] = array( 'source_row' => $index + 2, 'values' => $mapped );
        }

        $existing_by_key = array();
        foreach ( $existing as $row ) {
            $key = sanitize_key( (string) ( $row['row_key'] ?? '' ) );
            if ( '' !== $key ) {
                if ( isset( $existing_by_key[ $key ] ) ) {
                    $existing_by_key[ $key ] = null;
                } else {
                    $existing_by_key[ $key ] = $row;
                }
            }
        }
        if ( 'upsert' === $mode ) {
            foreach ( $existing_by_key as $key => $row ) {
                if ( null === $row && isset( $seen_keys[ $key ] ) ) {
                    $errors[] = $this->issue( 0, 'row_key', sprintf( __( 'Existing row key “%s” is not unique, so it cannot be used for upsert.', 'viswiz' ), $key ) );
                }
            }
        }

        $created = 0;
        $updated = 0;
        $result_rows = 'replace' === $mode ? array() : $existing;
        $positions = array();
        foreach ( $result_rows as $position => $row ) {
            $key = sanitize_key( (string) ( $row['row_key'] ?? '' ) );
            if ( '' !== $key && ! isset( $positions[ $key ] ) ) {
                $positions[ $key ] = $position;
            }
        }

        foreach ( $incoming as $item ) {
            $values = $item['values'];
            $key = (string) ( $values['row_key'] ?? '' );
            $action = 'create';
            if ( 'upsert' === $mode && '' !== $key && isset( $existing_by_key[ $key ] ) && is_array( $existing_by_key[ $key ] ) ) {
                $action = 'update';
                $merged = array_replace( $existing_by_key[ $key ], $values );
                $position = $positions[ $key ] ?? null;
                if ( null !== $position ) {
                    $result_rows[ $position ] = $merged;
                }
                ++$updated;
            } else {
                $result_rows[] = $this->new_row( $values );
                ++$created;
            }
            $preview[] = array(
                'source_row' => $item['source_row'],
                'action'     => $action,
                'key'        => $key,
                'label'      => (string) ( $values['label'] ?? '' ),
            );
        }

        $removed = 'replace' === $mode ? count( $existing ) : 0;
        if ( $removed > 0 ) {
            $warnings[] = sprintf( _n( '%d existing row will be replaced.', '%d existing rows will be replaced.', $removed, 'viswiz' ), $removed );
        }

        return array(
            'kind'     => 'rows',
            'mode'     => $mode,
            'schema'   => $schema,
            'summary'  => array(
                'source_records' => count( $records ),
                'created'        => $created,
                'updated'        => $updated,
                'removed'        => $removed,
                'final_rows'     => count( $result_rows ),
            ),
            'errors'   => $errors,
            'warnings' => $warnings,
            'preview'  => array_slice( $preview, 0, 50 ),
            'payload'  => array( 'rows' => $result_rows ),
        );
    }

    private function prepare_nodes( array $dataset, array $payload, array $records, array $mapping, string $mode ): array {
        $existing_nodes = array_values( (array) ( $payload['nodes'] ?? array() ) );
        $existing_relations = array_values( (array) ( $payload['relations'] ?? array() ) );
        $errors = array();
        $warnings = array();
        $preview = array();
        $incoming = array();
        $seen_keys = array();

        if ( 'upsert' === $mode && empty( $mapping['external_key'] ) ) {
            $errors[] = $this->issue( 0, 'external_key', __( 'Node upsert requires a mapped external key.', 'viswiz' ) );
        }

        foreach ( $records as $index => $record ) {
            if ( ! is_array( $record ) ) {
                $errors[] = $this->issue( $index + 2, '', __( 'The source row is invalid.', 'viswiz' ) );
                continue;
            }
            $values = $this->mapped_values( $record, $mapping );
            $row_errors = array();
            $values = $this->normalize_node_values( $values, $index + 2, $row_errors );
            $errors = array_merge( $errors, $row_errors );
            $key = (string) ( $values['external_key'] ?? '' );
            if ( '' !== $key && isset( $seen_keys[ $key ] ) ) {
                $errors[] = $this->issue( $index + 2, 'external_key', sprintf( __( 'Duplicate external key “%s”.', 'viswiz' ), $key ) );
            }
            if ( '' !== $key ) {
                $seen_keys[ $key ] = true;
            }
            $incoming[] = array( 'source_row' => $index + 2, 'values' => $values );
        }

        $index = $this->node_index( $existing_nodes );
        $result_nodes = 'replace' === $mode ? array() : $existing_nodes;
        $positions = array();
        foreach ( $result_nodes as $position => $node ) {
            foreach ( $this->node_keys( $node ) as $key ) {
                if ( '' !== $key && ! isset( $positions[ $key ] ) ) {
                    $positions[ $key ] = $position;
                }
            }
        }

        $created = 0;
        $updated = 0;
        foreach ( $incoming as $item ) {
            $values = $item['values'];
            $key = (string) ( $values['external_key'] ?? '' );
            $match = '' !== $key && isset( $index[ $key ] ) && is_array( $index[ $key ] ) ? $index[ $key ] : null;
            $action = 'create';
            if ( $match && in_array( $mode, array( 'upsert', 'replace' ), true ) ) {
                $action = 'update';
                $node = $this->merge_node( $match, $values );
                ++$updated;
            } else {
                $node = $this->new_node( $values );
                ++$created;
            }
            if ( 'replace' === $mode ) {
                $result_nodes[] = $node;
            } elseif ( 'update' === $action ) {
                $position = $positions[ $key ] ?? null;
                if ( null !== $position ) {
                    $result_nodes[ $position ] = $node;
                }
            } else {
                $result_nodes[] = $node;
            }
            $preview[] = array(
                'source_row' => $item['source_row'],
                'action'     => $action,
                'key'        => $key,
                'label'      => (string) ( $node['title'] ?? '' ),
            );
        }

        $valid_ids = array_fill_keys( array_map( static fn( array $node ): string => (string) ( $node['uuid'] ?? '' ), $result_nodes ), true );
        $result_relations = $existing_relations;
        $removed_relations = 0;
        if ( 'replace' === $mode ) {
            $result_relations = array_values(
                array_filter(
                    $existing_relations,
                    static function ( array $relation ) use ( $valid_ids, &$removed_relations ): bool {
                        $keep = isset( $valid_ids[ (string) ( $relation['from_node_uuid'] ?? '' ) ] ) && isset( $valid_ids[ (string) ( $relation['to_node_uuid'] ?? '' ) ] );
                        if ( ! $keep ) {
                            ++$removed_relations;
                        }
                        return $keep;
                    }
                )
            );
        }

        $graph = array( 'nodes' => $result_nodes, 'relations' => $result_relations );
        $graph_issues = $this->graph_issues( $graph );
        $errors = array_merge( $errors, $graph_issues['errors'] );
        $warnings = array_merge( $warnings, $graph_issues['warnings'] );
        $removed = 'replace' === $mode ? max( 0, count( $existing_nodes ) - $updated ) : 0;
        if ( $removed > 0 ) {
            $warnings[] = sprintf( _n( '%d existing node will be removed.', '%d existing nodes will be removed.', $removed, 'viswiz' ), $removed );
        }
        if ( $removed_relations > 0 ) {
            $warnings[] = sprintf( _n( '%d relation will be removed because an endpoint is removed.', '%d relations will be removed because an endpoint is removed.', $removed_relations, 'viswiz' ), $removed_relations );
        }

        return array(
            'kind'     => 'nodes',
            'mode'     => $mode,
            'schema'   => 'graph',
            'summary'  => array(
                'source_records'    => count( $records ),
                'created'           => $created,
                'updated'           => $updated,
                'removed'           => $removed,
                'relations_removed' => $removed_relations,
                'final_nodes'       => count( $result_nodes ),
                'final_relations'   => count( $result_relations ),
            ),
            'errors'   => $errors,
            'warnings' => $warnings,
            'preview'  => array_slice( $preview, 0, 50 ),
            'payload'  => $graph,
        );
    }

    private function prepare_relations( array $dataset, array $payload, array $records, array $mapping, string $mode ): array {
        $nodes = array_values( (array) ( $payload['nodes'] ?? array() ) );
        $existing = array_values( (array) ( $payload['relations'] ?? array() ) );
        $errors = array();
        $warnings = array();
        $preview = array();
        $incoming = array();
        $seen_keys = array();
        $node_index = $this->node_index( $nodes );
        $relation_index = $this->relation_index( $existing );

        if ( empty( $mapping['from_key'] ) || empty( $mapping['to_key'] ) ) {
            $errors[] = $this->issue( 0, 'from_key', __( 'Relation import requires mapped From key and To key columns.', 'viswiz' ) );
        }
        if ( 'upsert' === $mode && empty( $mapping['external_key'] ) ) {
            $errors[] = $this->issue( 0, 'external_key', __( 'Relation upsert requires a mapped external key.', 'viswiz' ) );
        }

        foreach ( $records as $index => $record ) {
            if ( ! is_array( $record ) ) {
                $errors[] = $this->issue( $index + 2, '', __( 'The source row is invalid.', 'viswiz' ) );
                continue;
            }
            $values = $this->mapped_values( $record, $mapping );
            $row_errors = array();
            $values = $this->normalize_relation_values( $values, $index + 2, $node_index, $row_errors );
            $errors = array_merge( $errors, $row_errors );
            $key = (string) ( $values['external_key'] ?? '' );
            if ( '' !== $key && isset( $seen_keys[ $key ] ) ) {
                $errors[] = $this->issue( $index + 2, 'external_key', sprintf( __( 'Duplicate external key “%s”.', 'viswiz' ), $key ) );
            }
            if ( '' !== $key ) {
                $seen_keys[ $key ] = true;
            }
            $incoming[] = array( 'source_row' => $index + 2, 'values' => $values );
        }

        $result = 'replace' === $mode ? array() : $existing;
        $positions = array();
        foreach ( $result as $position => $relation ) {
            $key = $this->relation_key( $relation );
            if ( '' !== $key && ! isset( $positions[ $key ] ) ) {
                $positions[ $key ] = $position;
            }
        }

        $created = 0;
        $updated = 0;
        foreach ( $incoming as $item ) {
            $values = $item['values'];
            $key = (string) ( $values['external_key'] ?? '' );
            $match = '' !== $key && isset( $relation_index[ $key ] ) && is_array( $relation_index[ $key ] ) ? $relation_index[ $key ] : null;
            $action = 'create';
            if ( $match && in_array( $mode, array( 'upsert', 'replace' ), true ) ) {
                $action = 'update';
                $relation = $this->merge_relation( $match, $values );
                ++$updated;
            } else {
                $relation = $this->new_relation( $values );
                ++$created;
            }
            if ( 'replace' === $mode ) {
                $result[] = $relation;
            } elseif ( 'update' === $action ) {
                $position = $positions[ $key ] ?? null;
                if ( null !== $position ) {
                    $result[ $position ] = $relation;
                }
            } else {
                $result[] = $relation;
            }
            $preview[] = array(
                'source_row' => $item['source_row'],
                'action'     => $action,
                'key'        => $key,
                'label'      => (string) ( $relation['label'] ?? $relation['relation_type'] ?? '' ),
            );
        }

        $graph = array( 'nodes' => $nodes, 'relations' => $result );
        $graph_issues = $this->graph_issues( $graph );
        $errors = array_merge( $errors, $graph_issues['errors'] );
        $warnings = array_merge( $warnings, $graph_issues['warnings'] );
        $removed = 'replace' === $mode ? max( 0, count( $existing ) - $updated ) : 0;
        if ( $removed > 0 ) {
            $warnings[] = sprintf( _n( '%d existing relation will be removed.', '%d existing relations will be removed.', $removed, 'viswiz' ), $removed );
        }

        return array(
            'kind'     => 'relations',
            'mode'     => $mode,
            'schema'   => 'graph',
            'summary'  => array(
                'source_records'  => count( $records ),
                'created'         => $created,
                'updated'         => $updated,
                'removed'         => $removed,
                'final_nodes'     => count( $nodes ),
                'final_relations' => count( $result ),
            ),
            'errors'   => $errors,
            'warnings' => $warnings,
            'preview'  => array_slice( $preview, 0, 50 ),
            'payload'  => $graph,
        );
    }

    private function sanitize_mapping( mixed $mapping ): array {
        if ( ! is_array( $mapping ) ) {
            return array();
        }
        $clean = array();
        foreach ( $mapping as $target => $source ) {
            $target = sanitize_key( (string) $target );
            $source = sanitize_text_field( (string) $source );
            if ( '' !== $target && '' !== $source ) {
                $clean[ $target ] = $source;
            }
        }
        return $clean;
    }

    private function mapped_values( array $record, array $mapping ): array {
        $values = array();
        foreach ( $mapping as $target => $source ) {
            if ( array_key_exists( $source, $record ) ) {
                $values[ $target ] = is_scalar( $record[ $source ] ) || null === $record[ $source ] ? (string) ( $record[ $source ] ?? '' ) : '';
            }
        }
        return $values;
    }

    private function normalize_row_values( array $values, int $source_row, string $schema, array &$errors ): array {
        $out = array();
        foreach ( array( 'row_key', 'label', 'x_value', 'color' ) as $field ) {
            if ( array_key_exists( $field, $values ) ) {
                $out[ $field ] = 'row_key' === $field ? sanitize_key( $values[ $field ] ) : sanitize_text_field( $values[ $field ] );
            }
        }
        foreach ( array( 'value', 'x_numeric', 'y_value', 'latitude', 'longitude' ) as $field ) {
            if ( ! array_key_exists( $field, $values ) ) {
                continue;
            }
            $parsed = $this->number( $values[ $field ] );
            if ( null === $parsed && '' !== trim( (string) $values[ $field ] ) ) {
                $errors[] = $this->issue( $source_row, $field, __( 'Expected a number.', 'viswiz' ) );
            }
            $out[ $field ] = $parsed;
        }
        if ( isset( $out['latitude'] ) && null !== $out['latitude'] && ( $out['latitude'] < -90 || $out['latitude'] > 90 ) ) {
            $errors[] = $this->issue( $source_row, 'latitude', __( 'Latitude must be between -90 and 90.', 'viswiz' ) );
        }
        if ( isset( $out['longitude'] ) && null !== $out['longitude'] && ( $out['longitude'] < -180 || $out['longitude'] > 180 ) ) {
            $errors[] = $this->issue( $source_row, 'longitude', __( 'Longitude must be between -180 and 180.', 'viswiz' ) );
        }
        if ( array_key_exists( 'meta', $values ) ) {
            $meta = $this->json_object( $values['meta'] );
            if ( is_wp_error( $meta ) ) {
                $errors[] = $this->issue( $source_row, 'meta', $meta->get_error_message() );
            } else {
                $out['meta'] = $meta;
            }
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
            if ( ! array_key_exists( $field, $out ) || '' === $out[ $field ] || null === $out[ $field ] ) {
                $errors[] = $this->issue( $source_row, $field, sprintf( __( '“%s” is required for this dataset schema.', 'viswiz' ), $field ) );
            }
        }
        return $out;
    }

    private function normalize_node_values( array $values, int $source_row, array &$errors ): array {
        $out = array();
        if ( array_key_exists( 'external_key', $values ) ) {
            $out['external_key'] = sanitize_key( $values['external_key'] );
            if ( '' === $out['external_key'] ) {
                $errors[] = $this->issue( $source_row, 'external_key', __( 'External keys must contain URL-safe letters, numbers, dashes or underscores.', 'viswiz' ) );
            }
        }
        foreach ( array( 'slug', 'title', 'label', 'description' ) as $field ) {
            if ( array_key_exists( $field, $values ) ) {
                $out[ $field ] = 'description' === $field ? wp_kses_post( $values[ $field ] ) : sanitize_text_field( $values[ $field ] );
            }
        }
        if ( isset( $out['slug'] ) ) {
            $out['slug'] = sanitize_title( $out['slug'] );
        }
        if ( array_key_exists( 'node_type', $values ) ) {
            $out['node_type'] = $this->registry_key( $values['node_type'], Registry::node_types() );
            if ( '' === $out['node_type'] ) {
                $errors[] = $this->issue( $source_row, 'node_type', sprintf( __( 'Unknown node type “%s”.', 'viswiz' ), sanitize_text_field( $values['node_type'] ) ) );
            }
        }
        if ( array_key_exists( 'node_subtype', $values ) ) {
            $type = (string) ( $out['node_type'] ?? '' );
            $node_types = Registry::node_types();
            $subtypes = isset( $node_types[ $type ]['subtypes'] ) ? (array) $node_types[ $type ]['subtypes'] : array();
            $out['node_subtype'] = '' === trim( $values['node_subtype'] ) ? '' : $this->registry_key( $values['node_subtype'], $subtypes, true );
            if ( '' !== trim( $values['node_subtype'] ) && '' === $out['node_subtype'] ) {
                $errors[] = $this->issue( $source_row, 'node_subtype', sprintf( __( 'Unknown node subtype “%s” for this type.', 'viswiz' ), sanitize_text_field( $values['node_subtype'] ) ) );
            }
        }
        if ( array_key_exists( 'main_image_id', $values ) ) {
            $out['main_image_id'] = absint( $values['main_image_id'] );
        }
        if ( array_key_exists( 'other_image_ids', $values ) ) {
            $out['other_image_ids'] = array_values( array_filter( array_map( 'absint', preg_split( '/[\s,;]+/', trim( $values['other_image_ids'] ) ) ?: array() ) ) );
        }
        if ( array_key_exists( 'meta', $values ) ) {
            $meta = $this->json_object( $values['meta'] );
            if ( is_wp_error( $meta ) ) {
                $errors[] = $this->issue( $source_row, 'meta', $meta->get_error_message() );
            } else {
                $out['meta'] = $meta;
            }
        }
        if ( empty( $out['title'] ) ) {
            $errors[] = $this->issue( $source_row, 'title', __( 'Node title is required.', 'viswiz' ) );
        }
        if ( empty( $out['node_type'] ) ) {
            $errors[] = $this->issue( $source_row, 'node_type', __( 'Node type is required.', 'viswiz' ) );
        }
        if ( empty( $out['slug'] ) && ! empty( $out['external_key'] ) ) {
            $out['slug'] = sanitize_title( $out['external_key'] );
        }
        return $out;
    }

    private function normalize_relation_values( array $values, int $source_row, array $node_index, array &$errors ): array {
        $out = array();
        if ( array_key_exists( 'external_key', $values ) ) {
            $out['external_key'] = sanitize_key( $values['external_key'] );
            if ( '' === $out['external_key'] ) {
                $errors[] = $this->issue( $source_row, 'external_key', __( 'External keys must contain URL-safe letters, numbers, dashes or underscores.', 'viswiz' ) );
            }
        }
        foreach ( array( 'from_key', 'to_key' ) as $field ) {
            if ( ! array_key_exists( $field, $values ) || '' === trim( $values[ $field ] ) ) {
                $errors[] = $this->issue( $source_row, $field, __( 'Both relation endpoint keys are required.', 'viswiz' ) );
                continue;
            }
            $key = $this->normalize_reference( $values[ $field ] );
            if ( ! isset( $node_index[ $key ] ) || ! is_array( $node_index[ $key ] ) ) {
                $errors[] = $this->issue( $source_row, $field, sprintf( __( 'No unique node matches “%s”.', 'viswiz' ), sanitize_text_field( $values[ $field ] ) ) );
                continue;
            }
            $out[ 'from_key' === $field ? 'from_node_uuid' : 'to_node_uuid' ] = (string) $node_index[ $key ]['uuid'];
        }
        if ( array_key_exists( 'relation_type', $values ) ) {
            $out['relation_type'] = '' === trim( $values['relation_type'] ) ? '' : $this->registry_key( $values['relation_type'], Registry::relation_types() );
            if ( '' !== trim( $values['relation_type'] ) && '' === $out['relation_type'] ) {
                $errors[] = $this->issue( $source_row, 'relation_type', sprintf( __( 'Unknown relation type “%s”.', 'viswiz' ), sanitize_text_field( $values['relation_type'] ) ) );
            }
        }
        foreach ( array( 'label', 'inverse_label' ) as $field ) {
            if ( array_key_exists( $field, $values ) ) {
                $out[ $field ] = sanitize_text_field( $values[ $field ] );
            }
        }
        if ( array_key_exists( 'direction', $values ) ) {
            $direction = sanitize_key( $values['direction'] );
            if ( ! in_array( $direction, array( 'directed', 'bidirectional', 'undirected' ), true ) ) {
                $errors[] = $this->issue( $source_row, 'direction', __( 'Direction must be directed, bidirectional or undirected.', 'viswiz' ) );
            } else {
                $out['direction'] = $direction;
            }
        }
        if ( array_key_exists( 'intensity', $values ) ) {
            $number = $this->number( $values['intensity'] );
            if ( null === $number || $number < 0.1 || $number > 20 ) {
                $errors[] = $this->issue( $source_row, 'intensity', __( 'Intensity must be a number between 0.1 and 20.', 'viswiz' ) );
            } else {
                $out['intensity'] = $number;
            }
        }
        if ( array_key_exists( 'meta', $values ) ) {
            $meta = $this->json_object( $values['meta'] );
            if ( is_wp_error( $meta ) ) {
                $errors[] = $this->issue( $source_row, 'meta', $meta->get_error_message() );
            } else {
                $out['meta'] = $meta;
            }
        }
        return $out;
    }

    private function new_row( array $values ): array {
        return array_replace(
            array(
                'row_key' => '', 'label' => '', 'value' => null, 'x_value' => '', 'x_numeric' => null,
                'y_value' => null, 'latitude' => null, 'longitude' => null, 'color' => '', 'meta' => array(),
            ),
            $values
        );
    }

    private function new_node( array $values ): array {
        $external_key = (string) ( $values['external_key'] ?? '' );
        unset( $values['external_key'] );
        $node = array_replace(
            array(
                'uuid' => Support::uuid(), 'slug' => '', 'title' => '', 'label' => '', 'node_type' => '', 'node_subtype' => '', 'description' => '',
                'main_image_id' => 0, 'other_image_ids' => array(), 'meta' => array(),
            ),
            $values
        );
        if ( '' === $node['label'] ) {
            $node['label'] = $node['title'];
        }
        if ( '' !== $external_key ) {
            $node['meta'][ self::IMPORT_KEY ] = $external_key;
        }
        return $node;
    }

    private function merge_node( array $existing, array $values ): array {
        $external_key = (string) ( $values['external_key'] ?? $this->node_import_key( $existing ) );
        unset( $values['external_key'] );
        $node = array_replace( $existing, $values );
        if ( '' !== $external_key ) {
            $node['meta'] = is_array( $node['meta'] ?? null ) ? $node['meta'] : array();
            $node['meta'][ self::IMPORT_KEY ] = $external_key;
        }
        return $node;
    }

    private function new_relation( array $values ): array {
        $external_key = (string) ( $values['external_key'] ?? '' );
        unset( $values['external_key'], $values['from_key'], $values['to_key'] );
        $relation = array_replace(
            array(
                'uuid' => Support::uuid(), 'from_node_uuid' => '', 'to_node_uuid' => '', 'relation_type' => '', 'label' => '', 'inverse_label' => '',
                'direction' => 'directed', 'intensity' => 1, 'meta' => array(),
            ),
            $values
        );
        if ( '' !== $external_key ) {
            $relation['meta'][ self::IMPORT_KEY ] = $external_key;
        }
        return $relation;
    }

    private function merge_relation( array $existing, array $values ): array {
        $external_key = (string) ( $values['external_key'] ?? $this->relation_key( $existing ) );
        unset( $values['external_key'], $values['from_key'], $values['to_key'] );
        $relation = array_replace( $existing, $values );
        if ( '' !== $external_key ) {
            $relation['meta'] = is_array( $relation['meta'] ?? null ) ? $relation['meta'] : array();
            $relation['meta'][ self::IMPORT_KEY ] = $external_key;
        }
        return $relation;
    }

    private function node_index( array $nodes ): array {
        $index = array();
        foreach ( $nodes as $node ) {
            foreach ( $this->node_keys( $node ) as $key ) {
                if ( '' === $key ) {
                    continue;
                }
                if ( isset( $index[ $key ] ) ) {
                    $index[ $key ] = null;
                } else {
                    $index[ $key ] = $node;
                }
            }
        }
        return $index;
    }

    private function node_keys( array $node ): array {
        return array_values(
            array_unique(
                array_filter(
                    array(
                        $this->normalize_reference( (string) ( $node['uuid'] ?? '' ) ),
                        $this->normalize_reference( (string) ( $node['slug'] ?? '' ) ),
                        $this->normalize_reference( $this->node_import_key( $node ) ),
                    )
                )
            )
        );
    }

    private function node_import_key( array $node ): string {
        $meta = is_array( $node['meta'] ?? null ) ? $node['meta'] : array();
        return sanitize_key( (string) ( $meta[ self::IMPORT_KEY ] ?? '' ) );
    }

    private function relation_index( array $relations ): array {
        $index = array();
        foreach ( $relations as $relation ) {
            $key = $this->relation_key( $relation );
            if ( '' === $key ) {
                continue;
            }
            if ( isset( $index[ $key ] ) ) {
                $index[ $key ] = null;
            } else {
                $index[ $key ] = $relation;
            }
        }
        return $index;
    }

    private function relation_key( array $relation ): string {
        $meta = is_array( $relation['meta'] ?? null ) ? $relation['meta'] : array();
        return sanitize_key( (string) ( $meta[ self::IMPORT_KEY ] ?? '' ) );
    }

    private function registry_key( string $value, array $registry, bool $values_are_labels = false ): string {
        $needle = sanitize_key( $value );
        if ( '' !== $needle && array_key_exists( $needle, $registry ) ) {
            return $needle;
        }
        foreach ( $registry as $key => $item ) {
            $label = $values_are_labels ? (string) $item : ( is_array( $item ) ? (string) ( $item['label'] ?? $key ) : (string) $item );
            if ( 0 === strcasecmp( trim( $value ), trim( $label ) ) ) {
                return sanitize_key( (string) $key );
            }
        }
        return '';
    }

    private function normalize_reference( string $value ): string {
        $value = strtolower( trim( $value ) );
        return Support::is_uuid( $value ) ? $value : sanitize_key( $value );
    }

    private function graph_issues( array $graph ): array {
        $issues = GraphValidator::validate( $graph, Registry::node_types(), Registry::relation_types() );
        $out = array( 'errors' => array(), 'warnings' => array() );
        foreach ( $issues as $issue ) {
            $message = (string) ( $issue['message'] ?? __( 'Graph integrity issue.', 'viswiz' ) );
            if ( 'error' === ( $issue['severity'] ?? 'error' ) ) {
                $out['errors'][] = $this->issue( 0, 'graph', $message );
            } else {
                $out['warnings'][] = $message;
            }
        }
        return $out;
    }

    private function json_object( string $value ) {
        $value = trim( $value );
        if ( '' === $value ) {
            return array();
        }
        $decoded = json_decode( $value, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            return new WP_Error( 'viswiz_import_meta_json', __( 'Metadata must be a valid JSON object.', 'viswiz' ) );
        }
        return $decoded;
    }

    private function number( string $value ): ?float {
        $value = trim( str_replace( array( "\xc2\xa0", ' ' ), '', $value ) );
        if ( '' === $value ) {
            return null;
        }
        $comma = strrpos( $value, ',' );
        $dot   = strrpos( $value, '.' );
        if ( false !== $comma && false !== $dot ) {
            if ( $comma > $dot ) {
                $value = str_replace( '.', '', $value );
                $value = str_replace( ',', '.', $value );
            } else {
                $value = str_replace( ',', '', $value );
            }
        } elseif ( false !== $comma ) {
            $value = str_replace( ',', '.', $value );
        }
        return is_numeric( $value ) ? (float) $value : null;
    }

    private function issue( int $row, string $field, string $message ): array {
        return array( 'row' => $row, 'field' => $field, 'message' => $message );
    }

    private function without_payload( array $prepared ): array {
        unset( $prepared['payload'] );
        return $prepared;
    }
}
