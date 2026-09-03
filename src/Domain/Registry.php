<?php
namespace VisWiz\Domain;

use VisWiz\Support;
use WP_Error;

final class Registry {
    public static function schemas(): array {
        return array(
            'categorical' => array(
                'label'  => 'Categorical',
                'fields' => array( 'label', 'value', 'color' ),
                'editor' => array(
                    'noun'   => 'item',
                    'plural' => 'items',
                    'fields' => array(
                        array( 'path' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true, 'table' => true ),
                        array( 'path' => 'value', 'label' => 'Value', 'type' => 'number', 'required' => true, 'table' => true, 'step' => 'any' ),
                        array( 'path' => 'color', 'label' => 'Color', 'type' => 'color', 'table' => true ),
                    ),
                ),
            ),
            'time_series' => array(
                'label'  => 'Time series',
                'fields' => array( 'x_value', 'value', 'label', 'color' ),
                'editor' => array(
                    'noun'   => 'point',
                    'plural' => 'points',
                    'fields' => array(
                        array( 'path' => 'x_value', 'label' => 'Date / time', 'type' => 'datetime-local', 'required' => true, 'table' => true ),
                        array( 'path' => 'value', 'label' => 'Value', 'type' => 'number', 'required' => true, 'table' => true, 'step' => 'any' ),
                        array( 'path' => 'label', 'label' => 'Label', 'type' => 'text', 'table' => true ),
                        array( 'path' => 'color', 'label' => 'Color', 'type' => 'color', 'table' => true ),
                    ),
                ),
            ),
            'xy' => array(
                'label'  => 'X/Y points',
                'fields' => array( 'x_numeric', 'y_value', 'label', 'color' ),
                'editor' => array(
                    'noun'   => 'point',
                    'plural' => 'points',
                    'fields' => array(
                        array( 'path' => 'x_numeric', 'label' => 'X', 'type' => 'number', 'required' => true, 'table' => true, 'step' => 'any' ),
                        array( 'path' => 'y_value', 'label' => 'Y', 'type' => 'number', 'required' => true, 'table' => true, 'step' => 'any' ),
                        array( 'path' => 'label', 'label' => 'Label', 'type' => 'text', 'table' => true ),
                        array( 'path' => 'color', 'label' => 'Color', 'type' => 'color', 'table' => true ),
                    ),
                ),
            ),
            'geo' => array(
                'label'  => 'Geographic points',
                'fields' => array( 'latitude', 'longitude', 'label', 'value', 'color' ),
                'editor' => array(
                    'noun'   => 'point',
                    'plural' => 'points',
                    'fields' => array(
                        array( 'path' => 'latitude', 'label' => 'Latitude', 'type' => 'number', 'required' => true, 'table' => true, 'step' => 'any', 'min' => -90, 'max' => 90 ),
                        array( 'path' => 'longitude', 'label' => 'Longitude', 'type' => 'number', 'required' => true, 'table' => true, 'step' => 'any', 'min' => -180, 'max' => 180 ),
                        array( 'path' => 'label', 'label' => 'Label', 'type' => 'text', 'table' => true ),
                        array( 'path' => 'value', 'label' => 'Value', 'type' => 'number', 'table' => true, 'step' => 'any' ),
                        array( 'path' => 'color', 'label' => 'Color', 'type' => 'color', 'table' => true ),
                    ),
                ),
            ),
            'progress' => array(
                'label'  => 'Progress',
                'fields' => array( 'label', 'value', 'color', 'meta' ),
                'editor' => array(
                    'noun'   => 'progress item',
                    'plural' => 'progress items',
                    'fields' => array(
                        array( 'path' => 'label', 'label' => 'Label', 'type' => 'text', 'required' => true, 'table' => true ),
                        array( 'path' => 'value', 'label' => 'Current value', 'type' => 'number', 'required' => true, 'table' => true, 'step' => 'any' ),
                        array( 'path' => 'meta.target', 'label' => 'Target', 'type' => 'number', 'table' => true, 'step' => 'any' ),
                        array( 'path' => 'meta.text', 'label' => 'Text', 'type' => 'textarea', 'table' => true, 'rows' => 4 ),
                        array( 'path' => 'color', 'label' => 'Color', 'type' => 'color', 'table' => true ),
                    ),
                ),
            ),
            'graph' => array( 'label' => 'Node graph', 'fields' => array( 'nodes', 'relations' ) ),
            'diagram' => array(
                'label'  => 'Diagram / sections',
                'fields' => array( 'label', 'color', 'meta' ),
                'editor' => array(
                    'noun'   => 'section',
                    'plural' => 'sections',
                    'fields' => array(
                        array( 'path' => 'label', 'label' => 'Section title', 'type' => 'text', 'required' => true, 'table' => true ),
                        array( 'path' => 'meta.text', 'label' => 'Section text', 'type' => 'textarea', 'table' => true, 'rows' => 6 ),
                        array( 'path' => 'color', 'label' => 'Accent color', 'type' => 'color', 'table' => true ),
                    ),
                ),
            ),
        );
    }

