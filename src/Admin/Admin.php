<?php
namespace VisWiz\Admin;

use VisWiz\Database\DatasetRepository;
use VisWiz\Domain\GraphValidator;
use VisWiz\Domain\Registry;
use VisWiz\Frontend\Frontend;
use VisWiz\Support;

final class Admin {
    public static function register(): void {
        add_action( 'admin_menu', array( self::class, 'menu' ), 5 );
        add_action( 'add_meta_boxes_viswiz_visualization', array( self::class, 'visualization_meta_box' ) );
        add_action( 'save_post_viswiz_visualization', array( self::class, 'save_visualization' ), 10, 2 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
        add_action( 'admin_post_viswiz_dataset_create', array( self::class, 'create_dataset' ) );
        add_action( 'admin_post_viswiz_dataset_update', array( self::class, 'update_dataset' ) );
        add_action( 'admin_post_viswiz_dataset_duplicate', array( self::class, 'duplicate_dataset' ) );
        add_action( 'admin_post_viswiz_dataset_delete', array( self::class, 'delete_dataset' ) );
        add_action( 'admin_post_viswiz_dataset_export', array( self::class, 'export_dataset' ) );
        add_action( 'admin_post_viswiz_save_node_schema', array( self::class, 'save_node_schema' ) );
        add_action( 'admin_post_viswiz_save_relation_schema', array( self::class, 'save_relation_schema' ) );
        add_action( 'admin_post_viswiz_save_settings', array( self::class, 'save_settings' ) );
        add_filter( 'manage_viswiz_visualization_posts_columns', array( self::class, 'visualization_columns' ) );
        add_action( 'manage_viswiz_visualization_posts_custom_column', array( self::class, 'visualization_column' ), 10, 2 );
    }

    public static function menu(): void {
        add_menu_page(
            __( 'VisWiz', 'viswiz' ),
            __( 'VisWiz', 'viswiz' ),
            'edit_viswiz_visualizations',
            'viswiz',
            array( self::class, 'dashboard_page' ),
            'dashicons-chart-area',
            58
        );
        add_submenu_page( 'viswiz', __( 'Dashboard', 'viswiz' ), __( 'Dashboard', 'viswiz' ), 'edit_viswiz_visualizations', 'viswiz', array( self::class, 'dashboard_page' ) );
        add_submenu_page( 'viswiz', __( 'Datasets', 'viswiz' ), __( 'Datasets', 'viswiz' ), 'edit_viswiz_datasets', 'viswiz-datasets', array( self::class, 'datasets_page' ) );
        add_submenu_page( 'viswiz', __( 'Node types', 'viswiz' ), __( 'Node types', 'viswiz' ), 'manage_viswiz_schema', 'viswiz-node-types', array( self::class, 'node_types_page' ) );
        add_submenu_page( 'viswiz', __( 'Relation types', 'viswiz' ), __( 'Relation types', 'viswiz' ), 'manage_viswiz_schema', 'viswiz-relation-types', array( self::class, 'relation_types_page' ) );
        add_submenu_page( 'viswiz', __( 'Settings', 'viswiz' ), __( 'Settings', 'viswiz' ), 'manage_viswiz_settings', 'viswiz-settings', array( self::class, 'settings_page' ) );
    }

    public static function assets( string $hook ): void {
        $screen = get_current_screen();
        $is_viswiz_post = $screen && 'viswiz_visualization' === $screen->post_type;
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $is_viswiz_page = str_starts_with( $page, 'viswiz' );
        if ( ! $is_viswiz_post && ! $is_viswiz_page ) {
            return;
        }

        wp_enqueue_style( 'viswiz-admin-v2', VISWIZ_URL . 'assets/viswiz-admin.css', array(), VISWIZ_VERSION );
        wp_enqueue_script( 'viswiz-admin-v2', VISWIZ_URL . 'assets/viswiz-admin.js', array( 'wp-i18n' ), VISWIZ_VERSION, true );
        wp_set_script_translations( 'viswiz-admin-v2', 'viswiz', VISWIZ_DIR . 'languages' );
        wp_localize_script(
            'viswiz-admin-v2',
            'VisWizAdminV2',
            array(
                'restUrl'       => esc_url_raw( rest_url( 'viswiz/v2' ) ),
                'nonce'         => wp_create_nonce( 'wp_rest' ),
                'schemas'       => Registry::schemas(),
                'renderers'     => Registry::renderers(),
                'nodeTypes'     => Registry::node_types(),
                'relationTypes' => Registry::relation_types(),
            )
        );

        if ( 'viswiz-datasets' === $page && isset( $_GET['dataset_id'] ) ) {
            wp_enqueue_style( 'viswiz-frontend', VISWIZ_URL . 'assets/viswiz.css', array(), VISWIZ_VERSION );
            wp_enqueue_script( 'viswiz-frontend', VISWIZ_URL . 'assets/viswiz.js', array(), VISWIZ_VERSION, true );
        }
        if ( $is_viswiz_post || ( 'viswiz-datasets' === $page && isset( $_GET['dataset_id'] ) ) ) {
            wp_enqueue_media();
        }
    }

    public static function dashboard_page(): void {
        if ( ! current_user_can( 'edit_viswiz_visualizations' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
        }
        $repo         = new DatasetRepository();
        $dataset_count = $repo->count();
        $visualizations = wp_count_posts( 'viswiz_visualization' );
        $published    = isset( $visualizations->publish ) ? (int) $visualizations->publish : 0;
        ?>
        <div class="wrap viswiz-admin-wrap">
            <h1><?php esc_html_e( 'VisWiz', 'viswiz' ); ?></h1>
            <p class="viswiz-lead"><?php esc_html_e( 'Datasets are canonical data sources. Visualizations only choose a renderer, data source and display settings.', 'viswiz' ); ?></p>
            <div class="viswiz-kpis">
                <div><strong><?php echo esc_html( (string) $dataset_count ); ?></strong><span><?php esc_html_e( 'Datasets', 'viswiz' ); ?></span></div>
                <div><strong><?php echo esc_html( (string) $published ); ?></strong><span><?php esc_html_e( 'Published visualizations', 'viswiz' ); ?></span></div>
                <div><strong><?php echo esc_html( (string) VISWIZ_DB_VERSION ); ?></strong><span><?php esc_html_e( 'DB schema', 'viswiz' ); ?></span></div>
            </div>
            <div class="viswiz-admin-grid">
                <section class="viswiz-card"><h2><?php esc_html_e( '1. Build or import data', 'viswiz' ); ?></h2><p><?php esc_html_e( 'Create a dataset with an explicit schema: categorical, time series, X/Y, geographic, progress, graph or diagram.', 'viswiz' ); ?></p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>"><?php esc_html_e( 'Manage datasets', 'viswiz' ); ?></a></section>
                <section class="viswiz-card"><h2><?php esc_html_e( '2. Create a visualization', 'viswiz' ); ?></h2><p><?php esc_html_e( 'Choose a renderer independently from the dataset schema. Incompatible combinations are rejected server-side.', 'viswiz' ); ?></p><a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=viswiz_visualization' ) ); ?>"><?php esc_html_e( 'New visualization', 'viswiz' ); ?></a></section>
                <section class="viswiz-card"><h2><?php esc_html_e( '3. Publish', 'viswiz' ); ?></h2><p><?php esc_html_e( 'Embed with the VisWiz block or shortcode. Assets load only when a visualization is actually rendered.', 'viswiz' ); ?></p></section>
            </div>
        </div>
        <?php
    }

    public static function visualization_meta_box(): void {
        add_meta_box( 'viswiz-v2-config', __( 'VisWiz configuration', 'viswiz' ), array( self::class, 'render_visualization_meta_box' ), 'viswiz_visualization', 'normal', 'high' );
    }

    public static function render_visualization_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'viswiz_save_visualization', 'viswiz_nonce' );
        $renderer   = sanitize_key( (string) get_post_meta( $post->ID, '_viswiz_renderer', true ) ) ?: 'pie';
        $source     = sanitize_key( (string) get_post_meta( $post->ID, '_viswiz_source_type', true ) ) ?: 'dataset';
        $dataset_id = absint( get_post_meta( $post->ID, '_viswiz_dataset_id', true ) );
        $settings   = Frontend::sanitize_settings( get_post_meta( $post->ID, '_viswiz_settings', true ) );
        $woo        = Support::json_decode_array( get_post_meta( $post->ID, '_viswiz_woo_config', true ) );
        $repo       = new DatasetRepository();
        $datasets   = $repo->list_with_counts( array( 'limit' => 200 ) );
        $error      = (string) get_post_meta( $post->ID, '_viswiz_last_validation_error', true );
        delete_post_meta( $post->ID, '_viswiz_last_validation_error' );
        if ( $error ) {
            echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
        }
        ?>
        <div class="viswiz-visualization-config" data-viswiz-visualization-config>
            <div class="viswiz-form-grid">
                <label><span><?php esc_html_e( 'Renderer', 'viswiz' ); ?></span>
                    <select name="viswiz_renderer" data-viswiz-renderer>
                        <?php foreach ( Registry::renderers() as $key => $meta ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $renderer, $key ); ?> data-schemas="<?php echo esc_attr( implode( ',', $meta['schemas'] ) ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span><?php esc_html_e( 'Data source', 'viswiz' ); ?></span>
                    <select name="viswiz_source_type" data-viswiz-source>
                        <option value="dataset" <?php selected( $source, 'dataset' ); ?>><?php esc_html_e( 'Dataset', 'viswiz' ); ?></option>
                        <option value="woo_live" <?php selected( $source, 'woo_live' ); ?>><?php esc_html_e( 'WooCommerce live', 'viswiz' ); ?></option>
                    </select>
                </label>
            </div>

            <section class="viswiz-config-section" data-viswiz-source-panel="dataset">
                <h3><?php esc_html_e( 'Dataset', 'viswiz' ); ?></h3>
                <label class="viswiz-field"><span><?php esc_html_e( 'Canonical dataset', 'viswiz' ); ?></span>
                    <select name="viswiz_dataset_id" data-viswiz-dataset-select>
                        <option value="0"><?php esc_html_e( 'Select dataset', 'viswiz' ); ?></option>
                        <?php foreach ( $datasets as $dataset ) : ?>
                            <option value="<?php echo esc_attr( (string) $dataset['id'] ); ?>" data-schema="<?php echo esc_attr( $dataset['schema_type'] ); ?>" <?php selected( $dataset_id, $dataset['id'] ); ?>><?php echo esc_html( $dataset['name'] . ' — ' . $dataset['schema_type'] . ' (r' . $dataset['revision'] . ')' ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ( $dataset_id ) : ?>
                    <p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $dataset_id ) ); ?>"><?php esc_html_e( 'Open dataset editor', 'viswiz' ); ?></a></p>
                <?php endif; ?>
                <p class="description"><?php esc_html_e( 'Data is edited in the dataset, not copied into the visualization. Multiple visualizations can safely reuse the same dataset.', 'viswiz' ); ?></p>
            </section>

