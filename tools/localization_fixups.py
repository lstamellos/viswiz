#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(path):
    return (ROOT / path).read_text()


def write(path, text):
    (ROOT / path).write_text(text)


def replace(path, old, new, expected=1):
    text = read(path)
    count = text.count(old)
    if count != expected:
        raise RuntimeError(f"{path}: expected {expected} occurrence(s), found {count}: {old[:120]!r}")
    write(path, text.replace(old, new))


# ---------------------------------------------------------------------------
# Repair partially removed PHP static-translation maps.
# ---------------------------------------------------------------------------
replace(
    'src/Admin/Admin.php',
    """                'relationTypes' => Registry::relation_types(),
                    'error'    => __( 'The change could not be saved.', 'viswiz' ),
                    'saved'    => __( 'Saved.', 'viswiz' ),
                    'confirmDelete' => __( 'Delete this item?', 'viswiz' ),
                ),
            )
""",
    """                'relationTypes' => Registry::relation_types(),
            )
""",
)

replace(
    'src/Admin/VisualizationPresets.php',
    """                'presets' => self::presets(),
                    'saved'          => __( 'Display preset saved.', 'viswiz' ),
                    'applied'        => __( 'Preset applied to unsaved display settings.', 'viswiz' ),
                    'nothingApplied' => __( 'This preset has no settings supported by the current renderer.', 'viswiz' ),
                    'deleted'        => __( 'Display preset deleted.', 'viswiz' ),
                    'saving'         => __( 'Saving preset…', 'viswiz' ),
                    'deleting'       => __( 'Deleting preset…', 'viswiz' ),
                    'nameRequired'   => __( 'Enter a preset name.', 'viswiz' ),
                    'requestError'   => __( 'The display preset change could not be saved.', 'viswiz' ),
                    'confirmDelete'  => __( 'Delete this display preset?', 'viswiz' ),
                ),
            )
""",
    """                'presets' => self::presets(),
            )
""",
)

replace(
    'src/Admin/WooSourceSelection.php',
    """                'categories'      => self::selected_category_labels( $is_visualization ),
                    'categories'             => __( 'Categories', 'viswiz' ),
                    'searchProducts'         => __( 'Search products…', 'viswiz' ),
                    'searchCategories'       => __( 'Search product categories…', 'viswiz' ),
                    'liveOption'             => __( 'WooCommerce live query', 'viswiz' ),
                    'liveDescription'        => __( 'Live query: recalculates from current WooCommerce orders when requested and uses the configured cache/refresh interval. No rows are copied into a dataset.', 'viswiz' ),
                    'snapshotDescription'    => __( 'Snapshot: runs the WooCommerce query once and replaces this canonical dataset with the current results. The copied rows can then be edited independently and do not stay synchronized with WooCommerce.', 'viswiz' ),
                    'snapshotButton'         => __( 'Replace dataset with current snapshot', 'viswiz' ),
                    'woocommerceInactive'    => __( 'WooCommerce is not active. Existing WooCommerce filter values are preserved, but new live queries or snapshots cannot be run.', 'viswiz' ),
                    'manualIdsFallback'      => __( 'WooCommerce search pickers are not available for this account. Product and category IDs remain editable manually.', 'viswiz' ),
                    'snapshotPermission'     => __( 'Your account does not have permission to run WooCommerce snapshots.', 'viswiz' ),
                    'graphSnapshotDisabled'  => __( 'WooCommerce snapshots require a row-based dataset and cannot replace graph data.', 'viswiz' ),
                ),
            )
""",
    """                'categories'      => self::selected_category_labels( $is_visualization ),
            )
""",
)

# Normalize wp-i18n dependency formatting in files touched by this feature.
for path in (
    'src/Admin/DatasetEditorPage.php',
    'src/Admin/ImportUi.php',
    'src/Admin/NodePublicFields.php',
    'src/Admin/NodeRichEditor.php',
    'src/Admin/SpreadsheetEditor.php',
    'src/Admin/VisualizationPreview.php',
    'src/Admin/VisualizationPresets.php',
    'src/Admin/WooSourceSelection.php',
    'src/Runtime/GraphRuntime.php',
):
    text = read(path)
    text = text.replace("array('viswiz-", "array( 'viswiz-")
    text = text.replace("'wp-i18n' ),", "'wp-i18n' ),")
    write(path, text)

