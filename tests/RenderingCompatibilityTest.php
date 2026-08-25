<?php
use PHPUnit\Framework\TestCase;

final class RenderingCompatibilityTest extends TestCase {
    public function test_runtime_compatibility_is_registered_from_bootstrap(): void {
        $bootstrap = file_get_contents( dirname( __DIR__ ) . '/viswiz.php' );
        self::assertStringContainsString( 'RenderingCompatibility::register()', $bootstrap );
    }

    public function test_runtime_compatibility_waits_for_frontend_renderer(): void {
        $runtime = file_get_contents( dirname( __DIR__ ) . '/src/Runtime/RenderingCompatibility.php' );
        self::assertStringContainsString( "array( 'viswiz-frontend' )", $runtime );
        self::assertStringContainsString( "add_action( 'wp_footer'", $runtime );
        self::assertStringContainsString( "add_action( 'admin_enqueue_scripts'", $runtime );
    }

    public function test_runtime_js_recovers_admin_preview_and_late_frontend_blocks(): void {
        $javascript = file_get_contents( dirname( __DIR__ ) . '/assets/viswiz-rendering-compat.js' );
        self::assertStringContainsString( '[data-viswiz-inline-spec]', $javascript );
        self::assertStringContainsString( '[data-viswiz-visualization]', $javascript );
        self::assertStringContainsString( 'MutationObserver', $javascript );
        self::assertStringContainsString( 'window.VisWiz.render', $javascript );
        self::assertStringContainsString( 'window.VisWiz.load', $javascript );
    }
}
