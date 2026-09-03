<?php
use PHPUnit\Framework\TestCase;

final class NodeRichEditorTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_graph_dataset_page_enqueues_the_wordpress_editor_api(): void {
        $plugin = file_get_contents( $this->root . '/src/Plugin.php' );
        $admin = file_get_contents( $this->root . '/src/Admin/NodeRichEditor.php' );

        self::assertStringContainsString( 'use VisWiz\\Admin\\NodeRichEditor;', $plugin );
        self::assertStringContainsString( 'NodeRichEditor::register();', $plugin );
        self::assertStringContainsString( "'graph' !== (string) \$dataset['schema_type']", $admin );
        self::assertStringContainsString( 'wp_enqueue_editor();', $admin );
        self::assertStringContainsString( 'viswiz-node-rich-editor.js', $admin );
        self::assertStringContainsString( "array( 'editor', 'viswiz-dataset-editor-v2' )", $admin );
    }

    public function test_dynamic_editor_has_explicit_initialize_sync_and_teardown_lifecycle(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-node-rich-editor.js' );

        self::assertStringContainsString( 'wp?.editor', $javascript );
        self::assertStringContainsString( 'api.initialize(id', $javascript );
        self::assertStringContainsString( 'api.getContent(instance.id)', $javascript );
        self::assertStringContainsString( 'editorApi().remove(instance.id)', $javascript );
        self::assertStringContainsString( "form?.addEventListener('submit', () => sync(instance), true)", $javascript );
        self::assertStringContainsString( "dialog.addEventListener('close', () => cleanup(dialog), { capture: true, once: true })", $javascript );
        self::assertStringContainsString( "dialog.addEventListener('cancel', () => cleanup(dialog), { capture: true, once: true })", $javascript );
        self::assertStringContainsString( 'MutationObserver', $javascript );
        self::assertStringContainsString( "dialog.dataset.viswizRichEditorState = 'fallback'", $javascript );
        self::assertStringContainsString( 'quicktags: true', $javascript );
        self::assertStringContainsString( 'mediaButtons: false', $javascript );
    }

    public function test_rich_editor_is_only_a_lifecycle_adapter_not_a_second_graph_state_owner(): void {
        $javascript = file_get_contents( $this->root . '/assets/viswiz-node-rich-editor.js' );

        self::assertStringNotContainsString( 'fetch(', $javascript );
        self::assertStringNotContainsString( 'restUrl', $javascript );
        self::assertStringNotContainsString( '/editor/nodes', $javascript );
        self::assertStringNotContainsString( '/editor/relations', $javascript );
    }

    public function test_node_description_stays_server_sanitized_with_wp_kses_post(): void {
        $repo = file_get_contents( $this->root . '/src/Database/DatasetRepository.php' );
        $support = file_get_contents( $this->root . '/src/Support.php' );

        self::assertStringContainsString( "'description'     => Support::sanitize_html", $repo );
        self::assertStringContainsString( 'return wp_kses_post( (string) $value );', $support );
        self::assertStringContainsString( "'description_html'=> (string) \$row['description']", $repo );
    }

    public function test_rich_editor_release_does_not_change_database_schema_version(): void {
        $plugin = file_get_contents( $this->root . '/viswiz.php' );
        self::assertStringContainsString( 'Version: 2.0.33', $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_VERSION', '2.0.33' );", $plugin );
        self::assertStringContainsString( "define( 'VISWIZ_DB_VERSION', 20000 );", $plugin );
    }
}
