<?php
namespace VisWiz\Rest;

use VisWiz\Domain\Registry;
use VisWiz\Import\DatasetImporter;
use VisWiz\Import\ImportGuard;
use WP_REST_Request;
use WP_REST_Server;

final class ImportApi {
    public static function register(): void {
        add_action( 'rest_api_init', array( self::class, 'routes' ) );
    }

    public static function routes(): void {
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/import/preview',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'preview' ),
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_datasets' ),
                'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
            )
        );
        register_rest_route(
            'viswiz/v2',
            '/datasets/(?P<id>\d+)/import',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( self::class, 'commit' ),
                'permission_callback' => static fn(): bool => current_user_can( 'edit_viswiz_datasets' ),
                'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
            )
        );
    }

    public static function preview( WP_REST_Request $request ) {
        $id = absint( $request['id'] );
        $args = self::import_args( $request );
        $guard = ImportGuard::validate( $id, $args );
        if ( is_wp_error( $guard ) ) {
            return $guard;
        }
        $importer = new DatasetImporter();
        $result = $importer->preview( $id, $args );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    public static function commit( WP_REST_Request $request ) {
        $id = absint( $request['id'] );
        $args = self::import_args( $request );
        $guard = ImportGuard::validate( $id, $args );
        if ( is_wp_error( $guard ) ) {
            return $guard;
        }
        $revision = $request->get_param( 'expected_revision' );
        $revision = null === $revision || '' === $revision ? null : absint( $revision );
        $importer = new DatasetImporter();
        $result = $importer->commit( $id, $args, $revision );
        return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
    }

    private static function import_args( WP_REST_Request $request ): array {
        $args = array(
            'kind'    => sanitize_key( (string) $request->get_param( 'kind' ) ),
            'mode'    => sanitize_key( (string) $request->get_param( 'mode' ) ),
            'mapping' => (array) $request->get_param( 'mapping' ),
            'records' => (array) $request->get_param( 'records' ),
        );
        return 'relations' === $args['kind'] ? self::apply_relation_defaults( $args ) : $args;
    }

    private static function apply_relation_defaults( array $args ): array {
        $mapping = (array) $args['mapping'];
        $type_source = sanitize_text_field( (string) ( $mapping['relation_type'] ?? '' ) );
        if ( '' === $type_source ) {
            return $args;
        }

        $default_fields = array(
            'label'         => 'label',
            'inverse_label' => 'inverse_label',
            'direction'     => 'direction',
            'intensity'     => 'intensity',
        );
        $synthetic_fields = array();
        foreach ( $default_fields as $target => $registry_key ) {
            if ( ! empty( $mapping[ $target ] ) ) {
                continue;
            }
            $synthetic = '__viswiz_' . $target;
            $mapping[ $target ] = $synthetic;
            $synthetic_fields[ $target ] = array( 'source' => $synthetic, 'registry_key' => $registry_key );
        }
        if ( ! $synthetic_fields ) {
            return $args;
        }

        $relation_types = Registry::relation_types();
        foreach ( (array) $args['records'] as $index => $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            $type = self::relation_type_key( (string) ( $record[ $type_source ] ?? '' ), $relation_types );
            if ( '' === $type || ! isset( $relation_types[ $type ] ) ) {
                continue;
            }
            foreach ( $synthetic_fields as $target => $field ) {
                $fallback = 'intensity' === $target ? '1' : '';
                $record[ $field['source'] ] = (string) ( $relation_types[ $type ][ $field['registry_key'] ] ?? $fallback );
            }
            $args['records'][ $index ] = $record;
        }
        $args['mapping'] = $mapping;
        return $args;
    }

    private static function relation_type_key( string $value, array $registry ): string {
        $key = sanitize_key( $value );
        if ( '' !== $key && isset( $registry[ $key ] ) ) {
            return $key;
        }
        foreach ( $registry as $candidate => $meta ) {
            if ( 0 === strcasecmp( trim( $value ), trim( (string) ( $meta['label'] ?? $candidate ) ) ) {
                return sanitize_key( (string) $candidate );
            }
        }
        return '';
    }
}