replace(
    'src/Runtime/GraphRuntime.php',
    "wp_set_script_translations( 'viswiz-graph-runtime', 'viswiz', VISWIZ_DIR . 'languages' );",
    "wp_set_script_translations( self::SCRIPT_HANDLE, 'viswiz', VISWIZ_DIR . 'languages' );",
)

# ---------------------------------------------------------------------------
# Preserve useful request diagnostics while localizing the fallback.
# ---------------------------------------------------------------------------
for path in ('assets/viswiz-admin.js', 'assets/viswiz-dataset-editor.js', 'assets/viswiz-spreadsheet-editor.js'):
    replace(
        path,
        "const error = new Error(data?.message || __('The change could not be saved.', 'viswiz') || `HTTP ${response.status}`);",
        "const error = new Error(data?.message || sprintf(__('The request failed with HTTP status %d.', 'viswiz'), response.status));",
    )

replace(
    'assets/viswiz-import.js',
    "const error = new Error(data?.message || __('The import request failed.', 'viswiz') || `HTTP ${response.status}`);",
    "const error = new Error(data?.message || sprintf(__('The import request failed with HTTP status %d.', 'viswiz'), response.status));",
)

# ---------------------------------------------------------------------------
# Dataset editor: keep machine identifiers stable and localize display values.
# ---------------------------------------------------------------------------
replace(
    'assets/viswiz-dataset-editor.js',
    "const baseSlug = String(node.slug || node.title || __('node', 'viswiz')).toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || __('node', 'viswiz');",
    "const baseSlug = String(node.slug || node.title || 'node').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'node';",
)
replace(
    'assets/viswiz-dataset-editor.js',
    """      state,
      'From',
      'from_node_uuid',
""",
    """      state,
      __('From', 'viswiz'),
      'from_node_uuid',
""",
)
replace(
    'assets/viswiz-dataset-editor.js',
    """      state,
      'To',
      'to_node_uuid',
""",
    """      state,
      __('To', 'viswiz'),
      'to_node_uuid',
""",
)

path = 'assets/viswiz-dataset-editor.js'
text = read(path)
marker = "  function openRelationDialog(state, relation, options = {}) {"
if 'function directionLabel(value)' not in text:
    helper = """  function directionLabel(value) {
    const labels = {
      directed: __('Directed', 'viswiz'),
      bidirectional: __('Bidirectional', 'viswiz'),
      undirected: __('Undirected', 'viswiz'),
    };
    return labels[value] || value || labels.directed;
  }

"""
    if marker not in text:
        raise RuntimeError('dataset editor relation-dialog marker missing')
    text = text.replace(marker, helper + marker, 1)
    write(path, text)
replace(
    path,
    "${['directed', 'bidirectional', 'undirected'].map((direction) => `<option ${current.direction === direction ? 'selected' : ''}>${direction}</option>`).join('')}",
    "${['directed', 'bidirectional', 'undirected'].map((direction) => `<option value=\"${direction}\" ${current.direction === direction ? 'selected' : ''}>${directionLabel(direction)}</option>`).join('')}",
)

# Legacy admin graph editor: same enum contract, plus remaining dynamic status copy.
path = 'assets/viswiz-admin.js'
text = read(path)
marker = "  function openRelationDialog(root,state,rel){"
if 'function directionLabel(value)' not in text:
    helper = """  function directionLabel(value){const labels={directed:__('Directed','viswiz'),bidirectional:__('Bidirectional','viswiz'),undirected:__('Undirected','viswiz')};return labels[value]||value||labels.directed;}

"""
    if marker not in text:
        raise RuntimeError('admin relation-dialog marker missing')
    text = text.replace(marker, helper + marker, 1)
    write(path, text)
