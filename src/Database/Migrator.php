<?php
namespace VisWiz\Database;

use VisWiz\Domain\Registry;
use VisWiz\Support;

final class Migrator {
    private const OPTION_DB_VERSION = 'viswiz_db_schema_version';
    private const OPTION_MIGRATED   = 'viswiz_v2_legacy_migrated';
    private const OPTION_LEGACY_MAP = 'viswiz_v2_legacy_dataset_map';

    public static function maybe_upgrade(): void {
        if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < VISWIZ_DB_VERSION || ! get_option( self::OPTION_MIGRATED ) ) {
            self::install();
        }
    }

    public static function install(): void {
        self::create_tables();
        self::migrate_legacy();
        update_option( self::OPTION_DB_VERSION, VISWIZ_DB_VERSION, false );
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset   = $wpdb->get_charset_collate();
        $datasets  = Support::table( 'datasets' );
        $rows      = Support::table( 'rows' );
        $nodes     = Support::table( 'nodes' );
        $edges     = Support::table( 'edges' );
        $revisions = Support::table( 'dataset_revisions' );

        dbDelta(
            "CREATE TABLE {$datasets} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                uuid char(36) NOT NULL DEFAULT '',
                name varchar(190) NOT NULL,
                description text NULL,
                schema_type varchar(40) NOT NULL DEFAULT 'categorical',
                source_type varchar(40) NOT NULL DEFAULT 'manual',
                revision bigint(20) unsigned NOT NULL DEFAULT 1,
                settings longtext NULL,
                created_by bigint(20) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uuid (uuid),
                KEY schema_type (schema_type),
                KEY source_type (source_type)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$rows} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                uuid char(36) NOT NULL,
                dataset_id bigint(20) unsigned NOT NULL,
                row_key varchar(190) NOT NULL DEFAULT '',
                label varchar(190) NOT NULL DEFAULT '',
                value double NULL,
                x_value varchar(190) NOT NULL DEFAULT '',
                x_numeric double NULL,
                y_value double NULL,
                latitude double NULL,
                longitude double NULL,
                color varchar(20) NOT NULL DEFAULT '',
                meta longtext NULL,
                sort_order int(11) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dataset_uuid (dataset_id,uuid),
                KEY dataset_sort (dataset_id,sort_order),
                KEY dataset_key (dataset_id,row_key)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$nodes} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                uuid char(36) NOT NULL,
                dataset_id bigint(20) unsigned NOT NULL,
                slug varchar(190) NOT NULL,
                title varchar(190) NOT NULL DEFAULT '',
                label varchar(190) NOT NULL DEFAULT '',
                node_type varchar(80) NOT NULL DEFAULT '',
                node_subtype varchar(80) NOT NULL DEFAULT '',
                description longtext NULL,
                main_image_id bigint(20) unsigned NOT NULL DEFAULT 0,
                other_image_ids text NULL,
                meta longtext NULL,
                sort_order int(11) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dataset_uuid (dataset_id,uuid),
                UNIQUE KEY dataset_slug (dataset_id,slug),
                KEY dataset_type (dataset_id,node_type),
                KEY dataset_subtype (dataset_id,node_subtype),
                KEY dataset_sort (dataset_id,sort_order)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$edges} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                uuid char(36) NOT NULL,
                dataset_id bigint(20) unsigned NOT NULL,
                from_node_uuid char(36) NOT NULL,
                to_node_uuid char(36) NOT NULL,
                relation_type varchar(80) NOT NULL DEFAULT '',
                label varchar(190) NOT NULL DEFAULT '',
                inverse_label varchar(190) NOT NULL DEFAULT '',
                direction varchar(20) NOT NULL DEFAULT 'directed',
                intensity double NOT NULL DEFAULT 1,
                meta longtext NULL,
                sort_order int(11) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dataset_uuid (dataset_id,uuid),
                KEY dataset_from (dataset_id,from_node_uuid),
                KEY dataset_to (dataset_id,to_node_uuid),
                KEY dataset_relation (dataset_id,relation_type),
                KEY dataset_sort (dataset_id,sort_order)
            ) {$charset};"
        );

        dbDelta(
            "CREATE TABLE {$revisions} (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                dataset_id bigint(20) unsigned NOT NULL,
                revision bigint(20) unsigned NOT NULL,
                snapshot longtext NOT NULL,
                actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                note varchar(255) NOT NULL DEFAULT '',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY dataset_revision (dataset_id,revision),
                KEY dataset_created (dataset_id,created_at)
            ) {$charset};"
        );
    }

    private static function migrate_legacy(): void {
        if ( get_option( self::OPTION_MIGRATED ) ) {
            return;
        }

        global $wpdb;
        self::migrate_legacy_registries();
        $migration_ok = true;

        $repository       = new DatasetRepository();
        $legacy_datasets  = Support::legacy_table( 'datasets' );
        $legacy_points    = Support::legacy_table( 'data_points' );
        $legacy_relations = Support::legacy_table( 'relations' );
        $has_datasets     = self::table_exists( $legacy_datasets );
        $has_points       = self::table_exists( $legacy_points );
        $has_relations    = self::table_exists( $legacy_relations );
        $id_map           = get_option( self::OPTION_LEGACY_MAP, array() );
        $id_map           = is_array( $id_map ) ? array_map( 'absint', $id_map ) : array();

        if ( $has_datasets ) {
            $records = $wpdb->get_results( "SELECT * FROM {$legacy_datasets} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            foreach ( $records ?: array() as $record ) {
                $legacy_id = (int) $record['id'];
                $legacy_type = sanitize_key( (string) ( $record['data_type'] ?? '' ) );
                $schema = self::legacy_schema_type( $legacy_type, $legacy_id, $has_relations ? $legacy_relations : '' );

                $dataset_id = isset( $id_map[ $legacy_id ] ) && $repository->get( (int) $id_map[ $legacy_id ] )
                    ? (int) $id_map[ $legacy_id ]
                    : 0;
                if ( ! $dataset_id ) {
                    $created = $repository->create(
                        array(
                            'uuid'        => Support::uuid(),
                            'name'        => (string) ( $record['name'] ?? sprintf( 'Legacy dataset #%d', $legacy_id ) ),
                            'description' => (string) ( $record['description'] ?? '' ),
                            'schema_type' => $schema,
                            'source_type' => 'legacy',
                            'settings'    => array( 'legacy_dataset_id' => $legacy_id ),
                        )
                    );
                    if ( is_wp_error( $created ) ) {
                        $migration_ok = false;
                        continue;
                    }
                    $dataset_id          = (int) $created;
                    $id_map[ $legacy_id ] = $dataset_id;
                    update_option( self::OPTION_LEGACY_MAP, $id_map, false );
                }

                $mapped_dataset = $repository->get( $dataset_id );
                if ( ! $has_points || ( $mapped_dataset && (int) $mapped_dataset['revision'] > 1 ) ) {
                    continue;
                }

                $points = $wpdb->get_results(
                    $wpdb->prepare( "SELECT * FROM {$legacy_points} WHERE dataset_id = %d ORDER BY sort_order ASC,id ASC", $legacy_id ),
                    ARRAY_A
                );

                if ( 'graph' === $schema ) {
                    $slug_to_uuid = array();
                    $used_slugs   = array();
                    $nodes        = array();
                    foreach ( $points ?: array() as $point ) {
                        $meta = Support::json_decode_array( $point['meta'] ?? '' );
                        if ( ! empty( $meta['custom_labels'] ) && empty( $meta['public_fields'] ) ) {
                            $meta['public_fields'] = self::legacy_custom_labels_to_public_fields( (array) $meta['custom_labels'] );
                        }
                        $base_slug = sanitize_title( (string) ( $meta['id'] ?? $point['point_key'] ?? $point['label'] ?? '' ) );
                        if ( '' === $base_slug ) {
                            $base_slug = 'node-' . ( count( $nodes ) + 1 );
                        }
                        $slug = $base_slug;
                        $suffix = 2;
                        while ( isset( $used_slugs[ $slug ] ) ) {
                            $slug = $base_slug . '-' . $suffix++;
                        }
                        $used_slugs[ $slug ] = true;
                        $node_uuid = Support::uuid( (string) ( $meta['uuid'] ?? '' ) );
                        // Preserve references using both the original key and the normalized unique slug.
                        foreach ( array( $meta['id'] ?? '', $point['point_key'] ?? '', $point['label'] ?? '', $base_slug, $slug ) as $ref ) {
                            $ref = sanitize_title( (string) $ref );
                            if ( '' !== $ref && ! isset( $slug_to_uuid[ $ref ] ) ) {
                                $slug_to_uuid[ $ref ] = $node_uuid;
                            }
                        }
                        $nodes[] = array(
                            'uuid'            => $node_uuid,
                            'slug'            => $slug,
                            'title'           => (string) ( $meta['title'] ?? $point['label'] ?? $slug ),
                            'label'           => (string) ( $meta['label'] ?? $point['label'] ?? '' ),
                            'node_type'       => (string) ( $meta['node_type'] ?? 'concept' ) ?: 'concept',
                            'node_subtype'    => (string) ( $meta['node_subtype'] ?? '' ),
                            'description'     => (string) ( $meta['description_html'] ?? $meta['description'] ?? '' ),
                            'main_image_id'   => (int) ( $meta['main_image'] ?? 0 ),
                            'other_image_ids' => (array) ( $meta['other_images'] ?? array() ),
                            'meta'            => $meta,
                        );
                    }

                    $edges = array();
                    if ( $has_relations ) {
                        $relations = $wpdb->get_results(
                            $wpdb->prepare( "SELECT * FROM {$legacy_relations} WHERE dataset_id = %d ORDER BY sort_order ASC,id ASC", $legacy_id ),
                            ARRAY_A
                        );
                        foreach ( $relations ?: array() as $relation ) {
                            $from = sanitize_title( (string) $relation['from_key'] );
                            $to   = sanitize_title( (string) $relation['to_key'] );
                            if ( empty( $slug_to_uuid[ $from ] ) || empty( $slug_to_uuid[ $to ] ) ) {
                                continue;
                            }
                            $edges[] = array(
                                'uuid'           => Support::uuid(),
                                'from_node_uuid' => $slug_to_uuid[ $from ],
                                'to_node_uuid'   => $slug_to_uuid[ $to ],
                                'relation_type'  => (string) ( $relation['relation_type'] ?? '' ),
                                'label'          => (string) ( $relation['label'] ?? '' ),
                                'direction'      => (string) ( $relation['direction'] ?? 'directed' ),
                                'intensity'      => (float) ( $relation['intensity'] ?? 1 ),
                                'meta'           => Support::json_decode_array( $relation['meta'] ?? '' ),
                            );
                        }
                    }
                    self::ensure_graph_registry_entries( $nodes, $edges );
                    $result = $repository->replace_payload( $dataset_id, array( 'nodes' => $nodes, 'relations' => $edges ), 1, 'Migrated from VisWiz 1.x dataset #' . $legacy_id );
                    if ( is_wp_error( $result ) ) {
                        $migration_ok = false;
                    }
                } else {
                    $rows = array();
                    foreach ( $points ?: array() as $point ) {
                        $meta   = Support::json_decode_array( $point['meta'] ?? '' );
                        $rows[] = array(
                            'uuid'      => Support::uuid(),
                            'row_key'   => (string) ( $point['point_key'] ?? '' ),
                            'label'     => (string) ( $point['label'] ?? '' ),
                            'value'     => isset( $point['value'] ) ? (float) $point['value'] : null,
                            'x_value'   => (string) ( $meta['x_value'] ?? $meta['x'] ?? '' ),
                            'x_numeric' => isset( $meta['x_numeric'] ) ? (float) $meta['x_numeric'] : null,
                            'y_value'   => isset( $meta['y'] ) ? (float) $meta['y'] : null,
                            'latitude'  => isset( $meta['lat'] ) ? (float) $meta['lat'] : null,
                            'longitude' => isset( $meta['lng'] ) ? (float) $meta['lng'] : null,
                            'color'     => (string) ( $point['color'] ?? '' ),
                            'meta'      => $meta,
                        );
                    }
                    $result = $repository->replace_payload( $dataset_id, array( 'rows' => $rows ), 1, 'Migrated from VisWiz 1.x dataset #' . $legacy_id );
                    if ( is_wp_error( $result ) ) {
                        $migration_ok = false;
                    }
                }
            }
        }

        if ( ! self::migrate_visualizations( $repository, $id_map ) ) {
            $migration_ok = false;
        }
        update_option( self::OPTION_LEGACY_MAP, $id_map, false );
        if ( $migration_ok ) {
            update_option( self::OPTION_MIGRATED, gmdate( 'c' ), false );
        }
    }

    private static function migrate_visualizations( DatasetRepository $repository, array $legacy_id_map ): bool {
        $migration_ok = true;
        $posts = get_posts(
            array(
                'post_type'      => 'viswiz_visualization',
                'post_status'    => 'any',
                'posts_per_page' => -1,
                'fields'         => 'ids',
            )
        );

        foreach ( $posts as $post_id ) {
            if ( metadata_exists( 'post', $post_id, '_viswiz_renderer' ) ) {
                continue;
            }

            $legacy_renderer = sanitize_key( (string) get_post_meta( $post_id, 'viswiz_type', true ) );
            $renderer        = Registry::renderer_exists( $legacy_renderer ) ? $legacy_renderer : 'pie';
            $legacy_dataset_id = absint( get_post_meta( $post_id, 'viswiz_dataset_id', true ) );
            $dataset_id        = $legacy_dataset_id && isset( $legacy_id_map[ $legacy_dataset_id ] ) ? absint( $legacy_id_map[ $legacy_dataset_id ] ) : 0;
            $source            = sanitize_key( (string) get_post_meta( $post_id, 'viswiz_source', true ) );

            if ( $legacy_dataset_id && ! $dataset_id ) {
                $migration_ok = false;
                continue;
            }
            if ( ! $dataset_id && 'manual' === $source ) {
                $dataset_id = self::migrate_private_post_data( $repository, $post_id, $renderer );
                if ( ! $dataset_id ) {
                    $migration_ok = false;
                    continue;
                }
            }

            update_post_meta( $post_id, '_viswiz_renderer', $renderer );
            update_post_meta( $post_id, '_viswiz_source_type', ( 'auto' === $source || 'woocommerce' === $source ) ? 'woo_live' : 'dataset' );
            update_post_meta( $post_id, '_viswiz_dataset_id', $dataset_id );

            $settings = array(
                'title'       => get_the_title( $post_id ),
                'full_screen' => true,
            );
            $legacy_format = Support::json_decode_array( get_post_meta( $post_id, 'viswiz_format_colors', true ) );
            if ( $legacy_format ) {
                $settings = array_merge( $settings, self::migrate_legacy_display_settings( $legacy_format ) );
            }
            update_post_meta( $post_id, '_viswiz_settings', $settings );
        }
        return $migration_ok;
    }

    private static function migrate_private_post_data( DatasetRepository $repository, int $post_id, string $renderer ): int {
        $schema = Registry::default_schema_for_renderer( $renderer );
        $name   = sprintf( 'Private dataset: %s', get_the_title( $post_id ) ?: '#' . $post_id );
        $id     = $repository->create(
            array(
                'name'        => $name,
                'description' => 'Automatically migrated private data from VisWiz 1.x.',
                'schema_type' => $schema,
                'source_type' => 'legacy',
                'settings'    => array( 'private' => true, 'owner_post_id' => $post_id ),
            )
        );
        if ( is_wp_error( $id ) ) {
            return 0;
        }

        if ( 'graph' === $schema ) {
            $graph = Support::json_decode_array( get_post_meta( $post_id, 'viswiz_graph_data', true ) );
            foreach ( (array) ( $graph['nodes'] ?? array() ) as $index => $node ) {
                if ( ! is_array( $node ) ) {
                    continue;
                }
                if ( empty( $node['node_type'] ) ) {
                    $graph['nodes'][ $index ]['node_type'] = 'concept';
                }
                if ( ! empty( $node['custom_labels'] ) ) {
                    $graph['nodes'][ $index ]['meta'] = is_array( $node['meta'] ?? null ) ? $node['meta'] : array();
                    if ( empty( $graph['nodes'][ $index ]['meta']['public_fields'] ) ) {
                        $graph['nodes'][ $index ]['meta']['public_fields'] = self::legacy_custom_labels_to_public_fields( (array) $node['custom_labels'] );
                    }
                }
            }
            self::ensure_graph_registry_entries( (array) ( $graph['nodes'] ?? array() ), (array) ( $graph['relations'] ?? $graph['links'] ?? array() ) );
            $result = $repository->replace_payload( (int) $id, $graph, 1, 'Migrated private graph data' );
            if ( is_wp_error( $result ) ) {
                $repository->delete_with_usage_cleanup( (int) $id );
                return 0;
            }
        } else {
            $raw  = 'progress' === $schema ? get_post_meta( $post_id, 'viswiz_manual_progress', true ) : get_post_meta( $post_id, 'viswiz_manual_pie', true );
            $data = Support::json_decode_array( $raw );
            $rows = isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : ( isset( $data['rows'] ) ? $data['rows'] : $data );
            $result = $repository->replace_payload( (int) $id, array( 'rows' => is_array( $rows ) ? $rows : array() ), 1, 'Migrated private manual data' );
            if ( is_wp_error( $result ) ) {
                $repository->delete_with_usage_cleanup( (int) $id );
                return 0;
            }
        }
        return (int) $id;
    }

    private static function legacy_custom_labels_to_public_fields( array $labels ): array {
        $fields = array();
        foreach ( $labels as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $type = sanitize_key( (string) ( $item['type'] ?? 'short' ) );
            if ( ! in_array( $type, array( 'short', 'long', 'url', 'formatted' ), true ) ) {
                $type = 'short';
            }
            $fields[] = array(
                'label' => sanitize_text_field( (string) ( $item['label'] ?? $item['key'] ?? '' ) ),
                'type'  => $type,
                'value' => (string) ( $item['value'] ?? '' ),
            );
        }
        return $fields;
    }

    private static function migrate_legacy_display_settings( array $legacy ): array {
        $settings = array();
        $color_map = array(
            'primary'    => 'primary_color',
            'secondary'  => 'secondary_color',
            'text'       => 'text_color',
            'background' => 'background_color',
        );
        foreach ( $color_map as $old_key => $new_key ) {
            if ( ! empty( $legacy[ $old_key ] ) ) {
                $settings[ $new_key ] = Support::sanitize_color( $legacy[ $old_key ], '' );
            }
        }
        $bool_map = array(
            'show_fullscreen_toggle' => 'full_screen',
            'show_graph_toolbar'     => 'show_graph_toolbar',
            'show_graph_search'      => 'show_graph_search',
            'show_graph_filters'     => 'show_graph_filters',
            'show_graph_zoom'        => 'show_graph_zoom',
            'show_node_images'       => 'show_node_images',
            'show_type_badges'       => 'show_type_badges',
            'show_relation_labels'   => 'show_relation_labels',
        );
        foreach ( $bool_map as $old_key => $new_key ) {
            if ( array_key_exists( $old_key, $legacy ) ) {
                $settings[ $new_key ] = ! empty( $legacy[ $old_key ] );
            }
        }
        foreach ( array( 'node_modal_title_fallback', 'node_modal_close_label', 'node_modal_previous_image_label', 'node_modal_next_image_label', 'node_modal_related_heading', 'node_modal_relation_fallback' ) as $key ) {
            if ( isset( $legacy[ $key ] ) && '' !== trim( (string) $legacy[ $key ] ) ) {
                $settings[ $key ] = sanitize_text_field( (string) $legacy[ $key ] );
            }
        }
        return $settings;
    }

    private static function migrate_legacy_registries(): void {
        if ( ! get_option( 'viswiz_node_type_schema_v2' ) ) {
            $legacy = get_option( 'viswiz_node_type_schema', array() );
            $schema = self::normalize_legacy_node_schema( is_array( $legacy ) ? $legacy : array() );
            if ( $schema ) {
                update_option( 'viswiz_node_type_schema_v2', $schema, false );
            }
        }
        if ( ! get_option( 'viswiz_relation_type_schema_v2' ) ) {
            $legacy = get_option( 'viswiz_relation_type_schema', array() );
            $schema = self::normalize_legacy_relation_schema( is_array( $legacy ) ? $legacy : array() );
            if ( $schema ) {
                update_option( 'viswiz_relation_type_schema_v2', $schema, false );
            }
        }
    }

    private static function normalize_legacy_node_schema( array $legacy ): array {
        $out = array();
        foreach ( $legacy as $key => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $slug = sanitize_key( (string) ( $item['slug'] ?? ( is_string( $key ) ? $key : '' ) ) );
            if ( '' === $slug ) {
                continue;
            }
            $subtypes = array();
            foreach ( (array) ( $item['subtypes'] ?? array() ) as $sub_key => $sub_value ) {
                if ( is_array( $sub_value ) ) {
                    $sub_slug  = sanitize_key( (string) ( $sub_value['slug'] ?? $sub_value['value'] ?? ( is_string( $sub_key ) ? $sub_key : '' ) ) );
                    $sub_label = sanitize_text_field( (string) ( $sub_value['label'] ?? $sub_slug ) );
                } else {
                    $sub_slug  = sanitize_key( (string) $sub_key );
                    $sub_label = sanitize_text_field( (string) $sub_value );
                }
                if ( '' !== $sub_slug ) {
                    $subtypes[ $sub_slug ] = $sub_label ?: $sub_slug;
                }
            }
            $out[ $slug ] = array(
                'slug'        => $slug,
                'label'       => sanitize_text_field( (string) ( $item['label'] ?? $slug ) ),
                'description' => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
                'color'       => Support::sanitize_color( $item['color'] ?? '', '#2563eb' ),
                'subtypes'    => $subtypes,
            );
        }
        return $out;
    }

    private static function normalize_legacy_relation_schema( array $legacy ): array {
        $out = array();
        foreach ( $legacy as $key => $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $slug = sanitize_key( (string) ( $item['slug'] ?? ( is_string( $key ) ? $key : '' ) ) );
            if ( '' === $slug ) {
                continue;
            }
            $direction = sanitize_key( (string) ( $item['direction'] ?? 'directed' ) );
            if ( ! in_array( $direction, array( 'directed', 'bidirectional', 'undirected' ), true ) ) {
                $direction = 'directed';
            }
            $out[ $slug ] = array(
                'slug'           => $slug,
                'label'          => sanitize_text_field( (string) ( $item['label'] ?? $slug ) ),
                'inverse_label'  => sanitize_text_field( (string) ( $item['inverse_label'] ?? '' ) ),
                'description'    => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
                'direction'      => $direction,
                'intensity'      => max( 0.1, min( 20, (float) ( $item['default_intensity'] ?? $item['intensity'] ?? 1 ) ) ),
                'source_type'    => sanitize_key( (string) ( $item['source_type'] ?? '' ) ),
                'source_subtype' => sanitize_key( (string) ( $item['source_subtype'] ?? '' ) ),
                'target_type'    => sanitize_key( (string) ( $item['target_type'] ?? '' ) ),
                'target_subtype' => sanitize_key( (string) ( $item['target_subtype'] ?? '' ) ),
            );
        }
        return $out;
    }

    private static function ensure_graph_registry_entries( array $nodes, array $edges ): void {
        $node_schema = Registry::node_types();
        $changed     = false;
        foreach ( $nodes as $node ) {
            $type = sanitize_key( (string) ( $node['node_type'] ?? '' ) );
            if ( '' === $type ) {
                continue;
            }
            if ( ! isset( $node_schema[ $type ] ) ) {
                $node_schema[ $type ] = array( 'slug' => $type, 'label' => ucwords( str_replace( '_', ' ', $type ) ), 'description' => 'Migrated from VisWiz 1.x.', 'color' => '#475569', 'subtypes' => array() );
                $changed = true;
            }
            $subtype = sanitize_key( (string) ( $node['node_subtype'] ?? '' ) );
            if ( '' !== $subtype && ! isset( $node_schema[ $type ]['subtypes'][ $subtype ] ) ) {
                $node_schema[ $type ]['subtypes'][ $subtype ] = ucwords( str_replace( '_', ' ', $subtype ) );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( 'viswiz_node_type_schema_v2', $node_schema, false );
        }

        $relation_schema = Registry::relation_types();
        $changed         = false;
        foreach ( $edges as $edge ) {
            $type = sanitize_key( (string) ( $edge['relation_type'] ?? '' ) );
            if ( '' !== $type && ! isset( $relation_schema[ $type ] ) ) {
                $direction = sanitize_key( (string) ( $edge['direction'] ?? 'directed' ) );
                if ( ! in_array( $direction, array( 'directed', 'bidirectional', 'undirected' ), true ) ) {
                    $direction = 'directed';
                }
                $relation_schema[ $type ] = array(
                    'slug' => $type, 'label' => ucwords( str_replace( '_', ' ', $type ) ), 'inverse_label' => '', 'description' => 'Migrated from VisWiz 1.x.',
                    'direction' => $direction, 'intensity' => max( 0.1, min( 20, (float) ( $edge['intensity'] ?? 1 ) ) ),
                    'source_type' => '', 'source_subtype' => '', 'target_type' => '', 'target_subtype' => '',
                );
                $changed = true;
            }
        }
        if ( $changed ) {
            update_option( 'viswiz_relation_type_schema_v2', $relation_schema, false );
        }
    }

    private static function legacy_schema_type( string $legacy_type, int $dataset_id, string $relations_table ): string {
        if ( in_array( $legacy_type, array( 'graph', 'flow_diagram', 'org_chart' ), true ) ) {
            return 'graph';
        }
        if ( 'progress' === $legacy_type ) {
            return 'progress';
        }
        if ( in_array( $legacy_type, array( 'line', 'area', 'timeline' ), true ) ) {
            return 'time_series';
        }
        if ( 'scatter' === $legacy_type ) {
            return 'xy';
        }
        if ( 'map' === $legacy_type ) {
            return 'geo';
        }
        if ( 'diagram' === $legacy_type ) {
            return 'diagram';
        }
        if ( $relations_table ) {
            global $wpdb;
            $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$relations_table} WHERE dataset_id = %d", $dataset_id ) );
            if ( $count > 0 ) {
                return 'graph';
            }
        }
        return Registry::schema_exists( $legacy_type ) ? $legacy_type : 'categorical';
    }

    private static function table_exists( string $table ): bool {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }
}
