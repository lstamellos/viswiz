<?php
namespace VisWiz\Frontend;

use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\Registry;
use VisWiz\Support;
use VisWiz\WooCommerce\SalesQuery;
use WP_Error;

final class Frontend {
    private static bool $assets_registered = false;

    public static function register(): void {
        add_action( 'init', array( self::class, 'register_shortcodes' ), 20 );
        add_action( 'init', array( self::class, 'register_block' ), 20 );
    }

    public static function register_shortcodes(): void {
        add_shortcode( 'viswiz_visualization', array( self::class, 'shortcode' ) );
        foreach ( array( 'viswiz_progress', 'viswiz_pie', 'viswiz_graph', 'viswiz_diagram' ) as $tag ) {
            add_shortcode( $tag, array( self::class, 'legacy_shortcode' ) );
        }
    }

    public static function register_block(): void {
        self::register_assets();
        wp_register_script(
            'viswiz-block-editor',
            VISWIZ_URL . 'assets/viswiz-block.js',
            array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'wp-i18n' ),
            VISWIZ_VERSION,
            true
        );
        register_block_type(
            VISWIZ_DIR . 'blocks/visualization',
            array(
                'editor_script'   => 'viswiz-block-editor',
                'render_callback' => array( self::class, 'render_block' ),
            )
        );
    }

    public static function shortcode( array $atts = array() ): string {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'viswiz_visualization' );
        return self::render_visualization( absint( $atts['id'] ) );
    }

    public static function legacy_shortcode( array $atts = array(), ?string $content = null, string $tag = '' ): string {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, $tag );
        $id   = absint( $atts['id'] );
        if ( ! $id ) {
            return current_user_can( 'edit_viswiz_visualizations' )
                ? '<div class="viswiz-notice">' . esc_html__( 'VisWiz 2 requires a saved visualization ID. Use [viswiz_visualization id="123"].', 'viswiz' ) . '</div>'
                : '';
        }
        return self::render_visualization( $id );
    }

    public static function render_block( array $attributes ): string {
        return self::render_visualization( absint( $attributes['visualizationId'] ?? 0 ) );
    }

    public static function render_visualization( int $post_id ): string {
        if ( ! $post_id || 'viswiz_visualization' !== get_post_type( $post_id ) ) {
            return '';
        }
        if ( 'publish' !== get_post_status( $post_id ) && ! current_user_can( 'edit_post', $post_id ) ) {
            return '';
        }

        self::enqueue_assets();
        $endpoint = rest_url( 'viswiz/v2/visualizations/' . $post_id );
        return sprintf(
            '<div class="viswiz-visualization" data-viswiz-visualization="%1$d" data-viswiz-endpoint="%2$s"><div class="viswiz-loading">%3$s</div></div>',
            $post_id,
            esc_url( $endpoint ),
            esc_html__( 'Loading visualization…', 'viswiz' )
        );
    }

    public static function get_payload( int $post_id ) {
        if ( 'viswiz_visualization' !== get_post_type( $post_id ) ) {
            return new WP_Error( 'viswiz_visualization_not_found', __( 'Visualization not found.', 'viswiz' ), array( 'status' => 404 ) );
        }

        return self::build_payload(
            array(
                'id'          => $post_id,
                'title'       => get_the_title( $post_id ),
                'renderer'    => get_post_meta( $post_id, '_viswiz_renderer', true ),
                'source_type' => get_post_meta( $post_id, '_viswiz_source_type', true ),
                'dataset_id'  => get_post_meta( $post_id, '_viswiz_dataset_id', true ),
                'settings'    => get_post_meta( $post_id, '_viswiz_settings', true ),
                'woo_config'  => Support::json_decode_array( get_post_meta( $post_id, '_viswiz_woo_config', true ) ),
            )
        );
    }

    public static function preview_payload( array $config ) {
        return self::build_payload(
            array(
                'id'          => absint( $config['id'] ?? 0 ),
                'title'       => sanitize_text_field( (string) ( $config['title'] ?? '' ) ),
                'renderer'    => $config['renderer'] ?? 'pie',
                'source_type' => $config['source_type'] ?? 'dataset',
                'dataset_id'  => $config['dataset_id'] ?? 0,
                'settings'    => (array) ( $config['settings'] ?? array() ),
                'woo_config'  => (array) ( $config['woo_config'] ?? array() ),
            )
        );
    }

    private static function build_payload( array $config ) {
        $renderer = sanitize_key( (string) ( $config['renderer'] ?? 'pie' ) );
        if ( ! Registry::renderer_exists( $renderer ) ) {
            $renderer = 'pie';
        }
        $source   = sanitize_key( (string) ( $config['source_type'] ?? 'dataset' ) );
        $settings = self::sanitize_settings( $config['settings'] ?? array() );
        $schema   = Registry::default_schema_for_renderer( $renderer );
        $data     = array();
        $meta     = array();

        if ( 'woo_live' === $source ) {
            $query  = new SalesQuery();
            $result = $query->query( (array) ( $config['woo_config'] ?? array() ), true );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $data = array( 'rows' => $result['rows'] );
            $meta = array(
                'currency' => sanitize_text_field( (string) ( $result['meta']['currency'] ?? '' ) ),
                'cached'   => ! empty( $result['meta']['cached'] ),
            );
        } else {
            $source     = 'dataset';
            $dataset_id = absint( $config['dataset_id'] ?? 0 );
            if ( ! $dataset_id ) {
                return new WP_Error( 'viswiz_dataset_missing', __( 'This visualization is not connected to a dataset.', 'viswiz' ), array( 'status' => 409 ) );
            }
            $repository = new DatasetRepository();
            $dataset    = $repository->get( $dataset_id );
            if ( ! $dataset ) {
                return new WP_Error( 'viswiz_dataset_not_found', __( 'The visualization dataset no longer exists.', 'viswiz' ), array( 'status' => 404 ) );
            }
            $schema = (string) $dataset['schema_type'];
            if ( ! Registry::renderer_supports_schema( $renderer, $schema ) ) {
                return new WP_Error( 'viswiz_renderer_schema_mismatch', __( 'The selected renderer is not compatible with this dataset schema.', 'viswiz' ), array( 'status' => 409 ) );
            }
            $data = self::public_dataset_payload( $schema, $repository->get_payload( $dataset_id ) );
            $meta = array(
                'dataset_id'       => $dataset_id,
                'dataset_revision' => (int) $dataset['revision'],
                'dataset_name'     => $dataset['name'],
            );
        }

        return array(
            'id'          => absint( $config['id'] ?? 0 ),
            'title'       => (string) ( $config['title'] ?? '' ),
            'renderer'    => $renderer,
            'schema'      => $schema,
            'source_type' => $source,
            'settings'    => $settings,
            'data'        => $data,
            'meta'        => $meta,
            'refresh_ms'  => 'woo_live' === $source ? max( 60000, absint( $settings['refresh_ms'] ?? 120000 ) ) : 0,
        );
    }

    private static function public_dataset_payload( string $schema, array $payload ): array {
        if ( 'graph' === $schema ) {
            $nodes = array_map(
                static function ( array $node ): array {
                    $images = array_map(
                        static fn( array $image ): array => array(
                            'url'      => esc_url_raw( (string) ( $image['url'] ?? '' ) ),
                            'thumb'    => esc_url_raw( (string) ( $image['thumb'] ?? '' ) ),
                            'alt'      => sanitize_text_field( (string) ( $image['alt'] ?? '' ) ),
                            'caption'  => sanitize_text_field( (string) ( $image['caption'] ?? '' ) ),
                            'featured' => ! empty( $image['featured'] ),
                        ),
                        array_values( array_filter( (array) ( $node['image_gallery'] ?? array() ), 'is_array' ) )
                    );
                    $public_meta = array();
                    if ( isset( $node['meta']['color'] ) ) {
                        $public_meta['color'] = Support::sanitize_color( $node['meta']['color'], '' );
                    }
                    $public_fields = array();
                    foreach ( (array) ( $node['meta']['public_fields'] ?? array() ) as $field ) {
                        if ( ! is_array( $field ) ) {
                            continue;
                        }
                        $type = sanitize_key( (string) ( $field['type'] ?? 'short' ) );
                        if ( ! in_array( $type, array( 'short', 'long', 'url', 'formatted' ), true ) ) {
                            $type = 'short';
                        }
                        $value = (string) ( $field['value'] ?? '' );
                        if ( 'url' === $type ) {
                            $value = esc_url_raw( $value );
                        } elseif ( 'formatted' === $type ) {
                            $value = wp_kses_post( $value );
                        } else {
                            $value = sanitize_textarea_field( $value );
                        }
                        if ( '' !== $value ) {
                            $public_fields[] = array(
                                'label' => sanitize_text_field( (string) ( $field['label'] ?? $field['key'] ?? '' ) ),
                                'type'  => $type,
                                'value' => $value,
                            );
                        }
                    }
                    return array(
                        'uuid'             => sanitize_text_field( (string) ( $node['uuid'] ?? '' ) ),
                        'slug'             => sanitize_title( (string) ( $node['slug'] ?? '' ) ),
                        'id'               => sanitize_title( (string) ( $node['slug'] ?? '' ) ),
                        'title'            => sanitize_text_field( (string) ( $node['title'] ?? '' ) ),
                        'label'            => sanitize_text_field( (string) ( $node['label'] ?? '' ) ),
                        'node_type'        => sanitize_key( (string) ( $node['node_type'] ?? '' ) ),
                        'node_subtype'     => sanitize_key( (string) ( $node['node_subtype'] ?? '' ) ),
                        'description'      => wp_kses_post( (string) ( $node['description'] ?? '' ) ),
                        'description_html' => wp_kses_post( (string) ( $node['description_html'] ?? $node['description'] ?? '' ) ),
                        'image_gallery'    => $images,
                        'public_fields'    => $public_fields,
                        'meta'             => $public_meta,
                    );
                },
                array_values( array_filter( (array) ( $payload['nodes'] ?? array() ), 'is_array' ) )
            );
            $relations = array_map(
                static fn( array $relation ): array => array(
                    'uuid'           => sanitize_text_field( (string) ( $relation['uuid'] ?? '' ) ),
                    'from_node_uuid' => sanitize_text_field( (string) ( $relation['from_node_uuid'] ?? '' ) ),
                    'to_node_uuid'   => sanitize_text_field( (string) ( $relation['to_node_uuid'] ?? '' ) ),
                    'relation_type'  => sanitize_key( (string) ( $relation['relation_type'] ?? '' ) ),
                    'label'          => sanitize_text_field( (string) ( $relation['label'] ?? '' ) ),
                    'inverse_label'  => sanitize_text_field( (string) ( $relation['inverse_label'] ?? '' ) ),
                    'direction'      => sanitize_key( (string) ( $relation['direction'] ?? 'directed' ) ),
                    'intensity'      => (float) ( $relation['intensity'] ?? 1 ),
                ),
                array_values( array_filter( (array) ( $payload['relations'] ?? array() ), 'is_array' ) )
            );
            return array( 'nodes' => $nodes, 'relations' => $relations );
        }

        $rows = array_map(
            static function ( array $row ): array {
                $meta = (array) ( $row['meta'] ?? array() );
                $public_meta = array();
                foreach ( array( 'target', 'text', 'description' ) as $key ) {
                    if ( ! array_key_exists( $key, $meta ) ) {
                        continue;
                    }
                    $public_meta[ $key ] = 'target' === $key ? (float) $meta[ $key ] : sanitize_textarea_field( (string) $meta[ $key ] );
                }
                return array(
                    'uuid'      => sanitize_text_field( (string) ( $row['uuid'] ?? '' ) ),
                    'row_key'   => sanitize_key( (string) ( $row['row_key'] ?? '' ) ),
                    'label'     => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
                    'value'     => isset( $row['value'] ) ? (float) $row['value'] : null,
                    'x_value'   => sanitize_text_field( (string) ( $row['x_value'] ?? '' ) ),
                    'x_numeric' => isset( $row['x_numeric'] ) ? (float) $row['x_numeric'] : null,
                    'y_value'   => isset( $row['y_value'] ) ? (float) $row['y_value'] : null,
                    'latitude'  => isset( $row['latitude'] ) ? (float) $row['latitude'] : null,
                    'longitude' => isset( $row['longitude'] ) ? (float) $row['longitude'] : null,
                    'color'     => Support::sanitize_color( $row['color'] ?? '', '' ),
                    'meta'      => $public_meta,
                );
            },
            array_values( array_filter( (array) ( $payload['rows'] ?? array() ), 'is_array' ) )
        );
        return array( 'rows' => $rows );
    }

    public static function sanitize_settings( mixed $value ): array {
        $raw = Support::json_decode_array( $value );
        return array(
            'title'                 => sanitize_text_field( (string) ( $raw['title'] ?? '' ) ),
            'primary_color'         => Support::sanitize_color( $raw['primary_color'] ?? '', '#2563eb' ),
            'secondary_color'       => Support::sanitize_color( $raw['secondary_color'] ?? '', '#64748b' ),
            'text_color'            => Support::sanitize_color( $raw['text_color'] ?? '', '#111827' ),
            'background_color'      => Support::sanitize_color( $raw['background_color'] ?? '', '#ffffff' ),
            'full_screen'           => ! isset( $raw['full_screen'] ) || Support::bool( $raw['full_screen'] ),
            'show_legend'           => ! isset( $raw['show_legend'] ) || Support::bool( $raw['show_legend'] ),
            'show_graph_toolbar'    => ! isset( $raw['show_graph_toolbar'] ) || Support::bool( $raw['show_graph_toolbar'] ),
            'show_graph_search'     => ! isset( $raw['show_graph_search'] ) || Support::bool( $raw['show_graph_search'] ),
            'show_graph_filters'    => ! isset( $raw['show_graph_filters'] ) || Support::bool( $raw['show_graph_filters'] ),
            'show_graph_zoom'       => ! isset( $raw['show_graph_zoom'] ) || Support::bool( $raw['show_graph_zoom'] ),
            'show_node_images'      => ! isset( $raw['show_node_images'] ) || Support::bool( $raw['show_node_images'] ),
            'show_type_badges'      => ! isset( $raw['show_type_badges'] ) || Support::bool( $raw['show_type_badges'] ),
            'show_relation_labels'  => ! isset( $raw['show_relation_labels'] ) || Support::bool( $raw['show_relation_labels'] ),
            'node_modal_title_fallback'       => sanitize_text_field( (string) ( $raw['node_modal_title_fallback'] ?? __( 'Node details', 'viswiz' ) ) ),
            'node_modal_close_label'          => sanitize_text_field( (string) ( $raw['node_modal_close_label'] ?? __( 'Close node details', 'viswiz' ) ) ),
            'node_modal_previous_image_label' => sanitize_text_field( (string) ( $raw['node_modal_previous_image_label'] ?? __( 'Previous image', 'viswiz' ) ) ),
            'node_modal_next_image_label'     => sanitize_text_field( (string) ( $raw['node_modal_next_image_label'] ?? __( 'Next image', 'viswiz' ) ) ),
            'node_modal_related_heading'      => sanitize_text_field( (string) ( $raw['node_modal_related_heading'] ?? __( 'Related nodes', 'viswiz' ) ) ),
            'node_modal_relation_fallback'    => sanitize_text_field( (string) ( $raw['node_modal_relation_fallback'] ?? __( 'Relation', 'viswiz' ) ) ),
            'target'                => isset( $raw['target'] ) ? (float) $raw['target'] : 0.0,
            'refresh_ms'            => max( 60000, min( 1800000, absint( $raw['refresh_ms'] ?? 120000 ) ) ),
        );
    }

    private static function settings( int $post_id ): array {
        return self::sanitize_settings( get_post_meta( $post_id, '_viswiz_settings', true ) );
    }

    private static function register_assets(): void {
        if ( self::$assets_registered ) {
            return;
        }
        wp_register_style( 'viswiz-frontend', VISWIZ_URL . 'assets/viswiz.css', array(), VISWIZ_VERSION );
        wp_register_script( 'viswiz-frontend', VISWIZ_URL . 'assets/viswiz.js', array(), VISWIZ_VERSION, true );
        wp_localize_script(
            'viswiz-frontend',
            'VisWizFrontendV2',
            array(
                'i18n' => array(
                    'visualization' => __( 'Visualization', 'viswiz' ),
                    'searchNodes'       => __( 'Search nodes', 'viswiz' ),
                    'filterNodeType'    => __( 'Filter node type', 'viswiz' ),
                    'allNodeTypes'      => __( 'All node types', 'viswiz' ),
                    'filterRelationType'=> __( 'Filter relation type', 'viswiz' ),
                    'allRelationTypes'  => __( 'All relation types', 'viswiz' ),
                    'zoomIn'            => __( 'Zoom in', 'viswiz' ),
                    'zoomOut'           => __( 'Zoom out', 'viswiz' ),
                    'resetZoom'         => __( 'Reset zoom', 'viswiz' ),
                    'nodes'             => __( 'nodes', 'viswiz' ),
                    'relations'         => __( 'relations', 'viswiz' ),
                    'noMatchingNodes'   => __( 'No matching nodes', 'viswiz' ),
                    'previousImage'     => __( 'Previous image', 'viswiz' ),
                    'nextImage'         => __( 'Next image', 'viswiz' ),
                    'nodeGraph'         => __( 'Node graph', 'viswiz' ),
                    'viewNode'          => __( 'View node', 'viswiz' ),
                    'close'         => __( 'Close', 'viswiz' ),
                    'node'          => __( 'Node', 'viswiz' ),
                    'relatedNodes'  => __( 'Related nodes', 'viswiz' ),
                    'relation'      => __( 'Relation', 'viswiz' ),
                    'noData'        => __( 'No data available.', 'viswiz' ),
                    'fullScreen'    => __( 'Full screen', 'viswiz' ),
                    'exitFullScreen'=> __( 'Exit full screen', 'viswiz' ),
                    'loadError'     => __( 'Could not load visualization.', 'viswiz' ),
                ),
            )
        );
        self::$assets_registered = true;
    }

    private static function enqueue_assets(): void {
        self::register_assets();
        wp_enqueue_style( 'viswiz-frontend' );
        wp_enqueue_script( 'viswiz-frontend' );
    }
}