            <section class="viswiz-config-section" data-viswiz-source-panel="woo_live">
                <h3><?php esc_html_e( 'WooCommerce live query', 'viswiz' ); ?></h3>
                <?php self::render_woo_fields( $woo ); ?>
                <p class="description"><?php esc_html_e( 'The public endpoint executes only this saved query. Visitors cannot submit arbitrary WooCommerce filters.', 'viswiz' ); ?></p>
            </section>

            <section class="viswiz-config-section">
                <h3><?php esc_html_e( 'Display', 'viswiz' ); ?></h3>
                <div class="viswiz-form-grid">
                    <label><span><?php esc_html_e( 'Primary color', 'viswiz' ); ?></span><input type="color" name="viswiz_settings[primary_color]" value="<?php echo esc_attr( $settings['primary_color'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Secondary color', 'viswiz' ); ?></span><input type="color" name="viswiz_settings[secondary_color]" value="<?php echo esc_attr( $settings['secondary_color'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Text color', 'viswiz' ); ?></span><input type="color" name="viswiz_settings[text_color]" value="<?php echo esc_attr( $settings['text_color'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Background', 'viswiz' ); ?></span><input type="color" name="viswiz_settings[background_color]" value="<?php echo esc_attr( $settings['background_color'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Progress target', 'viswiz' ); ?></span><input type="number" step="0.01" name="viswiz_settings[target]" value="<?php echo esc_attr( (string) $settings['target'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Live refresh (ms)', 'viswiz' ); ?></span><input type="number" min="60000" max="1800000" step="1000" name="viswiz_settings[refresh_ms]" value="<?php echo esc_attr( (string) $settings['refresh_ms'] ); ?>"></label>
                </div>
                <div class="viswiz-form-grid viswiz-graph-modal-labels">
                    <label><span><?php esc_html_e( 'Node modal fallback title', 'viswiz' ); ?></span><input type="text" name="viswiz_settings[node_modal_title_fallback]" value="<?php echo esc_attr( $settings['node_modal_title_fallback'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Node modal close label', 'viswiz' ); ?></span><input type="text" name="viswiz_settings[node_modal_close_label]" value="<?php echo esc_attr( $settings['node_modal_close_label'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Previous image label', 'viswiz' ); ?></span><input type="text" name="viswiz_settings[node_modal_previous_image_label]" value="<?php echo esc_attr( $settings['node_modal_previous_image_label'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Next image label', 'viswiz' ); ?></span><input type="text" name="viswiz_settings[node_modal_next_image_label]" value="<?php echo esc_attr( $settings['node_modal_next_image_label'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Related nodes heading', 'viswiz' ); ?></span><input type="text" name="viswiz_settings[node_modal_related_heading]" value="<?php echo esc_attr( $settings['node_modal_related_heading'] ); ?>"></label>
                    <label><span><?php esc_html_e( 'Relation fallback label', 'viswiz' ); ?></span><input type="text" name="viswiz_settings[node_modal_relation_fallback]" value="<?php echo esc_attr( $settings['node_modal_relation_fallback'] ); ?>"></label>
                </div>
                <div class="viswiz-checks">
                    <?php self::checkbox( 'viswiz_settings[full_screen]', $settings['full_screen'], __( 'Full-screen control', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_legend]', $settings['show_legend'], __( 'Legend', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_graph_toolbar]', $settings['show_graph_toolbar'], __( 'Graph exploration toolbar', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_graph_search]', $settings['show_graph_search'], __( 'Graph text search', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_graph_filters]', $settings['show_graph_filters'], __( 'Graph type/relation filters', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_graph_zoom]', $settings['show_graph_zoom'], __( 'Graph zoom controls', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_node_images]', $settings['show_node_images'], __( 'Node images in detail dialogs', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_type_badges]', $settings['show_type_badges'], __( 'Node type/subtype labels', 'viswiz' ) ); ?>
                    <?php self::checkbox( 'viswiz_settings[show_relation_labels]', $settings['show_relation_labels'], __( 'Relation labels', 'viswiz' ) ); ?>
                </div>
            </section>

            <?php if ( $post->ID ) : ?>
                <section class="viswiz-config-section"><h3><?php esc_html_e( 'Embed', 'viswiz' ); ?></h3><code>[viswiz_visualization id="<?php echo esc_html( (string) $post->ID ); ?>"]</code></section>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function save_visualization( int $post_id, \WP_Post $post ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! isset( $_POST['viswiz_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['viswiz_nonce'] ) ), 'viswiz_save_visualization' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $renderer = sanitize_key( wp_unslash( $_POST['viswiz_renderer'] ?? 'pie' ) );
        $source   = sanitize_key( wp_unslash( $_POST['viswiz_source_type'] ?? 'dataset' ) );
        if ( ! Registry::renderer_exists( $renderer ) ) {
            $renderer = 'pie';
        }
        if ( ! in_array( $source, array( 'dataset', 'woo_live' ), true ) ) {
            $source = 'dataset';
        }

        $dataset_id = absint( $_POST['viswiz_dataset_id'] ?? 0 );
        if ( 'dataset' === $source ) {
            $repo    = new DatasetRepository();
            $dataset = $repo->get( $dataset_id );
            if ( ! $dataset ) {
                update_post_meta( $post_id, '_viswiz_last_validation_error', __( 'Select an existing dataset before saving.', 'viswiz' ) );
                $dataset_id = 0;
            } elseif ( ! Registry::renderer_supports_schema( $renderer, $dataset['schema_type'] ) ) {
                update_post_meta( $post_id, '_viswiz_last_validation_error', __( 'The selected renderer is not compatible with the dataset schema.', 'viswiz' ) );
                $dataset_id = 0;
            }
        } else {
            $dataset_id = 0;
            if ( in_array( $renderer, array( 'graph', 'flow_diagram', 'org_chart', 'map', 'scatter', 'diagram' ), true ) ) {
                update_post_meta( $post_id, '_viswiz_last_validation_error', __( 'This renderer requires a dataset and cannot use WooCommerce live data.', 'viswiz' ) );
                $source = 'dataset';
            }
        }

        update_post_meta( $post_id, '_viswiz_renderer', $renderer );
        update_post_meta( $post_id, '_viswiz_source_type', $source );
        update_post_meta( $post_id, '_viswiz_dataset_id', $dataset_id );
        update_post_meta( $post_id, '_viswiz_settings', Frontend::sanitize_settings( wp_unslash( $_POST['viswiz_settings'] ?? array() ) ) );
        update_post_meta( $post_id, '_viswiz_woo_config', self::woo_config_from_post() );
    }

    public static function datasets_page(): void {
        if ( ! current_user_can( 'edit_viswiz_datasets' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
        }
        $dataset_id = absint( $_GET['dataset_id'] ?? 0 );
        if ( $dataset_id ) {
            self::dataset_detail_page( $dataset_id );
            return;
        }

        $repo   = new DatasetRepository();
        $search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
        $schema = sanitize_key( wp_unslash( $_GET['schema_type'] ?? '' ) );
        $paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $limit  = 30;
        $items  = $repo->list_with_counts( array( 'search' => $search, 'schema_type' => $schema, 'limit' => $limit, 'offset' => ( $paged - 1 ) * $limit ) );
        $total  = $repo->count( array( 'search' => $search, 'schema_type' => $schema ) );
        ?>
        <div class="wrap viswiz-admin-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Datasets', 'viswiz' ); ?></h1>
            <div class="viswiz-admin-grid viswiz-datasets-layout">
                <section>
                    <form method="get" class="viswiz-filter-bar">
                        <input type="hidden" name="page" value="viswiz-datasets">
                        <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search datasets', 'viswiz' ); ?>">
                        <select name="schema_type"><option value=""><?php esc_html_e( 'All schemas', 'viswiz' ); ?></option><?php foreach ( Registry::schemas() as $key => $meta ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $schema, $key ); ?>><?php echo esc_html( $meta['label'] ); ?></option><?php endforeach; ?></select>
                        <button class="button"><?php esc_html_e( 'Filter', 'viswiz' ); ?></button>
                    </form>
                    <table class="widefat striped viswiz-table"><thead><tr><th><?php esc_html_e( 'Dataset', 'viswiz' ); ?></th><th><?php esc_html_e( 'Schema', 'viswiz' ); ?></th><th><?php esc_html_e( 'Items', 'viswiz' ); ?></th><th><?php esc_html_e( 'Relations', 'viswiz' ); ?></th><th><?php esc_html_e( 'Visualizations', 'viswiz' ); ?></th><th><?php esc_html_e( 'Revision', 'viswiz' ); ?></th><th></th></tr></thead><tbody>
                    <?php if ( ! $items ) : ?><tr><td colspan="7"><?php esc_html_e( 'No datasets found.', 'viswiz' ); ?></td></tr><?php endif; ?>
                    <?php foreach ( $items as $item ) : ?>
                        <tr><td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $item['id'] ) ); ?>"><?php echo esc_html( $item['name'] ); ?></a></strong><br><span class="description"><?php echo esc_html( wp_trim_words( $item['description'], 14 ) ); ?></span></td><td><code><?php echo esc_html( $item['schema_type'] ); ?></code></td><td><?php echo esc_html( (string) ( 'graph' === $item['schema_type'] ? $item['node_count'] : $item['row_count'] ) ); ?></td><td><?php echo esc_html( (string) $item['relation_count'] ); ?></td><td><?php echo esc_html( (string) $item['visualization_count'] ); ?></td><td>r<?php echo esc_html( (string) $item['revision'] ); ?></td><td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $item['id'] ) ); ?>"><?php esc_html_e( 'Manage', 'viswiz' ); ?></a></td></tr>
                    <?php endforeach; ?>
                    </tbody></table>
                    <?php
                    echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $paged, 'total' => max( 1, (int) ceil( $total / $limit ) ) ) ) );
                    ?>
                </section>
                <aside class="viswiz-card">
                    <h2><?php esc_html_e( 'Create dataset', 'viswiz' ); ?></h2>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="viswiz_dataset_create"><?php wp_nonce_field( 'viswiz_dataset_create' ); ?>
                        <label class="viswiz-field"><span><?php esc_html_e( 'Name', 'viswiz' ); ?></span><input required type="text" name="name"></label>
                        <label class="viswiz-field"><span><?php esc_html_e( 'Schema', 'viswiz' ); ?></span><select name="schema_type"><?php foreach ( Registry::schemas() as $key => $meta ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta['label'] ); ?></option><?php endforeach; ?></select></label>
                        <label class="viswiz-field"><span><?php esc_html_e( 'Description', 'viswiz' ); ?></span><textarea name="description" rows="4"></textarea></label>
                        <button class="button button-primary"><?php esc_html_e( 'Create dataset', 'viswiz' ); ?></button>
                    </form>
                </aside>
            </div>
        </div>
        <?php
    }

    private static function dataset_detail_page( int $dataset_id ): void {
        $repo    = new DatasetRepository();
        $dataset = $repo->get( $dataset_id );
        if ( ! $dataset ) {
            wp_die( esc_html__( 'Dataset not found.', 'viswiz' ) );
        }
        $payload   = $repo->get_payload( $dataset_id );
        $revisions = $repo->revisions( $dataset_id );
        $issues    = 'graph' === $dataset['schema_type'] ? GraphValidator::validate( $payload, Registry::node_types(), Registry::relation_types() ) : array();
        ?>
        <div class="wrap viswiz-admin-wrap">
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>">&larr; <?php esc_html_e( 'All datasets', 'viswiz' ); ?></a></p>
            <h1><?php echo esc_html( $dataset['name'] ); ?> <small>r<?php echo esc_html( (string) $dataset['revision'] ); ?></small></h1>
            <div class="viswiz-admin-grid viswiz-dataset-detail-grid">
                <main>
                    <section class="viswiz-card">
                        <h2><?php esc_html_e( 'Dataset metadata', 'viswiz' ); ?></h2>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="viswiz_dataset_update"><input type="hidden" name="dataset_id" value="<?php echo esc_attr( (string) $dataset_id ); ?>"><input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $dataset['revision'] ); ?>"><?php wp_nonce_field( 'viswiz_dataset_update_' . $dataset_id ); ?>
                            <div class="viswiz-form-grid"><label><span><?php esc_html_e( 'Name', 'viswiz' ); ?></span><input required name="name" value="<?php echo esc_attr( $dataset['name'] ); ?>"></label><label><span><?php esc_html_e( 'Schema', 'viswiz' ); ?></span><input readonly value="<?php echo esc_attr( $dataset['schema_type'] ); ?>"></label></div>
                            <label class="viswiz-field"><span><?php esc_html_e( 'Description', 'viswiz' ); ?></span><textarea name="description" rows="3"><?php echo esc_textarea( $dataset['description'] ); ?></textarea></label>
                            <button class="button button-primary"><?php esc_html_e( 'Save metadata', 'viswiz' ); ?></button>
                        </form>
                    </section>

                    <section class="viswiz-card">
                        <div class="viswiz-section-heading"><div><h2><?php echo 'graph' === $dataset['schema_type'] ? esc_html__( 'Graph data', 'viswiz' ) : esc_html__( 'Dataset rows', 'viswiz' ); ?></h2><p><?php esc_html_e( 'Each item is saved independently. Revision checks prevent last-write-wins overwrites.', 'viswiz' ); ?></p></div><input type="search" data-viswiz-dataset-search placeholder="<?php esc_attr_e( 'Search data', 'viswiz' ); ?>"></div>
                        <div id="viswiz-dataset-editor" data-dataset-id="<?php echo esc_attr( (string) $dataset_id ); ?>" data-schema="<?php echo esc_attr( $dataset['schema_type'] ); ?>" data-revision="<?php echo esc_attr( (string) $dataset['revision'] ); ?>"></div>
                        <script type="application/json" id="viswiz-dataset-payload"><?php echo wp_json_encode( $payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?></script>
                    </section>

                    <?php if ( 'graph' === $dataset['schema_type'] ) : ?>
                        <section class="viswiz-card"><h2><?php esc_html_e( 'Graph preview', 'viswiz' ); ?></h2><div class="viswiz-visualization" data-viswiz-inline-spec></div></section>
                    <?php endif; ?>
                </main>
                <aside>
                    <section class="viswiz-card">
                        <h2><?php esc_html_e( 'Integrity', 'viswiz' ); ?></h2>
                        <?php if ( ! $issues ) : ?><p class="viswiz-ok"><?php esc_html_e( 'No graph integrity issues found.', 'viswiz' ); ?></p><?php else : ?><ul class="viswiz-issues"><?php foreach ( $issues as $issue ) : ?><li class="is-<?php echo esc_attr( $issue['severity'] ); ?>"><?php echo esc_html( $issue['message'] ); ?></li><?php endforeach; ?></ul><?php endif; ?>
                    </section>
                    <section class="viswiz-card"><h2><?php esc_html_e( 'Import / export', 'viswiz' ); ?></h2><p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_dataset_export&dataset_id=' . $dataset_id ), 'viswiz_dataset_export_' . $dataset_id ) ); ?>"><?php esc_html_e( 'Export JSON', 'viswiz' ); ?></a></p><label class="viswiz-field"><span><?php esc_html_e( 'Replace from JSON', 'viswiz' ); ?></span><textarea rows="7" data-viswiz-import-json placeholder='{"rows": [...]}'></textarea></label><button type="button" class="button" data-viswiz-import-button><?php esc_html_e( 'Validate & replace', 'viswiz' ); ?></button></section>
                    <section class="viswiz-card"><h2><?php esc_html_e( 'WooCommerce snapshot', 'viswiz' ); ?></h2><?php self::render_woo_fields( array() ); ?><button type="button" class="button" data-viswiz-commerce-snapshot><?php esc_html_e( 'Replace dataset with snapshot', 'viswiz' ); ?></button></section>
                    <section class="viswiz-card"><h2><?php esc_html_e( 'Data revisions', 'viswiz' ); ?></h2><div data-viswiz-revisions><?php foreach ( $revisions as $revision ) : ?><p><strong>r<?php echo esc_html( (string) $revision['revision'] ); ?></strong> — <?php echo esc_html( $revision['note'] ); ?><br><small><?php echo esc_html( $revision['created_at'] ); ?></small> <?php if ( (int) $revision['revision'] !== (int) $dataset['revision'] ) : ?><button type="button" class="button-link" data-viswiz-restore-revision="<?php echo esc_attr( (string) $revision['revision'] ); ?>"><?php esc_html_e( 'Restore', 'viswiz' ); ?></button><?php endif; ?></p><?php endforeach; ?></div></section>
                    <section class="viswiz-card viswiz-danger-zone"><h2><?php esc_html_e( 'Dataset actions', 'viswiz' ); ?></h2><p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_dataset_duplicate&dataset_id=' . $dataset_id ), 'viswiz_dataset_duplicate_' . $dataset_id ) ); ?>"><?php esc_html_e( 'Duplicate', 'viswiz' ); ?></a></p><p><a class="button button-link-delete" data-viswiz-confirm href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_dataset_delete&dataset_id=' . $dataset_id ), 'viswiz_dataset_delete_' . $dataset_id ) ); ?>"><?php esc_html_e( 'Delete dataset', 'viswiz' ); ?></a></p></section>
                </aside>
            </div>
        </div>
        <?php
    }