    public static function renderers(): array {
        $common = array( 'title', 'primary_color', 'secondary_color', 'text_color', 'background_color', 'full_screen' );
        $graph  = array_merge(
            $common,
            array(
                'node_modal_title_fallback',
                'node_modal_close_label',
                'node_modal_previous_image_label',
                'node_modal_next_image_label',
                'node_modal_related_heading',
                'node_modal_relation_fallback',
                'show_node_images',
                'show_type_badges',
                'show_relation_labels',
                'show_graph_toolbar',
                'show_graph_search',
                'show_graph_filters',
                'show_graph_zoom',
            )
        );

        return array(
            'pie'          => array( 'label' => 'Pie', 'schemas' => array( 'categorical' ), 'woo_live' => true, 'settings' => array_merge( $common, array( 'show_legend' ) ) ),
            'bar'          => array( 'label' => 'Bar', 'schemas' => array( 'categorical' ), 'woo_live' => true, 'settings' => $common ),
            'column'       => array( 'label' => 'Column', 'schemas' => array( 'categorical' ), 'woo_live' => true, 'settings' => $common ),
            'line'         => array( 'label' => 'Line', 'schemas' => array( 'time_series', 'categorical' ), 'woo_live' => true, 'settings' => $common ),
            'area'         => array( 'label' => 'Area', 'schemas' => array( 'time_series', 'categorical' ), 'woo_live' => true, 'settings' => $common ),
            'scatter'      => array( 'label' => 'Scatter', 'schemas' => array( 'xy' ), 'woo_live' => false, 'settings' => $common ),
            'progress'     => array( 'label' => 'Progress', 'schemas' => array( 'progress', 'categorical' ), 'woo_live' => true, 'settings' => array_merge( $common, array( 'target' ) ) ),
            'counter'      => array( 'label' => 'Counter', 'schemas' => array( 'categorical' ), 'woo_live' => true, 'settings' => $common ),
            'timeline'     => array( 'label' => 'Timeline', 'schemas' => array( 'time_series' ), 'woo_live' => true, 'settings' => $common ),
            'map'          => array( 'label' => 'Map', 'schemas' => array( 'geo' ), 'woo_live' => false, 'settings' => $common ),
            'graph'        => array( 'label' => 'Graph', 'schemas' => array( 'graph' ), 'woo_live' => false, 'settings' => $graph ),
            'flow_diagram' => array( 'label' => 'Flow diagram', 'schemas' => array( 'graph' ), 'woo_live' => false, 'settings' => $graph ),
            'org_chart'    => array( 'label' => 'Org chart', 'schemas' => array( 'graph' ), 'woo_live' => false, 'settings' => $graph ),
            'diagram'      => array( 'label' => 'Diagram', 'schemas' => array( 'diagram' ), 'woo_live' => false, 'settings' => $common ),
        );
    }

    public static function schema_exists( string $schema ): bool {
        return isset( self::schemas()[ $schema ] );
    }

    public static function renderer_exists( string $renderer ): bool {
        return isset( self::renderers()[ $renderer ] );
    }

    public static function renderer_supports_schema( string $renderer, string $schema ): bool {
        $renderers = self::renderers();
        return isset( $renderers[ $renderer ] ) && in_array( $schema, $renderers[ $renderer ]['schemas'], true );
    }

    public static function renderer_supports_woo_live( string $renderer ): bool {
        $renderers = self::renderers();
        return ! empty( $renderers[ $renderer ]['woo_live'] );
    }

    public static function renderer_settings( string $renderer ): array {
        $renderers = self::renderers();
        return isset( $renderers[ $renderer ] ) ? (array) $renderers[ $renderer ]['settings'] : array();
    }

