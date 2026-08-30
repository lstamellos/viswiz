<?php
namespace VisWiz\Admin;

use VisWiz\Database\DatasetCollectionRepository;
use VisWiz\Database\DatasetRepository;
use VisWiz\Support;

final class DatasetEditorPage {
    public static function register(): void {
        add_action( 'admin_menu', array( self::class, 'replace_dataset_menu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 60 );
    }

    public static function replace_dataset_menu(): void {
        $hook = get_plugin_page_hookname( 'viswiz-datasets', 'viswiz' );
        if ( ! $hook ) {
            return;
        }

        // add_submenu_page() has already registered Admin::datasets_page() on this hook.
        // Replace that callback in-place rather than registering the same submenu slug twice.
        remove_action( $hook, array( Admin::class, 'datasets_page' ) );
        add_action( $hook, array( self::class, 'page' ) );
    }

    public static function assets(): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $dataset_id = isset( $_GET['dataset_id'] ) ? absint( $_GET['dataset_id'] ) : 0;
        if ( 'viswiz-datasets' !== $page || ! $dataset_id ) {
            return;
        }

        // The server-aware editor does not render an automatic full-dataset graph preview.
        wp_dequeue_script( 'viswiz-frontend' );
        wp_dequeue_style( 'viswiz-frontend' );
        wp_enqueue_script(
            'viswiz-dataset-editor-v2',
            VISWIZ_URL . 'assets/viswiz-dataset-editor.js',
            array( 'viswiz-admin-v2' ),
            VISWIZ_VERSION,
            true
        );
        wp_add_inline_style(
            'viswiz-admin-v2',
            '.viswiz-server-editor-loading{padding:18px 0;color:#646970}.viswiz-server-editor-section+.viswiz-server-editor-section{margin-top:26px}.viswiz-node-picker{display:grid;gap:6px}.viswiz-node-picker select{min-height:150px;width:100%}.viswiz-editor-pager{display:flex;align-items:center;gap:10px;margin:12px 0}.viswiz-server-status{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.viswiz-server-status code{font-size:11px}.viswiz-server-search-note{margin:4px 0 0;color:#646970}'
        );
    }

    public static function page(): void {
        if ( ! current_user_can( 'edit_viswiz_datasets' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'viswiz' ) );
        }
        $dataset_id = absint( $_GET['dataset_id'] ?? 0 );
        if ( ! $dataset_id ) {
            Admin::datasets_page();
            return;
        }
        self::detail( $dataset_id );
    }

