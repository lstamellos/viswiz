<?php
namespace VisWiz\Domain;

final class GraphValidator {
    public static function validate( array $payload, array $node_types = array(), array $relation_types = array() ): array {
        $issues      = array();
        $nodes       = (array) ( $payload['nodes'] ?? array() );
        $relations   = (array) ( $payload['relations'] ?? $payload['links'] ?? array() );
        $uuid_lookup = array();
        $slug_lookup = array();

        foreach ( $nodes as $index => $node ) {
            if ( ! is_array( $node ) ) {
                $issues[] = self::issue( 'error', 'invalid_node', 'Node #' . ( $index + 1 ) . ' is not an object.' );
                continue;
            }
            $uuid = (string) ( $node['uuid'] ?? '' );
            $slug = (string) ( $node['slug'] ?? $node['id'] ?? '' );
            if ( '' === $uuid ) {
                $issues[] = self::issue( 'error', 'missing_node_uuid', 'A node is missing its immutable UUID.' );
            } elseif ( isset( $uuid_lookup[ $uuid ] ) ) {
                $issues[] = self::issue( 'error', 'duplicate_node_uuid', 'Duplicate node UUID: ' . $uuid );
            } else {
                $uuid_lookup[ $uuid ] = $node;
            }
            if ( '' === $slug ) {
                $issues[] = self::issue( 'error', 'missing_node_slug', 'A node is missing its readable slug.' );
            } elseif ( isset( $slug_lookup[ $slug ] ) ) {
                $issues[] = self::issue( 'error', 'duplicate_node_slug', 'Duplicate node slug: ' . $slug );
            } else {
                $slug_lookup[ $slug ] = true;
            }
            if ( '' === trim( (string) ( $node['title'] ?? '' ) ) ) {
                $issues[] = self::issue( 'error', 'missing_node_title', 'Node ' . ( $slug ?: '#' . ( $index + 1 ) ) . ' has no title.' );
            }
            $type = (string) ( $node['node_type'] ?? '' );
            if ( '' === $type ) {
                $issues[] = self::issue( 'error', 'missing_node_type', 'Node ' . ( $slug ?: '#' . ( $index + 1 ) ) . ' has no type.' );
            } elseif ( $node_types && ! isset( $node_types[ $type ] ) ) {
                $issues[] = self::issue( 'warning', 'unknown_node_type', 'Node ' . ( $slug ?: '#' . ( $index + 1 ) ) . ' uses unknown type ' . $type . '.' );
            }
        }

        foreach ( $relations as $index => $relation ) {
            if ( ! is_array( $relation ) ) {
                $issues[] = self::issue( 'error', 'invalid_relation', 'Relation #' . ( $index + 1 ) . ' is not an object.' );
                continue;
            }
            $from = (string) ( $relation['from_node_uuid'] ?? '' );
            $to   = (string) ( $relation['to_node_uuid'] ?? '' );
            if ( '' === $from || '' === $to ) {
                $issues[] = self::issue( 'error', 'missing_relation_endpoint', 'Relation #' . ( $index + 1 ) . ' is missing an endpoint.' );
                continue;
            }
            if ( ! isset( $uuid_lookup[ $from ] ) ) {
                $issues[] = self::issue( 'error', 'missing_relation_source', 'Relation #' . ( $index + 1 ) . ' points from a missing node.' );
            }
            if ( ! isset( $uuid_lookup[ $to ] ) ) {
                $issues[] = self::issue( 'error', 'missing_relation_target', 'Relation #' . ( $index + 1 ) . ' points to a missing node.' );
            }
            $type = (string) ( $relation['relation_type'] ?? '' );
            if ( '' !== $type && $relation_types && ! isset( $relation_types[ $type ] ) ) {
                $issues[] = self::issue( 'warning', 'unknown_relation_type', 'Relation #' . ( $index + 1 ) . ' uses unknown type ' . $type . '.' );
                continue;
            }
            if ( '' === $type || ! isset( $relation_types[ $type ], $uuid_lookup[ $from ], $uuid_lookup[ $to ] ) ) {
                continue;
            }
            $schema = $relation_types[ $type ];
            $source = $uuid_lookup[ $from ];
            $target = $uuid_lookup[ $to ];
            if ( ! empty( $schema['source_type'] ) && $schema['source_type'] !== ( $source['node_type'] ?? '' ) ) {
                $issues[] = self::issue( 'warning', 'relation_source_type_mismatch', 'Relation ' . $type . ' has an unexpected source node type.' );
            }
            if ( ! empty( $schema['target_type'] ) && $schema['target_type'] !== ( $target['node_type'] ?? '' ) ) {
                $issues[] = self::issue( 'warning', 'relation_target_type_mismatch', 'Relation ' . $type . ' has an unexpected target node type.' );
            }
            if ( ! empty( $schema['source_subtype'] ) && $schema['source_subtype'] !== ( $source['node_subtype'] ?? '' ) ) {
                $issues[] = self::issue( 'warning', 'relation_source_subtype_mismatch', 'Relation ' . $type . ' has an unexpected source subtype.' );
            }
            if ( ! empty( $schema['target_subtype'] ) && $schema['target_subtype'] !== ( $target['node_subtype'] ?? '' ) ) {
                $issues[] = self::issue( 'warning', 'relation_target_subtype_mismatch', 'Relation ' . $type . ' has an unexpected target subtype.' );
            }
        }
        return $issues;
    }

    private static function issue( string $severity, string $code, string $message ): array {
        return compact( 'severity', 'code', 'message' );
    }
}