    public static function renderer_setting_defaults( string $renderer = '' ): array {
        $known  = self::renderer_exists( $renderer );
        $active = $known ? self::renderer_settings( $renderer ) : array();
        $enabled = static fn( string $key ): bool => ! $known || in_array( $key, $active, true );

        return array(
            'title'                 => '',
            'primary_color'         => '#2563eb',
            'secondary_color'       => '#64748b',
            'text_color'            => '#111827',
            'background_color'      => '#ffffff',
            'full_screen'           => true,
            'show_legend'           => $enabled( 'show_legend' ),
            'show_graph_toolbar'    => $enabled( 'show_graph_toolbar' ),
            'show_graph_search'     => $enabled( 'show_graph_search' ),
            'show_graph_filters'    => $enabled( 'show_graph_filters' ),
            'show_graph_zoom'       => $enabled( 'show_graph_zoom' ),
            'show_node_images'      => $enabled( 'show_node_images' ),
            'show_type_badges'      => $enabled( 'show_type_badges' ),
            'show_relation_labels'  => $enabled( 'show_relation_labels' ),
            'node_modal_title_fallback'       => __( 'Node details', 'viswiz' ),
            'node_modal_close_label'          => __( 'Close node details', 'viswiz' ),
            'node_modal_previous_image_label' => __( 'Previous image', 'viswiz' ),
            'node_modal_next_image_label'     => __( 'Next image', 'viswiz' ),
            'node_modal_related_heading'      => __( 'Related nodes', 'viswiz' ),
            'node_modal_relation_fallback'    => __( 'Relation', 'viswiz' ),
            'target'                => 0.0,
            'refresh_ms'            => 120000,
        );
    }

    public static function default_schema_for_renderer( string $renderer ): string {
        $renderers = self::renderers();
        return isset( $renderers[ $renderer ] ) ? $renderers[ $renderer ]['schemas'][0] : 'categorical';
    }

    public static function node_types(): array {
        $saved = get_option( 'viswiz_node_type_schema_v2', array() );
        return is_array( $saved ) && $saved ? $saved : self::default_node_types();
    }

    public static function relation_types(): array {
        $saved = get_option( 'viswiz_relation_type_schema_v2', array() );
        return is_array( $saved ) && $saved ? $saved : self::default_relation_types();
    }

    public static function update_node_types( array $schema ) {
        if ( ! current_user_can( 'manage_viswiz_schema' ) ) {
            return new WP_Error( 'viswiz_forbidden_schema', __( 'You are not allowed to change the global node schema.', 'viswiz' ), array( 'status' => 403 ) );
        }
        $sanitized = array();
        foreach ( $schema as $key => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $slug = sanitize_key( (string) ( $item['slug'] ?? $key ) );
            if ( '' === $slug ) {
                continue;
            }
            $subtypes = array();
            foreach ( (array) ( $item['subtypes'] ?? array() ) as $sub_key => $sub_label ) {
                $sub_slug = sanitize_key( (string) $sub_key );
                if ( '' !== $sub_slug ) {
                    $subtypes[ $sub_slug ] = sanitize_text_field( (string) $sub_label );
                }
            }
            $sanitized[ $slug ] = array(
                'slug'        => $slug,
                'label'       => sanitize_text_field( (string) ( $item['label'] ?? $slug ) ),
                'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
                'color'       => Support::sanitize_color( $item['color'] ?? '', '#2563eb' ),
                'subtypes'    => $subtypes,
            );
        }
        $sanitized = $sanitized ?: self::default_node_types();
        $usage_error = self::validate_node_schema_usage( $sanitized );
        if ( is_wp_error( $usage_error ) ) {
            return $usage_error;
        }
        update_option( 'viswiz_node_type_schema_v2', $sanitized, false );
        return self::node_types();
    }

    public static function update_relation_types( array $schema ) {
        if ( ! current_user_can( 'manage_viswiz_schema' ) ) {
            return new WP_Error( 'viswiz_forbidden_schema', __( 'You are not allowed to change the global relation schema.', 'viswiz' ), array( 'status' => 403 ) );
        }
        $sanitized = array();
        foreach ( $schema as $key => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $slug = sanitize_key( (string) ( $item['slug'] ?? $key ) );
            if ( '' === $slug ) {
                continue;
            }
            $direction = sanitize_key( (string) ( $item['direction'] ?? 'directed' ) );
            if ( ! in_array( $direction, array( 'directed', 'bidirectional', 'undirected' ), true ) ) {
                $direction = 'directed';
            }
            $sanitized[ $slug ] = array(
                'slug'           => $slug,
                'label'          => sanitize_text_field( (string) ( $item['label'] ?? $slug ) ),
                'inverse_label'  => sanitize_text_field( (string) ( $item['inverse_label'] ?? '' ) ),
                'description'    => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
                'direction'      => $direction,
                'intensity'      => max( 0.1, min( 20, (float) ( $item['intensity'] ?? 1 ) ) ),
                'source_type'    => sanitize_key( (string) ( $item['source_type'] ?? '' ) ),
                'source_subtype' => sanitize_key( (string) ( $item['source_subtype'] ?? '' ) ),
                'target_type'    => sanitize_key( (string) ( $item['target_type'] ?? '' ) ),
                'target_subtype' => sanitize_key( (string) ( $item['target_subtype'] ?? '' ) ),
            );
        }
        $sanitized = $sanitized ?: self::default_relation_types();
        $usage_error = self::validate_relation_schema_usage( $sanitized );
        if ( is_wp_error( $usage_error ) ) {
            return $usage_error;
        }
        update_option( 'viswiz_relation_type_schema_v2', $sanitized, false );
        return self::relation_types();
    }