    private static function detail( int $dataset_id ): void {
        $repo = new DatasetRepository();
        $dataset = $repo->get( $dataset_id );
        if ( ! $dataset ) {
            wp_die( esc_html__( 'Dataset not found.', 'viswiz' ) );
        }
        $revisions = $repo->revisions( $dataset_id );
        $collection_repo = new DatasetCollectionRepository();
        $orphan_count = 'graph' === $dataset['schema_type'] ? $collection_repo->graph_orphan_count( $dataset_id ) : 0;
        ?>
        <div class="wrap viswiz-admin-wrap">
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=viswiz-datasets' ) ); ?>">&larr; <?php esc_html_e( 'All datasets', 'viswiz' ); ?></a></p>
            <h1><?php echo esc_html( $dataset['name'] ); ?> <small>r<?php echo esc_html( (string) $dataset['revision'] ); ?></small></h1>
            <div class="viswiz-admin-grid viswiz-dataset-detail-grid">
                <main>
                    <section class="viswiz-card">
                        <h2><?php esc_html_e( 'Dataset metadata', 'viswiz' ); ?></h2>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="viswiz_dataset_update">
                            <input type="hidden" name="dataset_id" value="<?php echo esc_attr( (string) $dataset_id ); ?>">
                            <input type="hidden" name="expected_revision" value="<?php echo esc_attr( (string) $dataset['revision'] ); ?>">
                            <?php wp_nonce_field( 'viswiz_dataset_update_' . $dataset_id ); ?>
                            <div class="viswiz-form-grid">
                                <label><span><?php esc_html_e( 'Name', 'viswiz' ); ?></span><input required name="name" value="<?php echo esc_attr( $dataset['name'] ); ?>"></label>
                                <label><span><?php esc_html_e( 'Schema', 'viswiz' ); ?></span><input readonly value="<?php echo esc_attr( $dataset['schema_type'] ); ?>"></label>
                            </div>
                            <label class="viswiz-field"><span><?php esc_html_e( 'Description', 'viswiz' ); ?></span><textarea name="description" rows="3"><?php echo esc_textarea( $dataset['description'] ); ?></textarea></label>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Save metadata', 'viswiz' ); ?></button>
                        </form>
                    </section>

                    <section class="viswiz-card">
                        <div class="viswiz-section-heading">
                            <div>
                                <h2><?php echo 'graph' === $dataset['schema_type'] ? esc_html__( 'Graph data', 'viswiz' ) : esc_html__( 'Dataset rows', 'viswiz' ); ?></h2>
                                <p><?php esc_html_e( 'Pages and searches are loaded from the server. The editor does not embed the full dataset in the admin page.', 'viswiz' ); ?></p>
                                <p class="viswiz-server-search-note"><?php esc_html_e( 'Search also finds records outside the currently visible page.', 'viswiz' ); ?></p>
                            </div>
                            <input type="search" data-viswiz-dataset-search placeholder="<?php esc_attr_e( 'Search data', 'viswiz' ); ?>" autocomplete="off">
                        </div>
                        <div id="viswiz-dataset-editor"
                            data-viswiz-server-editor="1"
                            data-dataset-id="<?php echo esc_attr( (string) $dataset_id ); ?>"
                            data-schema="<?php echo esc_attr( $dataset['schema_type'] ); ?>"
                            data-revision="<?php echo esc_attr( (string) $dataset['revision'] ); ?>">
                            <p class="viswiz-server-editor-loading"><?php esc_html_e( 'Loading dataset page…', 'viswiz' ); ?></p>
                        </div>
                    </section>
                </main>
                <aside>
                    <section class="viswiz-card">
                        <h2><?php esc_html_e( 'Integrity', 'viswiz' ); ?></h2>
                        <?php if ( 'graph' === $dataset['schema_type'] ) : ?>
                            <?php if ( 0 === $orphan_count ) : ?>
                                <p class="viswiz-ok"><?php esc_html_e( 'No orphan relation endpoints found.', 'viswiz' ); ?></p>
                            <?php else : ?>
                                <p class="notice notice-error inline"><?php echo esc_html( sprintf( _n( '%d orphan relation found.', '%d orphan relations found.', $orphan_count, 'viswiz' ), $orphan_count ) ); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e( 'Targeted writes validate node types and relation endpoints. Full graph replacements and imports run canonical graph validation.', 'viswiz' ); ?></p>
                        <?php else : ?>
                            <p class="description"><?php esc_html_e( 'Row writes are sanitized and revision-checked before commit.', 'viswiz' ); ?></p>
                        <?php endif; ?>
                    </section>

                    <section class="viswiz-card">
                        <h2><?php esc_html_e( 'Import / export', 'viswiz' ); ?></h2>
                        <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_dataset_export&dataset_id=' . $dataset_id ), 'viswiz_dataset_export_' . $dataset_id ) ); ?>"><?php esc_html_e( 'Export JSON', 'viswiz' ); ?></a></p>
                        <label class="viswiz-field"><span><?php esc_html_e( 'Replace from JSON', 'viswiz' ); ?></span><textarea rows="7" data-viswiz-import-json placeholder='{"rows": [...]}'></textarea></label>
                        <button type="button" class="button" data-viswiz-import-button><?php esc_html_e( 'Validate & replace', 'viswiz' ); ?></button>
                    </section>

                    <?php if ( 'graph' !== $dataset['schema_type'] ) : ?>
                        <section class="viswiz-card">
                            <h2><?php esc_html_e( 'WooCommerce snapshot', 'viswiz' ); ?></h2>
                            <?php self::woo_fields(); ?>
                            <button type="button" class="button" data-viswiz-commerce-snapshot><?php esc_html_e( 'Replace dataset with snapshot', 'viswiz' ); ?></button>
                        </section>
                    <?php endif; ?>

                    <section class="viswiz-card">
                        <h2><?php esc_html_e( 'Data revisions', 'viswiz' ); ?></h2>
                        <div data-viswiz-revisions>
                            <?php foreach ( $revisions as $revision ) : ?>
                                <p><strong>r<?php echo esc_html( (string) $revision['revision'] ); ?></strong> — <?php echo esc_html( $revision['note'] ); ?><br><small><?php echo esc_html( $revision['created_at'] ); ?></small>
                                <?php if ( (int) $revision['revision'] !== (int) $dataset['revision'] ) : ?>
                                    <button type="button" class="button-link" data-viswiz-restore-revision="<?php echo esc_attr( (string) $revision['revision'] ); ?>"><?php esc_html_e( 'Restore', 'viswiz' ); ?></button>
                                <?php endif; ?></p>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="viswiz-card viswiz-danger-zone">
                        <h2><?php esc_html_e( 'Dataset actions', 'viswiz' ); ?></h2>
                        <p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_dataset_duplicate&dataset_id=' . $dataset_id ), 'viswiz_dataset_duplicate_' . $dataset_id ) ); ?>"><?php esc_html_e( 'Duplicate', 'viswiz' ); ?></a></p>
                        <p><a class="button button-link-delete" data-viswiz-confirm href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=viswiz_dataset_delete&dataset_id=' . $dataset_id ), 'viswiz_dataset_delete_' . $dataset_id ) ); ?>"><?php esc_html_e( 'Delete dataset', 'viswiz' ); ?></a></p>
                    </section>
                </aside>
            </div>
        </div>
        <?php
    }