    public static function create_dataset(): void {
        self::require_dataset_cap();
        check_admin_referer( 'viswiz_dataset_create' );
        $repo = new DatasetRepository();
        $id   = $repo->create( array( 'name' => wp_unslash( $_POST['name'] ?? '' ), 'description' => wp_unslash( $_POST['description'] ?? '' ), 'schema_type' => wp_unslash( $_POST['schema_type'] ?? 'categorical' ) ) );
        if ( is_wp_error( $id ) ) { wp_die( esc_html( $id->get_error_message() ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $id ) ); exit;
    }

    public static function update_dataset(): void {
        self::require_dataset_cap(); $id = absint( $_POST['dataset_id'] ?? 0 ); check_admin_referer( 'viswiz_dataset_update_' . $id );
        $repo = new DatasetRepository(); $result = $repo->update_metadata( $id, array( 'name' => wp_unslash( $_POST['name'] ?? '' ), 'description' => wp_unslash( $_POST['description'] ?? '' ) ), absint( $_POST['expected_revision'] ?? 0 ) ?: null );
        if ( is_wp_error( $result ) ) { wp_die( esc_html( $result->get_error_message() ) ); }
        wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $id . '&updated=1' ) ); exit;
    }

    public static function duplicate_dataset(): void {
        self::require_dataset_cap(); $id = absint( $_GET['dataset_id'] ?? 0 ); check_admin_referer( 'viswiz_dataset_duplicate_' . $id ); $repo = new DatasetRepository(); $new_id = $repo->duplicate( $id ); if ( is_wp_error( $new_id ) ) { wp_die( esc_html( $new_id->get_error_message() ) ); } wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&dataset_id=' . $new_id ) ); exit;
    }

    public static function delete_dataset(): void {
        self::require_dataset_cap(); $id = absint( $_GET['dataset_id'] ?? 0 ); check_admin_referer( 'viswiz_dataset_delete_' . $id ); $repo = new DatasetRepository(); $repo->delete_with_usage_cleanup( $id ); wp_safe_redirect( admin_url( 'admin.php?page=viswiz-datasets&deleted=1' ) ); exit;
    }

    public static function export_dataset(): void {
        self::require_dataset_cap(); $id = absint( $_GET['dataset_id'] ?? 0 ); check_admin_referer( 'viswiz_dataset_export_' . $id ); $repo = new DatasetRepository(); $dataset = $repo->get( $id ); if ( ! $dataset ) { wp_die( esc_html__( 'Dataset not found.', 'viswiz' ) ); } nocache_headers(); header( 'Content-Type: application/json; charset=utf-8' ); header( 'Content-Disposition: attachment; filename="viswiz-dataset-' . $id . '-r' . (int) $dataset['revision'] . '.json"' ); echo wp_json_encode( array( 'format' => 'viswiz-dataset-v2', 'dataset' => $dataset, 'payload' => $repo->get_payload( $id ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); exit;
    }

    public static function node_types_page(): void { self::schema_page( 'node' ); }
    public static function relation_types_page(): void { self::schema_page( 'relation' ); }

    private static function schema_page( string $kind ): void {
        if ( ! current_user_can( 'manage_viswiz_schema' ) ) { wp_die( esc_html__( 'Permission denied.', 'viswiz' ) ); }
        $is_node = 'node' === $kind; $schema = $is_node ? Registry::node_types() : Registry::relation_types(); $action = $is_node ? 'viswiz_save_node_schema' : 'viswiz_save_relation_schema';
        ?><div class="wrap viswiz-admin-wrap"><h1><?php echo $is_node ? esc_html__( 'Node types', 'viswiz' ) : esc_html__( 'Relation types', 'viswiz' ); ?></h1><p><?php esc_html_e( 'Global schema changes require the dedicated manage_viswiz_schema capability. Dataset editors cannot approve schema changes through autosave.', 'viswiz' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>"><?php wp_nonce_field( $action ); ?><table class="widefat striped viswiz-table"><thead><tr><th>Slug</th><th>Label</th><?php if ( $is_node ) : ?><th>Subtypes (slug=Label)</th><th>Color</th><?php else : ?><th>Direction</th><th>Source type</th><th>Target type</th><th>Inverse label</th><?php endif; ?></tr></thead><tbody>
        <?php $index=0; foreach ( $schema as $slug => $item ) : ?><tr><td><input name="schema[<?php echo esc_attr((string)$index); ?>][slug]" value="<?php echo esc_attr($slug); ?>"></td><td><input name="schema[<?php echo esc_attr((string)$index); ?>][label]" value="<?php echo esc_attr($item['label'] ?? ''); ?>"></td><?php if ($is_node): ?><td><textarea name="schema[<?php echo esc_attr((string)$index); ?>][subtypes]" rows="3"><?php foreach ((array)($item['subtypes']??array()) as $s=>$l) { echo esc_textarea($s.'='.$l)."\n"; } ?></textarea></td><td><input type="color" name="schema[<?php echo esc_attr((string)$index); ?>][color]" value="<?php echo esc_attr($item['color']??'#2563eb'); ?>"></td><?php else: ?><td><select name="schema[<?php echo esc_attr((string)$index); ?>][direction]"><?php foreach(array('directed','bidirectional','undirected') as $d):?><option <?php selected($item['direction']??'directed',$d);?>><?php echo esc_html($d);?></option><?php endforeach;?></select></td><td><input name="schema[<?php echo esc_attr((string)$index); ?>][source_type]" value="<?php echo esc_attr($item['source_type']??'');?>"></td><td><input name="schema[<?php echo esc_attr((string)$index); ?>][target_type]" value="<?php echo esc_attr($item['target_type']??'');?>"></td><td><input name="schema[<?php echo esc_attr((string)$index); ?>][inverse_label]" value="<?php echo esc_attr($item['inverse_label']??'');?>"></td><?php endif;?></tr><?php $index++; endforeach; ?>
        <tr><td><input name="schema[new][slug]" placeholder="new_slug"></td><td><input name="schema[new][label]" placeholder="New type"></td><?php if($is_node):?><td><textarea name="schema[new][subtypes]"></textarea></td><td><input type="color" name="schema[new][color]" value="#2563eb"></td><?php else:?><td><select name="schema[new][direction]"><option>directed</option><option>bidirectional</option><option>undirected</option></select></td><td><input name="schema[new][source_type]"></td><td><input name="schema[new][target_type]"></td><td><input name="schema[new][inverse_label]"></td><?php endif;?></tr>
        </tbody></table><p><button class="button button-primary"><?php esc_html_e('Save schema','viswiz');?></button></p></form></div><?php
    }

    public static function save_node_schema(): void { self::save_schema( 'node' ); }
    public static function save_relation_schema(): void { self::save_schema( 'relation' ); }
    private static function save_schema( string $kind ): void {
        if ( ! current_user_can( 'manage_viswiz_schema' ) ) { wp_die( esc_html__( 'Permission denied.', 'viswiz' ) ); }
        $action = 'node' === $kind ? 'viswiz_save_node_schema' : 'viswiz_save_relation_schema'; check_admin_referer( $action ); $raw = wp_unslash( $_POST['schema'] ?? array() ); $schema=array(); foreach((array)$raw as $item){ if(!is_array($item)||empty($item['slug']))continue; if('node'===$kind){$subs=array(); foreach(preg_split('/\r?\n/',(string)($item['subtypes']??''))?:array() as $line){ if(!str_contains($line,'='))continue; [$s,$l]=array_map('trim',explode('=',$line,2)); if($s&&$l)$subs[sanitize_key($s)]=sanitize_text_field($l);} $item['subtypes']=$subs;} $schema[sanitize_key((string)$item['slug'])]=$item; } $result='node'===$kind?Registry::update_node_types($schema):Registry::update_relation_types($schema); if(is_wp_error($result))wp_die(esc_html($result->get_error_message())); wp_safe_redirect(admin_url('admin.php?page=viswiz-'.$kind.'-types&saved=1')); exit;
    }

    public static function settings_page(): void {
        if(!current_user_can('manage_viswiz_settings'))wp_die(esc_html__('Permission denied.','viswiz')); $settings=get_option('viswiz_settings_v2',array()); ?>
        <div class="wrap viswiz-admin-wrap"><h1><?php esc_html_e('VisWiz settings','viswiz');?></h1><form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="viswiz_save_settings"><?php wp_nonce_field('viswiz_save_settings');?><section class="viswiz-card"><h2><?php esc_html_e('Runtime defaults','viswiz');?></h2><label class="viswiz-field"><span><?php esc_html_e('Default live cache / refresh floor (seconds)','viswiz');?></span><input type="number" min="60" max="1800" name="cache_seconds" value="<?php echo esc_attr((string)absint($settings['cache_seconds']??120));?>"></label><p class="description"><?php esc_html_e('Per-visualization refresh can be longer, but never shorter than one minute.','viswiz');?></p></section><p><button class="button button-primary"><?php esc_html_e('Save settings','viswiz');?></button></p></form></div><?php
    }

    public static function save_settings(): void { if(!current_user_can('manage_viswiz_settings'))wp_die(esc_html__('Permission denied.','viswiz')); check_admin_referer('viswiz_save_settings'); update_option('viswiz_settings_v2',array('cache_seconds'=>min(1800,max(60,absint($_POST['cache_seconds']??120)))),false); wp_safe_redirect(admin_url('admin.php?page=viswiz-settings&saved=1')); exit; }

    public static function visualization_columns( array $columns ): array { $columns['viswiz_renderer']=__('Renderer','viswiz'); $columns['viswiz_dataset']=__('Dataset','viswiz'); $columns['viswiz_shortcode']=__('Shortcode','viswiz'); return $columns; }
    public static function visualization_column( string $column, int $post_id ): void { if('viswiz_renderer'===$column){echo '<code>'.esc_html((string)get_post_meta($post_id,'_viswiz_renderer',true)).'</code>';} elseif('viswiz_dataset'===$column){$id=absint(get_post_meta($post_id,'_viswiz_dataset_id',true)); if($id){$repo=new DatasetRepository();$d=$repo->get($id);echo $d?esc_html($d['name']):'—';}else{echo esc_html__('Woo live','viswiz');}} elseif('viswiz_shortcode'===$column){echo '<code>[viswiz_visualization id=&quot;'.esc_html((string)$post_id).'&quot;]</code>';}}

    private static function render_woo_fields( array $woo ): void {
        $metric=sanitize_key((string)($woo['metric']??'revenue')); $group=sanitize_key((string)($woo['group_by']??'total')); $mode=sanitize_key((string)($woo['period_mode']??'relative')); $unit=sanitize_key((string)($woo['period_unit']??'months')); ?>
        <div class="viswiz-form-grid viswiz-woo-fields"><label><span><?php esc_html_e('Metric','viswiz');?></span><select name="viswiz_woo[metric]" data-viswiz-woo="metric"><?php foreach(array('revenue'=>'Revenue','orders'=>'Orders','quantity'=>'Items sold') as $k=>$l):?><option value="<?php echo esc_attr($k);?>" <?php selected($metric,$k);?>><?php echo esc_html($l);?></option><?php endforeach;?></select></label><label><span><?php esc_html_e('Group by','viswiz');?></span><select name="viswiz_woo[group_by]" data-viswiz-woo="group_by"><?php foreach(array('total'=>'Total','month'=>'Month','product'=>'Product','status'=>'Status') as $k=>$l):?><option value="<?php echo esc_attr($k);?>" <?php selected($group,$k);?>><?php echo esc_html($l);?></option><?php endforeach;?></select></label><label><span><?php esc_html_e('Period mode','viswiz');?></span><select name="viswiz_woo[period_mode]" data-viswiz-woo="period_mode"><option value="relative" <?php selected($mode,'relative');?>>Relative</option><option value="fixed" <?php selected($mode,'fixed');?>>Fixed dates</option></select></label><label><span><?php esc_html_e('Period value','viswiz');?></span><input type="number" min="1" name="viswiz_woo[period_value]" data-viswiz-woo="period_value" value="<?php echo esc_attr((string)absint($woo['period_value']??12));?>"></label><label><span><?php esc_html_e('Period unit','viswiz');?></span><select name="viswiz_woo[period_unit]" data-viswiz-woo="period_unit"><?php foreach(array('days','weeks','months','years') as $u):?><option <?php selected($unit,$u);?>><?php echo esc_html($u);?></option><?php endforeach;?></select></label><label><span><?php esc_html_e('Fixed start','viswiz');?></span><input type="date" name="viswiz_woo[period_start]" data-viswiz-woo="period_start" value="<?php echo esc_attr((string)($woo['period_start']??''));?>"></label><label><span><?php esc_html_e('Fixed end','viswiz');?></span><input type="date" name="viswiz_woo[period_end]" data-viswiz-woo="period_end" value="<?php echo esc_attr((string)($woo['period_end']??''));?>"></label><label><span><?php esc_html_e('Product IDs','viswiz');?></span><input name="viswiz_woo[product_ids]" data-viswiz-woo="product_ids" value="<?php echo esc_attr(implode(',',Support::int_list($woo['product_ids']??array())));?>"></label><label><span><?php esc_html_e('Category IDs','viswiz');?></span><input name="viswiz_woo[category_ids]" data-viswiz-woo="category_ids" value="<?php echo esc_attr(implode(',',Support::int_list($woo['category_ids']??array())));?>"></label><label><span><?php esc_html_e('Date basis','viswiz');?></span><select name="viswiz_woo[date_basis]" data-viswiz-woo="date_basis"><?php foreach(array('created','paid','completed') as $b):?><option <?php selected(($woo['date_basis']??'created'),$b);?>><?php echo esc_html($b);?></option><?php endforeach;?></select></label><label><span><?php esc_html_e('Revenue basis','viswiz');?></span><select name="viswiz_woo[revenue_basis]" data-viswiz-woo="revenue_basis"><?php foreach(array('gross'=>'Order gross','net_items'=>'Items net','gross_items'=>'Items + tax') as $k=>$l):?><option value="<?php echo esc_attr($k);?>" <?php selected(($woo['revenue_basis']??'gross'),$k);?>><?php echo esc_html($l);?></option><?php endforeach;?></select></label></div><?php self::checkbox('viswiz_woo[deduct_refunds]',!isset($woo['deduct_refunds'])||rest_sanitize_boolean($woo['deduct_refunds']),__('Deduct refunds','viswiz'),'data-viswiz-woo="deduct_refunds"');
    }

    private static function woo_config_from_post(): array { $raw=wp_unslash($_POST['viswiz_woo']??array()); return array('metric'=>sanitize_key($raw['metric']??'revenue'),'group_by'=>sanitize_key($raw['group_by']??'total'),'period_mode'=>sanitize_key($raw['period_mode']??'relative'),'period_value'=>absint($raw['period_value']??12),'period_unit'=>sanitize_key($raw['period_unit']??'months'),'period_start'=>sanitize_text_field($raw['period_start']??''),'period_end'=>sanitize_text_field($raw['period_end']??''),'product_ids'=>Support::int_list($raw['product_ids']??array()),'category_ids'=>Support::int_list($raw['category_ids']??array()),'date_basis'=>sanitize_key($raw['date_basis']??'created'),'revenue_basis'=>sanitize_key($raw['revenue_basis']??'gross'),'deduct_refunds'=>isset($raw['deduct_refunds'])); }
    private static function checkbox( string $name, bool $checked, string $label, string $attrs='' ): void { echo '<label class="viswiz-check"><input type="checkbox" name="'.esc_attr($name).'" value="1" '.checked($checked,true,false).' '.$attrs.'> '.esc_html($label).'</label>'; }
    private static function require_dataset_cap(): void { if(!current_user_can('edit_viswiz_datasets'))wp_die(esc_html__('Permission denied.','viswiz')); }
}