    private static function validate_node_schema_usage( array $schema ) {
        global $wpdb;
        $table = Support::table( 'nodes' );
        $used  = $wpdb->get_results( "SELECT DISTINCT node_type,node_subtype FROM {$table} WHERE node_type<>''", ARRAY_A );
        foreach ( $used ?: array() as $row ) {
            $type    = (string) $row['node_type'];
            $subtype = (string) $row['node_subtype'];
            if ( ! isset( $schema[ $type ] ) ) {
                return new WP_Error( 'viswiz_schema_in_use', sprintf( __( 'Node type “%s” is in use and cannot be removed.', 'viswiz' ), $type ), array( 'status' => 409 ) );
            }
            if ( '' !== $subtype && ! isset( $schema[ $type ]['subtypes'][ $subtype ] ) ) {
                return new WP_Error( 'viswiz_schema_in_use', sprintf( __( 'Node subtype “%1$s / %2$s” is in use and cannot be removed.', 'viswiz' ), $type, $subtype ), array( 'status' => 409 ) );
            }
        }
        return true;
    }

    private static function validate_relation_schema_usage( array $schema ) {
        global $wpdb;
        $table = Support::table( 'edges' );
        $used  = $wpdb->get_col( "SELECT DISTINCT relation_type FROM {$table} WHERE relation_type<>''" );
        foreach ( $used ?: array() as $type ) {
            $type = (string) $type;
            if ( ! isset( $schema[ $type ] ) ) {
                return new WP_Error( 'viswiz_schema_in_use', sprintf( __( 'Relation type “%s” is in use and cannot be removed.', 'viswiz' ), $type ), array( 'status' => 409 ) );
            }
        }
        return true;
    }

    public static function default_node_types(): array {
        return array(
            'person'       => array( 'slug' => 'person', 'label' => 'Person', 'description' => '', 'color' => '#2563eb', 'subtypes' => array() ),
            'organization' => array( 'slug' => 'organization', 'label' => 'Organization', 'description' => '', 'color' => '#7c3aed', 'subtypes' => array() ),
            'event'        => array( 'slug' => 'event', 'label' => 'Event', 'description' => '', 'color' => '#ea580c', 'subtypes' => array() ),
            'place'        => array( 'slug' => 'place', 'label' => 'Place', 'description' => '', 'color' => '#059669', 'subtypes' => array() ),
            'publication'  => array( 'slug' => 'publication', 'label' => 'Publication', 'description' => '', 'color' => '#0891b2', 'subtypes' => array() ),
            'legal_case'   => array( 'slug' => 'legal_case', 'label' => 'Legal case', 'description' => '', 'color' => '#be123c', 'subtypes' => array() ),
            'state_body'   => array( 'slug' => 'state_body', 'label' => 'State body', 'description' => '', 'color' => '#4f46e5', 'subtypes' => array() ),
            'concept'      => array( 'slug' => 'concept', 'label' => 'Concept', 'description' => '', 'color' => '#475569', 'subtypes' => array() ),
            'asset'        => array( 'slug' => 'asset', 'label' => 'Asset', 'description' => '', 'color' => '#a16207', 'subtypes' => array() ),
        );
    }

    public static function default_relation_types(): array {
        return array(
            'member_of' => array(
                'slug' => 'member_of', 'label' => 'Member of', 'inverse_label' => 'Has member', 'description' => '',
                'direction' => 'directed', 'intensity' => 1, 'source_type' => 'person', 'source_subtype' => '', 'target_type' => 'organization', 'target_subtype' => '',
            ),
            'leader_of' => array(
                'slug' => 'leader_of', 'label' => 'Leader of', 'inverse_label' => 'Led by', 'description' => '',
                'direction' => 'directed', 'intensity' => 1, 'source_type' => 'person', 'source_subtype' => '', 'target_type' => 'organization', 'target_subtype' => '',
            ),
            'participated_in' => array(
                'slug' => 'participated_in', 'label' => 'Participated in', 'inverse_label' => 'Had participant', 'description' => '',
                'direction' => 'directed', 'intensity' => 1, 'source_type' => '', 'source_subtype' => '', 'target_type' => 'event', 'target_subtype' => '',
            ),
            'connected_to' => array(
                'slug' => 'connected_to', 'label' => 'Connected to', 'inverse_label' => 'Connected to', 'description' => '',
                'direction' => 'undirected', 'intensity' => 1, 'source_type' => '', 'source_subtype' => '', 'target_type' => '', 'target_subtype' => '',
            ),
        );
    }
}