replace(
    path,
    "${['directed','bidirectional','undirected'].map((d)=>`<option ${current.direction===d?'selected':''}>${d}</option>`).join('')}",
    "${['directed','bidirectional','undirected'].map((d)=>`<option value=\"${d}\" ${current.direction===d?'selected':''}>${directionLabel(d)}</option>`).join('')}",
)
replace(
    path,
    "pager.append(previous, statusText(`Page ${pageInfo.page + 1} / ${pageInfo.maxPage + 1} · ${total} ${noun}`), next);",
    "pager.append(previous, statusText(sprintf(__('Page %1$d / %2$d · %3$d %4$s', 'viswiz'), pageInfo.page + 1, pageInfo.maxPage + 1, total, noun)), next);",
)
replace(
    path,
    "const add = button(__('Add row', 'viswiz'), 'button button-primary'); bar.append(add, statusText(`${visible.length} / ${rows.length} rows · revision ${state.revision}`)); root.appendChild(bar);",
    "const add = button(__('Add row', 'viswiz'), 'button button-primary'); bar.append(add, statusText(sprintf(__('%1$d / %2$d rows · revision %3$d', 'viswiz'), visible.length, rows.length, state.revision))); root.appendChild(bar);",
)
replace(
    path,
    "bar.append(addNode, addRelation, statusText(`${visibleNodes.length}/${nodes.length} nodes · ${visibleRelations.length}/${relations.length} relations · revision ${state.revision}`)); root.appendChild(bar);",
    "bar.append(addNode, addRelation, statusText(sprintf(__('%1$d/%2$d nodes · %3$d/%4$d relations · revision %5$d', 'viswiz'), visibleNodes.length, nodes.length, visibleRelations.length, relations.length, state.revision))); root.appendChild(bar);",
)
replace(path, "}, 'rows');", "}, __('rows', 'viswiz'));", expected=1)
replace(path, "}, 'nodes');", "}, __('nodes', 'viswiz'));", expected=1)
replace(path, "}, 'relations');", "}, __('relations', 'viswiz'));", expected=1)
replace(path, "${esc(rel.direction||'directed')}", "${esc(directionLabel(rel.direction || 'directed'))}", expected=1)

# ---------------------------------------------------------------------------
# Fix gettext calls accidentally embedded in ordinary quoted literals.
# ---------------------------------------------------------------------------
replace(
    'assets/viswiz-import.js',
    "${schema === 'graph' ? '<label class=\"viswiz-field\"><span>${__(\'Graph data\', \'viswiz\')}</span><select data-viswiz-import-kind><option value=\"nodes\">${__(\'Nodes\', \'viswiz\')}</option><option value=\"relations\">${__(\'Relations\', \'viswiz\')}</option></select></label>' : ''}",
    "${schema === 'graph' ? `<label class=\"viswiz-field\"><span>${__('Graph data', 'viswiz')}</span><select data-viswiz-import-kind><option value=\"nodes\">${__('Nodes', 'viswiz')}</option><option value=\"relations\">${__('Relations', 'viswiz')}</option></select></label>` : ''}",
)
replace(
    'assets/viswiz-import.js',
    "append: '${__('Append adds records without changing existing ones.', 'viswiz')}',",
    "append: __('Append adds records without changing existing ones.', 'viswiz'),",
)
replace(
    'assets/viswiz-import.js',
    "const name = chosen === '\\t' ? 'tab' : chosen === ',' ? 'comma' : chosen === ';' ? 'semicolon' : 'pipe';",
    "const name = chosen === '\\t' ? __('tab', 'viswiz') : chosen === ',' ? __('comma', 'viswiz') : chosen === ';' ? __('semicolon', 'viswiz') : __('pipe', 'viswiz');",
)

replace(
    'assets/viswiz-spreadsheet-editor.js',
    "${sheet.conflict ? '<button type=\"button\" class=\"button\" data-grid-action=\"reload\">${__(\'Reload server version\', \'viswiz\')}</button>' : ''}",
    "${sheet.conflict ? `<button type=\"button\" class=\"button\" data-grid-action=\"reload\">${__('Reload server version', 'viswiz')}</button>` : ''}",
)
replace(
    'assets/viswiz-spreadsheet-editor.js',
    "${dirty ? '<p class=\"viswiz-grid-unsaved-note\">${__(\'Save or discard the pending grid changes before searching, changing pages or replacing dataset state from another control.\', \'viswiz\')}</p>' : ''}",
    "${dirty ? `<p class=\"viswiz-grid-unsaved-note\">${__('Save or discard the pending grid changes before searching, changing pages or replacing dataset state from another control.', 'viswiz')}</p>` : ''}",
)
replace(
    'assets/viswiz-spreadsheet-editor.js',
    "<span>${shownTotal} ${esc(editor.plural || 'rows')} · ${esc(cfg.schemas?.[sheet.schema]?.label || sheet.schema)}</span>",
    "<span>${shownTotal} ${esc(editor.plural || __('rows', 'viswiz'))} · ${esc(cfg.schemas?.[sheet.schema]?.label || sheet.schema)}</span>",
)
replace(
    'assets/viswiz-spreadsheet-editor.js',
    "}).join('') : `<tr class=\"viswiz-grid-empty\"><td colspan=\"${fields.length + 2}\">No ${esc(editor.plural || 'rows')} found. Add a row or paste data to begin.</td></tr>`}",
    "}).join('') : `<tr class=\"viswiz-grid-empty\"><td colspan=\"${fields.length + 2}\">${esc(sprintf(__('No %s found. Add a row or paste data to begin.', 'viswiz'), editor.plural || __('rows', 'viswiz')))}</td></tr>`}",
)

