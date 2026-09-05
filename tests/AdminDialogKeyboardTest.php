<?php
use PHPUnit\Framework\TestCase;

final class AdminDialogKeyboardTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_keyboard_adapter_is_lifecycle_only_and_locally_scoped(): void {
        $keyboard = file_get_contents( $this->root . '/assets/viswiz-dataset-editor-keyboard.js' );
        $admin    = file_get_contents( $this->root . '/src/Admin/DatasetEditorPage.php' );

        self::assertStringContainsString( 'viswiz-dataset-editor-keyboard.js', $admin );
        self::assertStringContainsString( "array( 'viswiz-dataset-editor-v2' )", $admin );
        self::assertStringContainsString( "search.addEventListener('keydown'", $keyboard );
        self::assertStringContainsString( "event.key !== 'Enter'", $keyboard );
        self::assertStringNotContainsString( "document.addEventListener('keydown'", $keyboard );
        self::assertStringNotContainsString( "event.key === 'Tab'", $keyboard );
        self::assertStringNotContainsString( "event.key === 'Escape'", $keyboard );
        self::assertStringNotContainsString( 'fetch(', $keyboard );
        self::assertStringNotContainsString( 'restUrl', $keyboard );
    }

    public function test_native_dialog_keeps_accessible_name_and_focus_return_contract(): void {
        $keyboard = file_get_contents( $this->root . '/assets/viswiz-dataset-editor-keyboard.js' );
        $editor   = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );

        self::assertStringContainsString( "document.createElement('dialog')", $editor );
        self::assertStringContainsString( 'dialog.showModal();', $editor );
        self::assertStringContainsString( "dialog.setAttribute('aria-labelledby', heading.id);", $keyboard );
        self::assertStringContainsString( "dialog.setAttribute('aria-modal', 'true');", $keyboard );
        self::assertStringContainsString( 'const context = pendingInvoker;', $keyboard );
        self::assertStringContainsString( 'matchingInvoker(context.key)', $keyboard );
        self::assertStringContainsString( "dialog.addEventListener('close', () => restoreFocus(context)", $keyboard );
        self::assertStringContainsString( "editor.addEventListener('click', rememberInvoker, true);", $keyboard );
    }

    public function test_destructive_dataset_editor_actions_keep_explicit_confirmation(): void {
        $editor = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );

        self::assertGreaterThanOrEqual( 3, substr_count( $editor, 'window.confirm(' ) );
        self::assertStringContainsString( "cfg.i18n?.confirmDelete || 'Delete this item?'", $editor );
    }
}
