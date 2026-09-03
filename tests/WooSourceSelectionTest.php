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
        self::assertStringContainsString( "wp_script_is( 'wc-enhanced-select', 'registered' )", $admin );
        self::assertStringContainsString( "wp_enqueue_script( 'wc-enhanced-select' );", $admin );
        self::assertStringContainsString( "WC()->plugin_url() . '/assets/css/select2.css'", $admin );
        self::assertStringContainsString( 'selected_product_labels', $admin );
        self::assertStringContainsString( 'selected_category_labels', $admin );
    }

    public function test_adapter_replaces_raw_id_text_controls_with_native_woo_search_pickers(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-woo-source-selection.js' );

        self::assertStringContainsString( "input.type = 'hidden';", $javascript );
        self::assertStringContainsString( "'wc-product-search'", $javascript );
        self::assertStringContainsString( "'wc-category-search'", $javascript );
        self::assertStringContainsString( "select.dataset.returnId = 'true'", $javascript );
        self::assertStringContainsString( "select.dataset.minimumInputLength = '1'", $javascript );
        self::assertStringContainsString( "window.jQuery(document.body).trigger('wc-enhanced-select-init')", $javascript );
        self::assertStringContainsString( "input.value = [...select.selectedOptions]", $javascript );
        self::assertStringNotContainsString( 'fetch(', $javascript );
        self::assertStringNotContainsString( 'restUrl', $javascript );
    }

    public function test_live_query_and_snapshot_are_explained_as_different_data_ownership_modes(): void {
        $admin = file_get_contents( $this->root . '/src/Admin/WooSourceSelection.php' );
        $javascript = file_get_contents( $this->root . '/assets/viswiz-woo-source-selection.js' );

        self::assertStringContainsString( 'Live query: recalculates from current WooCommerce orders', $admin );
        self::assertStringContainsString( 'No rows are copied into a dataset.', $admin );
        self::assertStringContainsString( 'Snapshot: runs the WooCommerce query once', $admin );
        self::assertStringContainsString( 'do not stay synchronized with WooCommerce', $admin );
        self::assertStringContainsString( 'WooCommerce is not active.', $admin );
        self::assertStringContainsString( "liveOption.textContent = tr('liveOption'", $javascript );
        self::assertStringContainsString( "snapshotButton.textContent = tr('snapshotButton'", $javascript );
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