    private static function woo_fields(): void {
        ?>
        <div class="viswiz-form-grid viswiz-woo-fields">
            <label><span><?php esc_html_e( 'Metric', 'viswiz' ); ?></span><select data-viswiz-woo="metric"><option value="revenue">Revenue</option><option value="orders">Orders</option><option value="quantity">Items sold</option></select></label>
            <label><span><?php esc_html_e( 'Group by', 'viswiz' ); ?></span><select data-viswiz-woo="group_by"><option value="total">Total</option><option value="month">Month</option><option value="product">Product</option><option value="status">Status</option></select></label>
            <label><span><?php esc_html_e( 'Period mode', 'viswiz' ); ?></span><select data-viswiz-woo="period_mode"><option value="relative">Relative</option><option value="fixed">Fixed dates</option></select></label>
            <label><span><?php esc_html_e( 'Period value', 'viswiz' ); ?></span><input type="number" min="1" value="12" data-viswiz-woo="period_value"></label>
            <label><span><?php esc_html_e( 'Period unit', 'viswiz' ); ?></span><select data-viswiz-woo="period_unit"><option>days</option><option>weeks</option><option selected>months</option><option>years</option></select></label>
            <label><span><?php esc_html_e( 'Fixed start', 'viswiz' ); ?></span><input type="date" data-viswiz-woo="period_start"></label>
            <label><span><?php esc_html_e( 'Fixed end', 'viswiz' ); ?></span><input type="date" data-viswiz-woo="period_end"></label>
            <label><span><?php esc_html_e( 'Product IDs', 'viswiz' ); ?></span><input data-viswiz-woo="product_ids"></label>
            <label><span><?php esc_html_e( 'Category IDs', 'viswiz' ); ?></span><input data-viswiz-woo="category_ids"></label>
            <label><span><?php esc_html_e( 'Date basis', 'viswiz' ); ?></span><select data-viswiz-woo="date_basis"><option>created</option><option>paid</option><option>completed</option></select></label>
            <label><span><?php esc_html_e( 'Revenue basis', 'viswiz' ); ?></span><select data-viswiz-woo="revenue_basis"><option value="gross">Order gross</option><option value="net_items">Items net</option><option value="gross_items">Items + tax</option></select></label>
        </div>
        <label><input type="checkbox" checked data-viswiz-woo="deduct_refunds"> <?php esc_html_e( 'Deduct refunds', 'viswiz' ); ?></label>
        <?php
    }
}
