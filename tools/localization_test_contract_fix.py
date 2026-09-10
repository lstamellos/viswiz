from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

replacements = {
    'tests/AdminDialogKeyboardTest.php': [
        ("self::assertStringContainsString( \"cfg.i18n?.confirmDelete || 'Delete this item?'\", $editor );", "self::assertStringContainsString( \"__('Delete this item?', 'viswiz')\", $editor );"),
    ],
    'tests/GraphEditorWorkflowTest.php': [
        ("self::assertStringContainsString( \"button('Create node…'\", $javascript );", "self::assertStringContainsString( \"button(__('Create node…', 'viswiz')\", $javascript );"),
    ],
    'tests/GraphRuntimeTest.php': [
        ("self::assertStringContainsString( \"array( 'viswiz-frontend' )\", $runtime );", "self::assertStringContainsString( \"array( 'viswiz-frontend', 'wp-i18n' )\", $runtime );"),
    ],
    'tests/ImportWorkflowTest.php': [
        ("self::assertStringContainsString( \"['external_key', 'External key'\", $source );", "self::assertStringContainsString( \"['external_key', __('External key', 'viswiz')\", $source );"),
        ("self::assertStringContainsString( \"['from_key', 'From node key'\", $source );", "self::assertStringContainsString( \"['from_key', __('From node key', 'viswiz')\", $source );"),
        ("self::assertStringContainsString( \"['to_key', 'To node key'\", $source );", "self::assertStringContainsString( \"['to_key', __('To node key', 'viswiz')\", $source );"),
        ("self::assertStringContainsString( \"<option value=\\\"nodes\\\">Nodes</option><option value=\\\"relations\\\">Relations</option>\", $source );", "self::assertStringContainsString( \"<option value=\\\"nodes\\\">${__('Nodes', 'viswiz')}</option><option value=\\\"relations\\\">${__('Relations', 'viswiz')}</option>\", $source );"),
    ],
    'tests/NodePublicFieldsTest.php': [
        ("array( 'viswiz-dataset-editor-v2' )", "array( 'viswiz-dataset-editor-v2', 'wp-i18n' )"),
    ],
    'tests/NodeRichEditorTest.php': [
        ("array( 'editor', 'viswiz-dataset-editor-v2' )", "array( 'editor', 'viswiz-dataset-editor-v2', 'wp-i18n' )"),
    ],
    'tests/PublicGraphAccessibilityTest.php': [
        ("self::assertStringContainsString( \"'aria-label': `\\${tr('viewNode'\", $frontend );", "self::assertStringContainsString( \"'aria-label': `\\${__('View node', 'viswiz')\", $frontend );"),
    ],
    'tests/RendererSpecificSettingsTest.php': [
        ("array( 'viswiz-renderer-settings', 'viswiz-frontend', 'viswiz-graph-runtime' )", "array( 'viswiz-renderer-settings', 'viswiz-frontend', 'viswiz-graph-runtime', 'wp-i18n' )"),
    ],
    'tests/SpreadsheetEditorTest.php': [
        ("array( 'viswiz-dataset-editor-v2' )", "array( 'viswiz-dataset-editor-v2', 'wp-i18n' )"),
    ],
    'tests/VisualizationPresetsTest.php': [
        ("array( 'viswiz-visualization-preview' )", "array( 'viswiz-visualization-preview', 'wp-i18n' )"),
    ],
    'tests/WooSourceSelectionTest.php': [
        ("self::assertStringContainsString( 'Live query: recalculates from current WooCommerce orders', $admin );", "self::assertStringContainsString( \"__('Live query: recalculates from current WooCommerce orders when requested and uses the configured cache/refresh interval. No rows are copied into a dataset.', 'viswiz')\", $javascript );"),
        ("self::assertStringContainsString( 'No rows are copied into a dataset.', $admin );", "self::assertStringContainsString( 'No rows are copied into a dataset.', $javascript );"),
        ("self::assertStringContainsString( 'Snapshot: runs the WooCommerce query once', $admin );", "self::assertStringContainsString( 'Snapshot: runs the WooCommerce query once', $javascript );"),
        ("self::assertStringContainsString( 'do not stay synchronized with WooCommerce', $admin );", "self::assertStringContainsString( 'do not stay synchronized with WooCommerce', $javascript );"),
        ("self::assertStringContainsString( 'WooCommerce is not active.', $admin );", "self::assertStringContainsString( \"__('WooCommerce is not active. Existing WooCommerce filter values are preserved, but new live queries or snapshots cannot be run.', 'viswiz')\", $javascript );"),
        ("self::assertStringContainsString( 'does not have permission to run WooCommerce snapshots', $admin );", "self::assertStringContainsString( \"__('Your account does not have permission to run WooCommerce snapshots.', 'viswiz')\", $javascript );"),
        ("self::assertStringContainsString( \"liveOption.textContent = tr('liveOption'\", $javascript );", "self::assertStringContainsString( \"liveOption.textContent = __('WooCommerce live query', 'viswiz')\", $javascript );"),
        ("self::assertStringContainsString( \"snapshotButton.textContent = tr('snapshotButton'\", $javascript );", "self::assertStringContainsString( \"snapshotButton.textContent = __('Replace dataset with current snapshot', 'viswiz')\", $javascript );"),
    ],
}

for rel, pairs in replacements.items():
    path = ROOT / rel
    text = path.read_text()
    for old, new in pairs:
        if old not in text:
            raise SystemExit(f'missing expected pattern in {rel}: {old}')
        text = text.replace(old, new)
    path.write_text(text)

loc = ROOT / 'tests/JavaScriptLocalizationTest.php'
lines = loc.read_text().splitlines()
found = False
for i, line in enumerate(lines):
    if 'must not embed gettext interpolation inside a quoted literal.' in line:
        indent = line[:len(line) - len(line.lstrip())]
        lines[i] = indent + "self::assertDoesNotMatchRegularExpression( '/[\\\'\"][^\\\'\"\\r\\n]*\\$\\{__\\(/', $javascript, $file . ' must not embed gettext interpolation inside a quoted literal.' );"
        found = True
if not found:
    raise SystemExit('localization quoted-literal assertion not found')
loc.write_text('\n'.join(lines) + '\n')

print('Localization test contracts updated.')
