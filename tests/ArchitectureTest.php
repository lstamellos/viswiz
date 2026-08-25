<?php
use PHPUnit\Framework\TestCase;

final class ArchitectureTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_bootstrap_stays_small(): void {
        $lines = file( $this->root . '/viswiz.php' );
        $this->assertIsArray( $lines );
        $this->assertLessThan( 80, count( $lines ) );
    }

    public function test_no_public_arbitrary_sales_routes_remain(): void {
        $source = $this->allActiveSource();
        $this->assertStringNotContainsString( "'/sales'", $source );
        $this->assertStringNotContainsString( "'/sales-breakdown'", $source );
        $this->assertStringNotContainsString( "'/sales-status'", $source );
        $this->assertStringNotContainsString( "'permission_callback' => '__return_true'", $source );
    }

    public function test_no_unbounded_woocommerce_order_queries(): void {
        $source = $this->allActiveSource();
        $this->assertStringNotContainsString( "'limit' => -1", $source );
        $this->assertStringContainsString( "private const PAGE_SIZE = 200", $source );
    }

    public function test_frontend_has_no_external_runtime_cdn_dependency(): void {
        $source = file_get_contents( $this->root . '/assets/viswiz.js' ) . file_get_contents( $this->root . '/viswiz.php' );
        $this->assertStringNotContainsString( 'cdn.jsdelivr', $source );
        $this->assertStringNotContainsString( 'unpkg.com', $source );
        $this->assertStringNotContainsString( 'd3.min.js', $source );
    }

    public function test_database_version_is_independent_from_plugin_version(): void {
        $bootstrap = file_get_contents( $this->root . '/viswiz.php' );
        $this->assertStringContainsString( "VISWIZ_VERSION', '2.0.0'", $bootstrap );
        $this->assertStringContainsString( "VISWIZ_DB_VERSION', 20000", $bootstrap );
    }

    public function test_dataset_storage_uses_immutable_node_references_and_revision_locking(): void {
        $repository = file_get_contents( $this->root . '/src/Database/DatasetRepository.php' );
        $migrator   = file_get_contents( $this->root . '/src/Database/Migrator.php' );
        $this->assertStringContainsString( 'from_node_uuid', $repository );
        $this->assertStringContainsString( 'FOR UPDATE', $repository );
        $this->assertStringContainsString( 'viswiz_dataset_revisions', $migrator );
        $this->assertStringNotContainsString( 'visualization_id', $repository );
    }

    public function test_block_metadata_is_valid_json(): void {
        $data = json_decode( file_get_contents( $this->root . '/blocks/visualization/block.json' ), true );
        $this->assertIsArray( $data );
        $this->assertSame( 'viswiz/visualization', $data['name'] );
        $this->assertSame( 3, $data['apiVersion'] );
    }

    private function allActiveSource(): string {
        $source = file_get_contents( $this->root . '/viswiz.php' );
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root . '/src' ) );
        foreach ( $iterator as $file ) {
            if ( $file->isFile() && 'php' === $file->getExtension() ) {
                $source .= file_get_contents( $file->getPathname() );
            }
        }
        return $source;
    }
}
