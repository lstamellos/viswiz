<?php
use PHPUnit\Framework\TestCase;

final class WooSourceSelectionTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_plugin_registers_a_thin_woocommerce_source_adapter(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $admin = file_get_contents( $this->root . '/src/Admin/WooSourceSelection.php' );

        self::assertStringContainsString( 'use VisWiz\\Admin\\WooSourceSelection;', $plugin );
        self::assertStringContainsString( 'WooSourceSelection::register();', $plugin );
        self::assertStringContainsString( "current_user_can( 'edit_products' )", $admin );
        self::assertStringContainsString( "current_user_can( 'manage_woocommerce' )", $admin );
        self::assertStringContainsString( "if ( \$searchable && wp_script_is( 'wc-enhanced-select', 'registered' ) )", $admin );
        self::assertStringContainsString( "wp_enqueue_script( 'wc-enhanced-select' );", $admin );
        self::assertStringContainsString( "WC()->plugin_url() . '/assets/css/select2.css'", $admin );
        self::assertStringContainsString( "'searchable'", $admin );
        self::assertStringContainsString( "'snapshotAllowed'", $admin );
        self::assertStringContainsString( 'selected_product_labels', $admin );
        self::assertStringContainsString( 'selected_category_labels', $admin );
        self::assertStringContainsString( "'wp-i18n'", $admin );
    }

    public function test_adapter_progressively_enhances_ids_only_when_native_woo_search_is_usable(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-woo-source-selection.js' );

        self::assertStringContainsString( "cfg.searchable !== true", $javascript );
        self::assertStringContainsString( "input.type = 'hidden';", $javascript );
        self::assertStringContainsString( "'wc-product-search'", $javascript );
        self::assertStringContainsString( "'wc-category-search'", $javascript );
        self::assertStringContainsString( "select.dataset.returnId = 'true'", $javascript );
        self::assertStringContainsString( "select.dataset.minimumInputLength = '1'", $javascript );
        self::assertStringContainsString( "cfg.searchable === true && (product || category)", $javascript );
        self::assertStringContainsString( "window.jQuery(document.body).trigger('wc-enhanced-select-init')", $javascript );
        self::assertStringContainsString( "input.value = [...select.selectedOptions]", $javascript );
        self::assertStringContainsString( "__('WooCommerce search pickers are not available for this account. Product and category IDs remain editable manually.', 'viswiz')", $javascript );
        self::assertStringNotContainsString( 'fetch(', $javascript );
        self::assertStringNotContainsString( 'restUrl', $javascript );
    }

    public function test_live_query_and_snapshot_are_explained_as_different_data_ownership_modes(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-woo-source-selection.js' );

        self::assertStringContainsString( 'Live query: recalculates from current WooCommerce orders', $javascript );
        self::assertStringContainsString( 'No rows are copied into a dataset.', $javascript );
        self::assertStringContainsString( 'Snapshot: runs the WooCommerce query once', $javascript );
        self::assertStringContainsString( 'do not stay synchronized with WooCommerce', $javascript );
        self::assertStringContainsString( 'WooCommerce is not active.', $javascript );
        self::assertStringContainsString( 'does not have permission to run WooCommerce snapshots', $javascript );
        self::assertStringContainsString( "liveOption.textContent = __('WooCommerce live query', 'viswiz')", $javascript );
        self::assertStringContainsString( "snapshotButton.textContent = __('Replace dataset with current snapshot', 'viswiz')", $javascript );
        self::assertStringContainsString( "cfg.snapshotAllowed !== true", $javascript );
    }

    public function test_existing_query_contract_and_database_schema_stay_unchanged(): void {
        $admin = file_get_contents( $this->root . '/src/Admin/Admin.php' );
        $query = file_get_contents( $this->root . '/src/WooCommerce/SalesQuery.php' );
        $plugin = file_get_contents( $this->root . '/viswiz.php' );

        self::assertStringContainsString( "'product_ids'=>Support::int_list", $admin );
        self::assertStringContainsString( "'category_ids'=>Support::int_list", $admin );
        self::assertStringContainsString( "'product_ids'     => Support::int_list", $query );
        self::assertStringContainsString( "'category_ids'    => Support::int_list", $query );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}
