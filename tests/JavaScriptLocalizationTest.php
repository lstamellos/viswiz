<?php
use PHPUnit\Framework\TestCase;

final class JavaScriptLocalizationTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        $this->root = dirname( __DIR__ );
    }

    public function test_user_visible_javascript_uses_wordpress_i18n_not_local_maps(): void {
        $files = array(
            'viswiz-admin.js',
            'viswiz-dataset-editor.js',
            'viswiz-import.js',
            'viswiz-node-public-fields.js',
            'viswiz-node-rich-editor.js',
            'viswiz-renderer-settings.js',
            'viswiz-spreadsheet-editor.js',
            'viswiz-visualization-presets.js',
            'viswiz-visualization-preview.js',
            'viswiz-woo-source-selection.js',
            'viswiz.js',
            'viswiz-graph-runtime.js',
            'viswiz-block.js',
        );
        foreach ( $files as $file ) {
            $javascript = file_get_contents( $this->root . '/assets/' . $file );
            self::assertStringContainsString( '.i18n', $javascript, $file . ' must use the WordPress i18n API.' );
            self::assertStringContainsString( "'viswiz'", $javascript, $file . ' must use the VisWiz text domain.' );
            self::assertStringNotContainsString( 'cfg.i18n', $javascript, $file );
            self::assertStringNotContainsString( 'previewCfg.i18n', $javascript, $file );
            self::assertStringNotContainsString( 'VisWizFrontendV2?.i18n', $javascript, $file );
            self::assertStringNotContainsString( 'const tr = (key, fallback)', $javascript, $file );
        }
    }

    public function test_static_translation_maps_are_removed_from_php_config(): void {
        $admin = file_get_contents( $this->root . '/src/Admin/Admin.php' );
        $preview = file_get_contents( $this->root . '/src/Admin/VisualizationPreview.php' );
        $presets = file_get_contents( $this->root . '/src/Admin/VisualizationPresets.php' );
        $woo = file_get_contents( $this->root . '/src/Admin/WooSourceSelection.php' );
        $frontend = file_get_contents( $this->root . '/src/Frontend/Frontend.php' );

        self::assertStringNotContainsString( "'i18n'", $admin );
        self::assertStringNotContainsString( 'VisWizVisualizationPreview', $preview );
        self::assertStringNotContainsString( "'i18n'", $presets );
        self::assertStringNotContainsString( "'i18n'", $woo );
        self::assertStringNotContainsString( 'VisWizFrontendV2', $frontend );
    }

    public function test_scripts_declare_wp_i18n_and_translation_catalogs(): void {
        $sources = array(
            'src/Admin/Admin.php',
            'src/Admin/DatasetEditorPage.php',
            'src/Admin/ImportUi.php',
            'src/Admin/NodePublicFields.php',
            'src/Admin/NodeRichEditor.php',
            'src/Admin/SpreadsheetEditor.php',
            'src/Admin/VisualizationPreview.php',
            'src/Admin/VisualizationPresets.php',
            'src/Admin/WooSourceSelection.php',
            'src/Frontend/Frontend.php',
            'src/Runtime/GraphRuntime.php',
        );
        foreach ( $sources as $source ) {
            $php = file_get_contents( $this->root . '/' . $source );
            self::assertStringContainsString( "'wp-i18n'", $php, $source );
            self::assertStringContainsString( 'wp_set_script_translations', $php, $source );
        }

        foreach ( array( 'viswiz-el-viswiz-frontend.json', 'viswiz-el-viswiz-graph-runtime.json' ) as $catalog ) {
            $data = json_decode( file_get_contents( $this->root . '/languages/' . $catalog ), true, 512, JSON_THROW_ON_ERROR );
            self::assertSame( 'el', $data['locale_data']['messages']['']['lang'] );
            self::assertSame( 'messages', $data['domain'] );
        }
    }

    public function test_existing_greek_graph_labels_moved_to_catalog(): void {
        $runtime = file_get_contents( $this->root . '/assets/viswiz-graph-runtime.js' );
        $catalog = file_get_contents( $this->root . '/languages/viswiz-el-viswiz-graph-runtime.json' );
        self::assertStringNotContainsString( 'Καθαρισμός αναζήτησης', $runtime );
        self::assertStringNotContainsString( "document.documentElement.lang", $runtime );
        self::assertStringContainsString( 'Καθαρισμός αναζήτησης', $catalog );
        self::assertStringContainsString( 'Εστίαση στις συνδέσεις', $catalog );
    }
}