replace('assets/viswiz-node-public-fields.js', "labelWrap.innerHTML = '<span>${__('Label', 'viswiz')}</span>';", "labelWrap.innerHTML = `<span>${__('Label', 'viswiz')}</span>`;")
replace('assets/viswiz-node-public-fields.js', "typeWrap.innerHTML = '<span>${__('Type', 'viswiz')}</span>';", "typeWrap.innerHTML = `<span>${__('Type', 'viswiz')}</span>`;")
replace('assets/viswiz-node-public-fields.js', "valueWrap.innerHTML = '<span>${__('Value', 'viswiz')}</span>';", "valueWrap.innerHTML = `<span>${__('Value', 'viswiz')}</span>`;")

# Clean dead imports/config aliases and line joins left by the codemod.
replace('assets/viswiz-graph-runtime.js', "const { __, sprintf } = window.wp.i18n;", "const { __ } = window.wp.i18n;")
replace('assets/viswiz-graph-runtime.js', "const enhancedModals = new WeakSet();  const $ =", "const enhancedModals = new WeakSet();\n  const $ =")
replace('assets/viswiz-visualization-presets.js', "const { __, sprintf } = window.wp.i18n;", "const { __ } = window.wp.i18n;")
replace('assets/viswiz-visualization-presets.js', "const adminCfg = window.VisWizAdminV2 || {};  const $ =", "const adminCfg = window.VisWizAdminV2 || {};\n  const $ =")
replace('assets/viswiz-visualization-preview.js', "const { __, sprintf } = window.wp.i18n;", "const { __ } = window.wp.i18n;")
replace('assets/viswiz-visualization-preview.js', "  const previewCfg = window.VisWizVisualizationPreview || {};  const $ = (selector, root = document) => root.querySelector(selector);\n", "  const $ = (selector, root = document) => root.querySelector(selector);\n")

# ---------------------------------------------------------------------------
# Strengthen regression coverage for every class of audit regression.
# ---------------------------------------------------------------------------
path = 'tests/JavaScriptLocalizationTest.php'
text = read(path)
needle = "            self::assertStringNotContainsString( 'const tr = (key, fallback)', $javascript, $file );\n"
insert = needle + "            self::assertDoesNotMatchRegularExpression( '/(?:=|:|\\?)[ \\t]*[\\\'\"][^\\r\\n]*\\$\\{__\\(/', $javascript, $file . ' must not embed gettext interpolation inside a quoted literal.' );\n"
if text.count(needle) != 1:
    raise RuntimeError('localization test loop marker missing or duplicated')
text = text.replace(needle, insert, 1)
marker = "    public function test_static_translation_maps_are_removed_from_php_config(): void {\n"
extra = """    public function test_machine_identifiers_and_relation_enum_values_stay_stable(): void {
        $dataset = file_get_contents( $this->root . '/assets/viswiz-dataset-editor.js' );
        self::assertStringNotContainsString( "node.slug || node.title || __('node', 'viswiz')", $dataset );
        self::assertStringContainsString( 'value="${direction}"', $dataset );
        self::assertStringContainsString( "__('Directed', 'viswiz')", $dataset );
    }

"""
if text.count(marker) != 1:
    raise RuntimeError('localization test method marker missing or duplicated')
text = text.replace(marker, extra + marker, 1)
write(path, text)

print('Localization audit fixups applied successfully.')
