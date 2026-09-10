from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def apply(rel, pairs):
    path = ROOT / rel
    text = path.read_text()
    for old, new in pairs:
        if old not in text:
            raise SystemExit(f'missing expected pattern in {rel}: {old}')
        text = text.replace(old, new)
    path.write_text(text)


apply('tests/AdminDialogKeyboardTest.php', [
    ("cfg.i18n?.confirmDelete || 'Delete this item?'", "__('Delete this item?', 'viswiz')"),
])
apply('tests/GraphEditorWorkflowTest.php', [
    ("button('Create node…'", "button(__('Create node…', 'viswiz')"),
])
apply('tests/GraphRuntimeTest.php', [
    ("array( 'viswiz-frontend' )", "array( 'viswiz-frontend', 'wp-i18n' )"),
])
apply('tests/ImportWorkflowTest.php', [
    ("['external_key', 'External key'", "['external_key', __('External key', 'viswiz')"),
    ("['from_key', 'From node key'", "['from_key', __('From node key', 'viswiz')"),
    ("['to_key', 'To node key'", "['to_key', __('To node key', 'viswiz')"),
    ('<option value=\\"nodes\\">Nodes</option><option value=\\"relations\\">Relations</option>', '<option value=\\"nodes\\">${__(\'Nodes\', \'viswiz\')}</option><option value=\\"relations\\">${__(\'Relations\', \'viswiz\')}</option>'),
])
apply('tests/NodePublicFieldsTest.php', [
    ("array( 'viswiz-dataset-editor-v2' )", "array( 'viswiz-dataset-editor-v2', 'wp-i18n' )"),
])
apply('tests/NodeRichEditorTest.php', [
    ("array( 'editor', 'viswiz-dataset-editor-v2' )", "array( 'editor', 'viswiz-dataset-editor-v2', 'wp-i18n' )"),
])
apply('tests/PublicGraphAccessibilityTest.php', [
    ("${tr('viewNode'", "${__('View node', 'viswiz')"),
])
apply('tests/RendererSpecificSettingsTest.php', [
    ("array( 'viswiz-renderer-settings', 'viswiz-frontend', 'viswiz-graph-runtime' )", "array( 'viswiz-renderer-settings', 'viswiz-frontend', 'viswiz-graph-runtime', 'wp-i18n' )"),
])
apply('tests/SpreadsheetEditorTest.php', [
    ("array( 'viswiz-dataset-editor-v2' )", "array( 'viswiz-dataset-editor-v2', 'wp-i18n' )"),
])
apply('tests/VisualizationPresetsTest.php', [
    ("array( 'viswiz-visualization-preview' )", "array( 'viswiz-visualization-preview', 'wp-i18n' )"),
])

woo = ROOT / 'tests/WooSourceSelectionTest.php'
text = woo.read_text()
for phrase in [
    'Live query: recalculates from current WooCommerce orders',
    'No rows are copied into a dataset.',
    'Snapshot: runs the WooCommerce query once',
    'do not stay synchronized with WooCommerce',
    'WooCommerce is not active.',
    'does not have permission to run WooCommerce snapshots',
]:
    old = f"self::assertStringContainsString( '{phrase}', $admin );"
    if old not in text:
        raise SystemExit(f'missing Woo ownership assertion: {phrase}')
    text = text.replace(old, f"self::assertStringContainsString( '{phrase}', $javascript );")
for old, new in [
    ("liveOption.textContent = tr('liveOption'", "liveOption.textContent = __('WooCommerce live query', 'viswiz')"),
    ("snapshotButton.textContent = tr('snapshotButton'", "snapshotButton.textContent = __('Replace dataset with current snapshot', 'viswiz')"),
]:
    if old not in text:
        raise SystemExit(f'missing Woo JS assertion: {old}')
    text = text.replace(old, new)
woo.write_text(text)

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
