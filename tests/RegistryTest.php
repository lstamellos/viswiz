<?php
use PHPUnit\Framework\TestCase;
use VisWiz\Domain\Registry;

final class RegistryTest extends TestCase {
    public function test_all_renderers_have_valid_schemas(): void {
        foreach ( Registry::renderers() as $renderer => $meta ) {
            $this->assertNotEmpty( $meta['schemas'], $renderer );
            foreach ( $meta['schemas'] as $schema ) {
                $this->assertTrue( Registry::schema_exists( $schema ), $renderer . ' -> ' . $schema );
                $this->assertTrue( Registry::renderer_supports_schema( $renderer, $schema ) );
            }
        }
    }

    public function test_renderer_and_schema_are_separate_concepts(): void {
        $this->assertTrue( Registry::renderer_supports_schema( 'bar', 'categorical' ) );
        $this->assertTrue( Registry::renderer_supports_schema( 'pie', 'categorical' ) );
        $this->assertFalse( Registry::schema_exists( 'bar' ) );
        $this->assertFalse( Registry::schema_exists( 'pie' ) );
        $this->assertSame( 'graph', Registry::default_schema_for_renderer( 'org_chart' ) );
    }
}
