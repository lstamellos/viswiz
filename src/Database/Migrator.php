<?php
namespace VisWiz\Database;

use VisWiz\Domain\Registry;
use VisWiz\Support;

final class Migrator {
    private const OPTION_DB_VERSION = 'viswiz_db_schema_version';
    private const OPTION_MIGRATED   = 'viswiz_v2_legacy_migrated';

    public static function maybe_upgrade(): void {
        if ( (int) get_option( self::OPTION_DB_VERSION, 0 ) < VISWIZ_DB_VERSION ) {
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
                KEY uuid (uuid),
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
        $datasets = Support::table( 'datasets' );
        if ( ! self::table_exists( $datasets ) ) {
            return;
        }

        $legacy_points    = Support::table( 'data_points' );
        $legacy_relations = Support::table( 'relations' );
        $has_points       = self::table_exists( $legacy_points );
        $has_relations    = self::table_exists( $legacy_relations );
        $repository       = new DatasetRepository();

        $records = $wpdb->get_results( "SELECT * FROM {$datasets} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( $records as $record ) {
            $dataset_id = (int) $record['id'];
            $uuid       = ! empty( $record['uuid'] ) ? Support::uuid( $record['uuid'] ) : Support::uuid();
            $legacy_type = sanitize_key( (string) ( $record['data_type'] ?? $record['schema_type'] ?? '' ) );
            $schema      = self::legacy_schema_type( $legacy_type, $dataset_id, $has_relations ? $legacy_relations : '' );

            $wpdb->update(
                $datasets,
                array(
                    'uuid'        => $uuid,
                    'schema_type' => $schema,
                    'source_type' => 'manual',
                    'revision'    => max( 1, (int) ( $record['revision'] ?? 1 ) ),
                    'settings'    => $record['settings'] ?? '{}',
                ),
                array( 'id' => $dataset_id ),
                array( '%s', '%s', '%s', '%d', '%s' ),
                array( '%d' )
            );

            if ( ! $has_points || $repository->has_v2_data( $dataset_id ) ) {
                continue;
            }

            $points = $wpdb->get_results(
                $wpdb->prepare( "SELECT * FROM {$legacy_points} WHERE dataset_id = %d ORDER BY sort_order ASC,id ASC", $dataset_id ),
                ARRAY_A
            );

            if ( 'graph' === $schema ) {
                $slug_to_uuid = array();
                $nodes        = array();
                foreach ( $points as $point ) {
                    $meta = Support::json_decode_array( $point['meta'] ?? '' );
                    $slug = sanitize_title( (string) ( $meta['id'] ?? $point['point_key'] ?? $point['label'] ?? '' ) );
                    if ( '' === $slug ) {
                        $slug = 'node-' . ( count( $nodes ) + 1 );
                    }
                    $node_uuid            = Support::uuid( (string) ( $meta['uuid'] ?? '' ) );
                    $slug_to_uuid[ $slug ] = $node_uuid;
                    $nodes[] = array(
                        'uuid'            => $node_uuid,
                        'slug'            => $slug,
                        'title'           => (string) ( $meta['title'] ?? $point['label'] ?? $slug ),
                        'label'           => (string) ( $meta['label'] ?? $point['label'] ?? '' ),
                        'node_type'       => (string) ( $meta['node_type'] ?? '' ),
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
                        $wpdb->prepare( "SELECT * FROM {$legacy_relations} WHERE dataset_id = %d ORDER BY sort_order ASC,id ASC", $dataset_id ),
                        ARRAY_A
                    );
                    foreach ( $relations as $relation ) {
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
                $repository->replace_payload( $dataset_id, array( 'nodes' => $nodes, 'relations' => $edges ), null, 'Migrated from VisWiz 1.x' );
            } else {
                $rows = array();
                foreach ( $points as $point ) {
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
                $repository->replace_payload( $dataset_id, array( 'rows' => $rows ), null, 'Migrated from VisWiz 1.x' );
            }
        }

        self::migrate_visualizations( $repository );
        update_option( self::OPTION_MIGRATED, gmdate( 'c' ), false );
    }

    private static function migrate_visualizations( DatasetRepository $repository ): void {
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
            $dataset_id      = absint( get_post_meta( $post_id, 'viswiz_dataset_id', true ) );
            $source          = sanitize_key( (string) get_post_meta( $post_id, 'viswiz_source', true ) );

            if ( ! $dataset_id && 'manual' === $source ) {
                $dataset_id = self::migrate_private_post_data( $repository, $post_id, $renderer );
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
                $settings['legacy_format'] = $legacy_format;
            }
            update_post_meta( $post_id, '_viswiz_settings', $settings );
        }
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
            $repository->replace_payload( (int) $id, $graph, null, 'Migrated private graph data' );
        } else {
            $raw  = 'progress' === $schema ? get_post_meta( $post_id, 'viswiz_manual_progress', true ) : get_post_meta( $post_id, 'viswiz_manual_pie', true );
            $data = Support::json_decode_array( $raw );
            $rows = isset( $data['values'] ) && is_array( $data['values'] ) ? $data['values'] : ( isset( $data['rows'] ) ? $data['rows'] : $data );
            $repository->replace_payload( (int) $id, array( 'rows' => is_array( $rows ) ? $rows : array() ), null, 'Migrated private manual data' );
        }
        return (int) $id;
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
