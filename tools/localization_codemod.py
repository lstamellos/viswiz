#!/usr/bin/env python3
from pathlib import Path
import json
import re

ROOT = Path(__file__).resolve().parents[1]
DOMAIN = 'viswiz'


def read(path):
    return (ROOT / path).read_text()


def write(path, text):
    target = ROOT / path
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_text(text)


def replace(path, old, new, required=True):
    text = read(path)
    if old not in text:
        if required:
            raise RuntimeError(f'missing expected pattern in {path}: {old[:120]!r}')
        return False
    write(path, text.replace(old, new))
    return True


def regex(path, pattern, repl, required=False, flags=0):
    text = read(path)
    updated, count = re.subn(pattern, repl, text, flags=flags)
    if required and not count:
        raise RuntimeError(f'missing regex in {path}: {pattern}')
    if count:
        write(path, updated)
    return count


def ensure_i18n(path, names):
    text = read(path)
    marker = "  'use strict';\n"
    declaration = f"  const {{ {', '.join(names)} }} = window.wp.i18n;\n"
    if declaration in text:
        return
    if marker not in text:
        raise RuntimeError(f'use strict marker missing in {path}')
    write(path, text.replace(marker, marker + declaration, 1))


def remove_php_array_entry(path, key):
    lines = read(path).splitlines(keepends=True)
    start = next((i for i, line in enumerate(lines) if re.search(rf"'{re.escape(key)}'\s*=>\s*array\(", line)), None)
    if start is None:
        raise RuntimeError(f'{key} array missing in {path}')
    depth = 0
    end = None
    for i in range(start, len(lines)):
        depth += lines[i].count('array(')
        depth -= lines[i].count(')')
        if i > start and depth <= 0:
            end = i
            break
    if end is None:
        raise RuntimeError(f'unclosed {key} array in {path}')
    del lines[start:end + 1]
    write(path, ''.join(lines))


def remove_wp_localize(path, object_name):
    lines = read(path).splitlines(keepends=True)
    start = None
    for i, line in enumerate(lines):
        if 'wp_localize_script(' in line:
            window = ''.join(lines[i:i + 8])
            if object_name in window:
                start = i
                break
    if start is None:
        raise RuntimeError(f'wp_localize_script for {object_name} missing in {path}')
    depth = 0
    end = None
    started = False
    for i in range(start, len(lines)):
        depth += lines[i].count('(')
        depth -= lines[i].count(')')
        started = True
        if started and depth <= 0 and ';' in lines[i]:
            end = i
            break
    if end is None:
        raise RuntimeError(f'unclosed wp_localize_script in {path}')
    del lines[start:end + 1]
    write(path, ''.join(lines))


def script_call_range(lines, handle):
    source = ''.join(lines)
    constant_handle = bool(re.search(rf"const\s+SCRIPT_HANDLE\s*=\s*'{re.escape(handle)}'", source))
    for start, line in enumerate(lines):
        if 'wp_enqueue_script(' not in line and 'wp_register_script(' not in line:
            continue
        depth = 0
        call = []
        for end in range(start, len(lines)):
            call.append(lines[end])
            depth += lines[end].count('(')
            depth -= lines[end].count(')')
            if depth <= 0 and ';' in lines[end]:
                break
        block = ''.join(call)
        if f"'{handle}'" in block or (constant_handle and 'self::SCRIPT_HANDLE' in block):
            return start, end
    raise RuntimeError(f'script call for {handle} missing')

def add_wp_i18n_dependency(path, handle):
    lines = read(path).splitlines(keepends=True)
    start, end = script_call_range(lines, handle)
    block = ''.join(lines[start:end + 1])
    if "'wp-i18n'" in block:
        return
    if '$dependencies' in block:
        source = ''.join(lines)
        pattern = re.compile(r"\$dependencies\s*=\s*array\(([^)]*)\);")
        match = pattern.search(source)
        if not match:
            raise RuntimeError(f'dynamic dependency declaration for {handle} missing in {path}')
        inner = match.group(1).strip()
        if "'wp-i18n'" not in inner:
            inner = inner.rstrip() + ", 'wp-i18n'"
            source = source[:match.start(1)] + inner + source[match.end(1):]
            write(path, source)
        return
    pattern = re.compile(r'array\(([^()]*)\)')
    matches = list(pattern.finditer(block))
    if not matches:
        raise RuntimeError(f'dependency array for {handle} missing in {path}')
    match = matches[-1]
    inner = match.group(1).strip()
    addition = " 'wp-i18n' " if not inner else inner.rstrip() + ", 'wp-i18n' "
    block = block[:match.start()] + 'array(' + addition + ')' + block[match.end():]
    lines[start:end + 1] = block.splitlines(keepends=True)
    write(path, ''.join(lines))

def add_script_translations(path, handle):
    text = read(path)
    call = f"wp_set_script_translations( '{handle}', 'viswiz', VISWIZ_DIR . 'languages' );"
    if call in text:
        return
    lines = text.splitlines(keepends=True)
    start, end = script_call_range(lines, handle)
    indent = re.match(r'\s*', lines[start]).group(0)
    lines.insert(end + 1, indent + call + '\n')
    write(path, ''.join(lines))

def convert_tr(path, pre_remove_patterns=()):
    text = read(path)
    for pattern in pre_remove_patterns:
        text = re.sub(pattern, '', text, flags=re.S)
    text = re.sub(r"\n\s*const i18n = (?:cfg|previewCfg)\.i18n \|\| \{\};\n", "\n", text)
    text = re.sub(r"\n\s*const tr = \(key, fallback\) => i18n\[key\] \|\| fallback;\n", "\n", text)
    text, count = re.subn(r"tr\('(?:[^'\\]|\\.)*',\s*'((?:[^'\\]|\\.)*)'\)", lambda m: "__('" + m.group(1) + "', 'viswiz')", text)
    if not count:
        raise RuntimeError(f'no tr() calls converted in {path}')
    write(path, text)

def wrap_builder_literals(path):
    text = read(path)
    pattern = re.compile(r"\b(button|field|textareaField)\('([A-Za-z][^'\\]*)'")
    def repl(m):
        return f"{m.group(1)}(__('{m.group(2)}', 'viswiz')"
    text = pattern.sub(repl, text)
    write(path, text)


def js_replace(path, mapping):
    text = read(path)
    for old, new in mapping:
        if old in text:
            text = text.replace(old, new)
    write(path, text)


# ---------------------------------------------------------------------------
# Replace adapter-local translation maps with direct WordPress gettext calls.
# ---------------------------------------------------------------------------
remove_php_array_entry('src/Admin/Admin.php', 'i18n')
remove_wp_localize('src/Admin/VisualizationPreview.php', 'VisWizVisualizationPreview')
remove_php_array_entry('src/Admin/VisualizationPresets.php', 'i18n')
remove_php_array_entry('src/Admin/WooSourceSelection.php', 'i18n')
remove_wp_localize('src/Frontend/Frontend.php', 'VisWizFrontendV2')

# Explicit wp-i18n dependency + script translation domain/path for every script
# that authors user-visible JavaScript copy.
script_handles = {
    'src/Admin/Admin.php': ['viswiz-admin-v2'],
    'src/Admin/DatasetEditorPage.php': ['viswiz-dataset-editor-v2'],
    'src/Admin/ImportUi.php': ['viswiz-import-v2'],
    'src/Admin/NodePublicFields.php': ['viswiz-node-public-fields'],
    'src/Admin/NodeRichEditor.php': ['viswiz-node-rich-editor'],
    'src/Admin/SpreadsheetEditor.php': ['viswiz-spreadsheet-editor-v2'],
    'src/Admin/VisualizationPreview.php': ['viswiz-renderer-settings', 'viswiz-visualization-preview'],
    'src/Admin/VisualizationPresets.php': ['viswiz-visualization-presets'],
    'src/Admin/WooSourceSelection.php': ['viswiz-woo-source-selection'],
    'src/Frontend/Frontend.php': ['viswiz-frontend', 'viswiz-block-editor'],
    'src/Runtime/GraphRuntime.php': ['viswiz-graph-runtime'],
}
for php_path, handles in script_handles.items():
    for handle in handles:
        add_wp_i18n_dependency(php_path, handle)
        add_script_translations(php_path, handle)

# ---------------------------------------------------------------------------
# Public renderer: direct wp.i18n calls, no VisWizFrontendV2 map.
# ---------------------------------------------------------------------------
ensure_i18n('assets/viswiz.js', ['__', 'sprintf'])
convert_tr(
    'assets/viswiz.js',
    [r"\n\s*const i18n = window\.VisWizFrontendV2\?\.i18n \|\| \{\};\n\s*const tr = \(key, fallback\) => i18n\[key\] \|\| fallback;\n"]
)
js_replace('assets/viswiz.js', [
    ("row.label || `Section ${i + 1}`", "row.label || sprintf(__('Section %d', 'viswiz'), i + 1)"),
])

# Graph enhancement runtime: remove manual Greek/English dictionaries. Greek
# translations are supplied below as normal Jed JSON catalogs.
ensure_i18n('assets/viswiz-graph-runtime.js', ['__', 'sprintf'])
convert_tr(
    'assets/viswiz-graph-runtime.js',
    [r"\n\s*const i18n = window\.VisWizFrontendV2\?\.i18n \|\| \{\};.*?\n\s*const tr = \(key, fallback = ''\) => i18n\[key\] \|\| labels\[key\] \|\| fallback \|\| key;\n"]
)

# ---------------------------------------------------------------------------
# Small admin adapters.
# ---------------------------------------------------------------------------
ensure_i18n('assets/viswiz-renderer-settings.js', ['__'])
js_replace('assets/viswiz-renderer-settings.js', [
    ("section('data', 'Data / source')", "section('data', __('Data / source', 'viswiz'))"),
    ("section('appearance', 'Appearance')", "section('appearance', __('Appearance', 'viswiz'))"),
    ("section('labels', 'Labels / content')", "section('labels', __('Labels / content', 'viswiz'))"),
    ("section('interaction', 'Interaction')", "section('interaction', __('Interaction', 'viswiz'))"),
    ("section('advanced', 'Advanced')", "section('advanced', __('Advanced', 'viswiz'))"),
])

ensure_i18n('assets/viswiz-node-rich-editor.js', ['__'])
js_replace('assets/viswiz-node-rich-editor.js', [
    ("|| 'Description';", "|| __('Description', 'viswiz');"),
])

ensure_i18n('assets/viswiz-node-public-fields.js', ['__', 'sprintf'])
wrap_builder_literals('assets/viswiz-node-public-fields.js')
js_replace('assets/viswiz-node-public-fields.js', [
    ("short: 'Short text'", "short: __('Short text', 'viswiz')"),
    ("long: 'Long text'", "long: __('Long text', 'viswiz')"),
    ("url: 'URL'", "url: __('URL', 'viswiz')"),
    ("formatted: 'Formatted HTML'", "formatted: __('Formatted HTML', 'viswiz')"),
    ("'Public field value'", "__('Public field value', 'viswiz')"),
    ("`field ${index + 1}`", "sprintf(__('field %d', 'viswiz'), index + 1)"),
    ("`Move ${label} up`", "sprintf(__('Move %s up', 'viswiz'), label)"),
    ("`Move ${label} down`", "sprintf(__('Move %s down', 'viswiz'), label)"),
    ("`Remove ${label}`", "sprintf(__('Remove %s', 'viswiz'), label)"),
    ('<span>Label</span>', "<span>${__('Label', 'viswiz')}</span>"),
    ('<span>Type</span>', "<span>${__('Type', 'viswiz')}</span>"),
    ('<span>Value</span>', "<span>${__('Value', 'viswiz')}</span>"),
    ("copy.innerHTML = '<h3>Public fields</h3><p class=\"description\">Structured details shown in the public node information view. Order here is the public display order.</p>';", "copy.innerHTML = `<h3>${__('Public fields', 'viswiz')}</h3><p class=\"description\">${__('Structured details shown in the public node information view. Order here is the public display order.', 'viswiz')}</p>`;"),
    ("add.textContent = 'Add public field';", "add.textContent = __('Add public field', 'viswiz');"),
    ("remove.textContent = 'Remove';", "remove.textContent = __('Remove', 'viswiz');"),
    ("empty.textContent = 'No public fields yet.';", "empty.textContent = __('No public fields yet.', 'viswiz');"),
    ("summary.textContent = 'Advanced metadata';", "summary.textContent = __('Advanced metadata', 'viswiz');"),
    ("if (labelText) labelText.textContent = nodeMetadata ? 'Additional metadata JSON' : 'Metadata JSON';", "if (labelText) labelText.textContent = nodeMetadata ? __('Additional metadata JSON', 'viswiz') : __('Metadata JSON', 'viswiz');"),
    ("description.textContent = nodeMetadata\n      ? 'Reserved for uncommon or integration-specific metadata. Public fields are managed above.'\n      : 'Reserved for uncommon or integration-specific relation metadata.';", "description.textContent = nodeMetadata\n      ? __('Reserved for uncommon or integration-specific metadata. Public fields are managed above.', 'viswiz')\n      : __('Reserved for uncommon or integration-specific relation metadata.', 'viswiz');"),
])

for small_path, preamble in [
    ('assets/viswiz-visualization-preview.js', r"\n\s*const i18n = previewCfg\.i18n \|\| \{\};\n\s*const tr = \(key, fallback\) => i18n\[key\] \|\| fallback;\n"),
    ('assets/viswiz-visualization-presets.js', r"\n\s*const i18n = cfg\.i18n \|\| \{\};\n\s*const tr = \(key, fallback\) => i18n\[key\] \|\| fallback;\n"),
    ('assets/viswiz-woo-source-selection.js', r"\n\s*const i18n = cfg\.i18n \|\| \{\};\n\s*const tr = \(key, fallback\) => i18n\[key\] \|\| fallback;\n"),
]:
    ensure_i18n(small_path, ['__', 'sprintf'])
    convert_tr(small_path, [preamble])

# Woo fallback labels that are dynamically formatted.
js_replace('assets/viswiz-woo-source-selection.js', [
    ("labels[String(id)] || `${kind === 'product' ? 'Product' : 'Category'} #${id}`", "labels[String(id)] || (kind === 'product' ? sprintf(__('Product #%d', 'viswiz'), id) : sprintf(__('Category #%d', 'viswiz'), id))"),
])

# ---------------------------------------------------------------------------
# Canonical dataset editor.
# ---------------------------------------------------------------------------
ensure_i18n('assets/viswiz-dataset-editor.js', ['__', '_n', 'sprintf'])
wrap_builder_literals('assets/viswiz-dataset-editor.js')
js_replace('assets/viswiz-dataset-editor.js', [
    ("cfg.i18n?.error || `HTTP ${response.status}`", "__('The change could not be saved.', 'viswiz') || `HTTP ${response.status}`"),
    ("cfg.i18n?.saved || 'Saved.'", "__('Saved.', 'viswiz')"),
    ("cfg.i18n?.conflict || error.message", "__('This dataset changed in another editor. Reload before saving.', 'viswiz')"),
    ("const text = String(value || 'row');", "const text = String(value || __('row', 'viswiz'));"),
    ("noun: 'row',", "noun: __('row', 'viswiz'),"),
    ("plural: 'rows',", "plural: __('rows', 'viswiz'),"),
    ("label: 'Label'", "label: __('Label', 'viswiz')"),
    ("label: 'Value'", "label: __('Value', 'viswiz')"),
    ("close.setAttribute('aria-label', 'Close');", "close.setAttribute('aria-label', __('Close', 'viswiz'));"),
    ("statusText('Server paged')", "statusText(__('Server paged', 'viswiz'))"),
    ("`No ${esc(editor.plural || 'rows')} found.`", "sprintf(__('No %s found.', 'viswiz'), esc(editor.plural || __('rows', 'viswiz')))"),
    ("const modal = makeDialog(`${row ? 'Edit' : 'Add'} ${noun}`);", "const modal = makeDialog(row ? sprintf(__('Edit %s', 'viswiz'), noun) : sprintf(__('Add %s', 'viswiz'), noun));"),
    ('<summary>Advanced</summary>', "<summary>${__('Advanced', 'viswiz')}</summary>"),
    ('>Cancel</button><button type="submit" class="button button-primary">Save ${esc(noun)}</button>', ">${__('Cancel', 'viswiz')}</button><button type=\"submit\" class=\"button button-primary\">${sprintf(__('Save %s', 'viswiz'), esc(noun))}</button>"),
    ("'Additional metadata JSON is invalid.'", "__('Additional metadata JSON is invalid.', 'viswiz')"),
    ("'Additional metadata must be a JSON object.'", "__('Additional metadata must be a JSON object.', 'viswiz')"),
    ("return node?.title || node?.label || node?.slug || node?.uuid || 'Node';", "return node?.title || node?.label || node?.slug || node?.uuid || __('Node', 'viswiz');"),
    ("node.title || 'node'", "node.title || __('node', 'viswiz')"),
    ("|| 'node';", "|| __('node', 'viswiz');"),
    ("title: `${nodeTitle(node)} copy`", "title: sprintf(__('%s copy', 'viswiz'), nodeTitle(node))"),
    ("nodeSection.innerHTML = '<h3>Nodes</h3>';", "nodeSection.innerHTML = `<h3>${__('Nodes', 'viswiz')}</h3>`;"),
    ("table.innerHTML = '<thead><tr><th>Node</th><th>Type</th><th>Slug</th><th>Degree</th><th></th></tr></thead><tbody></tbody>';", "table.innerHTML = `<thead><tr><th>${__('Node', 'viswiz')}</th><th>${__('Type', 'viswiz')}</th><th>${__('Slug', 'viswiz')}</th><th>${__('Degree', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;"),
    ("tbody.innerHTML = '<tr><td colspan=\"5\">No nodes found.</td></tr>';", "tbody.innerHTML = `<tr><td colspan=\"5\">${__('No nodes found.', 'viswiz')}</td></tr>`;"),
    ("{ title: 'Duplicate node'", "{ title: __('Duplicate node', 'viswiz')"),
    ("relationSection.innerHTML = '<h3>Relations</h3>';", "relationSection.innerHTML = `<h3>${__('Relations', 'viswiz')}</h3>`;"),
    ("rtable.innerHTML = '<thead><tr><th>From</th><th>Relation</th><th>To</th><th>Direction</th><th></th></tr></thead><tbody></tbody>';", "rtable.innerHTML = `<thead><tr><th>${__('From', 'viswiz')}</th><th>${__('Relation', 'viswiz')}</th><th>${__('To', 'viswiz')}</th><th>${__('Direction', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;"),
    ("rbody.innerHTML = '<tr><td colspan=\"5\">No relations found.</td></tr>';", "rbody.innerHTML = `<tr><td colspan=\"5\">${__('No relations found.', 'viswiz')}</td></tr>`;"),
    ("rel.from_title || rel.from_slug || 'Missing'", "rel.from_title || rel.from_slug || __('Missing', 'viswiz')"),
    ("rel.to_title || rel.to_slug || 'Missing'", "rel.to_title || rel.to_slug || __('Missing', 'viswiz')"),
    ("{ title: 'Duplicate relation'", "{ title: __('Duplicate relation', 'viswiz')"),
    ("panel.innerHTML = '<p class=\"description\">Loading connected relations…</p>';", "panel.innerHTML = `<p class=\"description\">${__('Loading connected relations…', 'viswiz')}</p>`;"),
    ("headingText.innerHTML = `<h3>Connected relations</h3><p class=\"description\">${meta.total} relation${meta.total === 1 ? '' : 's'} for ${esc(nodeTitle(node))}.</p>`;", "headingText.innerHTML = `<h3>${__('Connected relations', 'viswiz')}</h3><p class=\"description\">${sprintf(_n('%1$d relation for %2$s.', '%1$d relations for %2$s.', meta.total, 'viswiz'), meta.total, esc(nodeTitle(node)))}</p>`;"),
    ("table.innerHTML = '<thead><tr><th>Role</th><th>Relation</th><th>Other node</th><th></th></tr></thead><tbody></tbody>';", "table.innerHTML = `<thead><tr><th>${__('Role', 'viswiz')}</th><th>${__('Relation', 'viswiz')}</th><th>${__('Other node', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;"),
    ("body.innerHTML = '<tr><td colspan=\"4\">No connected relations.</td></tr>';", "body.innerHTML = `<tr><td colspan=\"4\">${__('No connected relations.', 'viswiz')}</td></tr>`;"),
    ("${outgoing ? 'Outgoing' : 'Incoming'}", "${outgoing ? __('Outgoing', 'viswiz') : __('Incoming', 'viswiz')}"),
    ("relation.relation_type || 'Unspecified'", "relation.relation_type || __('Unspecified', 'viswiz')"),
    ("options.title || (node ? 'Edit node' : 'Add node')", "options.title || (node ? __('Edit node', 'viswiz') : __('Add node', 'viswiz'))"),
    ('<span>Node type</span>', "<span>${__('Node type', 'viswiz')}</span>"),
    ('>Select type</option>', ">${__('Select type', 'viswiz')}</option>"),
    ('<span>Subtype</span>', "<span>${__('Subtype', 'viswiz')}</span>"),
    ('>Cancel</button><button type="submit" class="button button-primary">Save node</button>', ">${__('Cancel', 'viswiz')}</button><button type=\"submit\" class=\"button button-primary\">${__('Save node', 'viswiz')}</button>"),
    ("title: 'Choose featured image'", "title: __('Choose featured image', 'viswiz')"),
    ("title: 'Choose node images'", "title: __('Choose node images', 'viswiz')"),
    ("subtype.innerHTML = '<option value=\"\">No subtype</option>'", "subtype.innerHTML = `<option value=\"\">${__('No subtype', 'viswiz')}</option>`"),
    ("'Metadata JSON is invalid.'", "__('Metadata JSON is invalid.', 'viswiz')"),
    ("search.placeholder = 'Search nodes…';", "search.placeholder = __('Search nodes…', 'viswiz');"),
    ("`${label} node search`", "sprintf(__('%s node search', 'viswiz'), label)"),
    ("`${label} node`", "sprintf(__('%s node', 'viswiz'), label)"),
    ("`Create ${label.toLowerCase()} node`", "sprintf(__('Create %s node', 'viswiz'), label.toLowerCase())"),
    ("`Source should be ${nodeTypeLabel(schema.source_type)}; selected ${nodeTypeLabel(fromNode.node_type)}.`", "sprintf(__('Source should be %1$s; selected %2$s.', 'viswiz'), nodeTypeLabel(schema.source_type), nodeTypeLabel(fromNode.node_type))"),
    ("`Source subtype should be ${subtypeLabel(schema.source_type, schema.source_subtype)}; selected ${subtypeLabel(fromNode.node_type, fromNode.node_subtype)}.`", "sprintf(__('Source subtype should be %1$s; selected %2$s.', 'viswiz'), subtypeLabel(schema.source_type, schema.source_subtype), subtypeLabel(fromNode.node_type, fromNode.node_subtype))"),
    ("`Target should be ${nodeTypeLabel(schema.target_type)}; selected ${nodeTypeLabel(toNode.node_type)}.`", "sprintf(__('Target should be %1$s; selected %2$s.', 'viswiz'), nodeTypeLabel(schema.target_type), nodeTypeLabel(toNode.node_type))"),
    ("`Target subtype should be ${subtypeLabel(schema.target_type, schema.target_subtype)}; selected ${subtypeLabel(toNode.node_type, toNode.node_subtype)}.`", "sprintf(__('Target subtype should be %1$s; selected %2$s.', 'viswiz'), subtypeLabel(schema.target_type, schema.target_subtype), subtypeLabel(toNode.node_type, toNode.node_subtype))"),
    ("options.title || (relation ? 'Edit relation' : 'Add relation')", "options.title || (relation ? __('Edit relation', 'viswiz') : __('Add relation', 'viswiz'))"),
    ("'Current source'", "__('Current source', 'viswiz')"),
    ("'Current target'", "__('Current target', 'viswiz')"),
    ('<span>Relation type</span>', "<span>${__('Relation type', 'viswiz')}</span>"),
    ('>Unspecified</option>', ">${__('Unspecified', 'viswiz')}</option>"),
    ('<span>Direction</span>', "<span>${__('Direction', 'viswiz')}</span>"),
    ('>Cancel</button><button type="submit" class="button button-primary">Save relation</button>', ">${__('Cancel', 'viswiz')}</button><button type=\"submit\" class=\"button button-primary\">${__('Save relation', 'viswiz')}</button>"),
    ("title: `Create ${side.toLowerCase()} node`", "title: sprintf(__('Create %s node', 'viswiz'), side.toLowerCase())"),
    ("'Choose both relation endpoints.'", "__('Choose both relation endpoints.', 'viswiz')"),
    ("'Invalid JSON.'", "__('Invalid JSON.', 'viswiz')"),
    ("note: 'JSON import'", "note: __('JSON import', 'viswiz')"),
    ("`Restore revision ${revision}? The current state will remain in history.`", "sprintf(__('Restore revision %d? The current state will remain in history.', 'viswiz'), revision)"),
])

# Generic generated labels in the dataset editor that use dynamic nouns/counts.
regex('assets/viswiz-dataset-editor.js', r"statusText\(`Page \$\{collection\.page\} / \$\{collection\.totalPages\} · \$\{collection\.total\} \$\{noun\}`\)", "statusText(sprintf(__('Page %1$d / %2$d · %3$d %4$s', 'viswiz'), collection.page, collection.totalPages, collection.total, noun))")
regex('assets/viswiz-dataset-editor.js', r"button\(`Add \$\{editor\.noun \|\| 'row'\}`", "button(sprintf(__('Add %s', 'viswiz'), editor.noun || __('row', 'viswiz'))")
regex('assets/viswiz-dataset-editor.js', r"statusText\(`\$\{collection\.total\} \$\{editor\.plural \|\| 'rows'\} · revision \$\{state\.revision\}`\)", "statusText(sprintf(__('%1$d %2$s · revision %3$d', 'viswiz'), collection.total, editor.plural || __('rows', 'viswiz'), state.revision))")
regex('assets/viswiz-dataset-editor.js', r"statusText\(`\$\{nodes\.total\} nodes · \$\{relations\.total\} relations · revision \$\{state\.revision\}`\)", "statusText(sprintf(__('%1$d nodes · %2$d relations · revision %3$d', 'viswiz'), nodes.total, relations.total, state.revision))")

# ---------------------------------------------------------------------------
# Guided import UI.
# ---------------------------------------------------------------------------
ensure_i18n('assets/viswiz-import.js', ['__', '_n', 'sprintf'])
# FIELD_SETS display label (second tuple element) only; aliases remain canonical
# import matching tokens and must not be translated.
text = read('assets/viswiz-import.js')
text = re.sub(r"(\['[^']+', )'([^']+)'(, \[)", lambda m: m.group(1) + "__('" + m.group(2) + "', 'viswiz')" + m.group(3), text)
write('assets/viswiz-import.js', text)
js_replace('assets/viswiz-import.js', [
    ("cfg.i18n?.error || `HTTP ${response.status}`", "__('The import request failed.', 'viswiz') || `HTTP ${response.status}`"),
    ("throw new Error('A quoted field is not closed.');", "throw new Error(__('A quoted field is not closed.', 'viswiz'));"),
    ("throw new Error('The source needs a header row and at least one data row.');", "throw new Error(__('The source needs a header row and at least one data row.', 'viswiz'));"),
    ("throw new Error('Every source column needs a header.');", "throw new Error(__('Every source column needs a header.', 'viswiz'));"),
    ("throw new Error('Source headers must be unique.');", "throw new Error(__('Source headers must be unique.', 'viswiz'));"),
    ("throw new Error(`A single import is limited to ${MAX_RECORDS} records.`);", "throw new Error(sprintf(__('A single import is limited to %d records.', 'viswiz'), MAX_RECORDS));"),
    ("table.innerHTML = '<thead><tr><th>VisWiz field</th><th>Source column</th></tr></thead><tbody></tbody>';", "table.innerHTML = `<thead><tr><th>${__('VisWiz field', 'viswiz')}</th><th>${__('Source column', 'viswiz')}</th></tr></thead><tbody></tbody>`;"),
    ('<option value="">— Ignore —</option>', "<option value=\"\">${__('— Ignore —', 'viswiz')}</option>"),
    ('<h4>Source preview</h4>', "<h4>${__('Source preview', 'viswiz')}</h4>"),
    ("['Source', summary.source_records]", "[__('Source', 'viswiz'), summary.source_records]"),
    ("['Create', summary.created]", "[__('Create', 'viswiz'), summary.created]"),
    ("['Update', summary.updated]", "[__('Update', 'viswiz'), summary.updated]"),
    ("['Remove', summary.removed]", "[__('Remove', 'viswiz'), summary.removed]"),
    ("[['Relations removed', summary.relations_removed]]", "[[__('Relations removed', 'viswiz'), summary.relations_removed]]"),
    ("<p><strong>${result.errors.length} validation error${result.errors.length === 1 ? '' : 's'}</strong></p>", "<p><strong>${sprintf(_n('%d validation error', '%d validation errors', result.errors.length, 'viswiz'), result.errors.length)}</strong></p>"),
    ("`Row ${esc(item.row)} · `", "sprintf(__('Row %s · ', 'viswiz'), esc(item.row))"),
    ('<p><strong>Review before commit</strong></p>', "<p><strong>${__('Review before commit', 'viswiz')}</strong></p>"),
    ('<th>Source row</th><th>Action</th><th>Key</th><th>Item</th>', "<th>${__('Source row', 'viswiz')}</th><th>${__('Action', 'viswiz')}</th><th>${__('Key', 'viswiz')}</th><th>${__('Item', 'viswiz')}</th>"),
    ('<h3>Import CSV / TSV / spreadsheet data</h3>', "<h3>${__('Import CSV / TSV / spreadsheet data', 'viswiz')}</h3>"),
    ('Paste cells directly from a spreadsheet or choose a delimited text file. Nothing is written until the validated preview is committed.', "${__('Paste cells directly from a spreadsheet or choose a delimited text file. Nothing is written until the validated preview is committed.', 'viswiz')}"),
    ('<span>File</span>', "<span>${__('File', 'viswiz')}</span>"),
    ('<span>Encoding</span>', "<span>${__('Encoding', 'viswiz')}</span>"),
    ('<option value="auto">Auto</option>', "<option value=\"auto\">${__('Auto', 'viswiz')}</option>"),
    ('Windows-1253 (Greek)', "${__('Windows-1253 (Greek)', 'viswiz')}"),
    ('<span>Delimiter</span>', "<span>${__('Delimiter', 'viswiz')}</span>"),
    ('<option value="tab">Tab</option>', "<option value=\"tab\">${__('Tab', 'viswiz')}</option>"),
    ('<option value="comma">Comma</option>', "<option value=\"comma\">${__('Comma', 'viswiz')}</option>"),
    ('<option value="semicolon">Semicolon</option>', "<option value=\"semicolon\">${__('Semicolon', 'viswiz')}</option>"),
    ('<option value="pipe">Pipe</option>', "<option value=\"pipe\">${__('Pipe', 'viswiz')}</option>"),
    ('<span>Paste CSV / TSV / spreadsheet cells</span>', "<span>${__('Paste CSV / TSV / spreadsheet cells', 'viswiz')}</span>"),
    ('<span>Graph data</span>', "<span>${__('Graph data', 'viswiz')}</span>"),
    ('<option value="nodes">Nodes</option>', "<option value=\"nodes\">${__('Nodes', 'viswiz')}</option>"),
    ('<option value="relations">Relations</option>', "<option value=\"relations\">${__('Relations', 'viswiz')}</option>"),
    ('<span>Import mode</span>', "<span>${__('Import mode', 'viswiz')}</span>"),
    ('<option value="append">Append — add new items</option>', "<option value=\"append\">${__('Append — add new items', 'viswiz')}</option>"),
    ('<option value="upsert">Upsert — update matching keys, add missing</option>', "<option value=\"upsert\">${__('Upsert — update matching keys, add missing', 'viswiz')}</option>"),
    ('<option value="replace">Replace — replace this item set</option>', "<option value=\"replace\">${__('Replace — replace this item set', 'viswiz')}</option>"),
    ('Append adds records without changing existing ones.', "${__('Append adds records without changing existing ones.', 'viswiz')}"),
    ('>Prepare mapping</button>', ">${__('Prepare mapping', 'viswiz')}</button>"),
    ('>Validate preview</button>', ">${__('Validate preview', 'viswiz')}</button>"),
    ('>Commit import</button>', ">${__('Commit import', 'viswiz')}</button>"),
    ("'<summary>Advanced JSON replacement</summary><p class=\"description\">Use JSON for interchange, backup or recovery. CSV/TSV import is the normal data-entry workflow.</p>'", "`<summary>${__('Advanced JSON replacement', 'viswiz')}</summary><p class=\"description\">${__('Use JSON for interchange, backup or recovery. CSV/TSV import is the normal data-entry workflow.', 'viswiz')}</p>`"),
    ("append: 'Append adds records without changing existing ones.'", "append: __('Append adds records without changing existing ones.', 'viswiz')"),
    ("'Upsert preserves internal UUIDs for matching external keys and adds missing items.'", "__('Upsert preserves internal UUIDs for matching external keys and adds missing items.', 'viswiz')"),
    ("'Upsert matches the mapped row key, updates existing rows and adds missing rows.'", "__('Upsert matches the mapped row key, updates existing rows and adds missing rows.', 'viswiz')"),
    ("'Replace swaps the selected node/relation set. The preview lists any dependent relations that would be removed.'", "__('Replace swaps the selected node/relation set. The preview lists any dependent relations that would be removed.', 'viswiz')"),
    ("'Replace removes the current rows and replaces them with the imported rows.'", "__('Replace removes the current rows and replaces them with the imported rows.', 'viswiz')"),
    ("`Loaded ${file.files[0].name}. Review the text, then prepare mapping.`", "sprintf(__('Loaded %s. Review the text, then prepare mapping.', 'viswiz'), file.files[0].name)"),
    ("error.message || 'Could not read this file.'", "error.message || __('Could not read this file.', 'viswiz')"),
    ("throw new Error('Paste data or choose a file first.');", "throw new Error(__('Paste data or choose a file first.', 'viswiz'));"),
    ("`${state.parsed.records.length} records parsed using ${name} delimiter. Map columns, then validate preview.`", "sprintf(__('%1$d records parsed using %2$s delimiter. Map columns, then validate preview.', 'viswiz'), state.parsed.records.length, name)"),
    ("error.message || 'Could not parse the source data.'", "error.message || __('Could not parse the source data.', 'viswiz')"),
    ("setMessage(root, 'Validating import preview…', 'info');", "setMessage(root, __('Validating import preview…', 'viswiz'), 'info');"),
    ("valid ? 'Preview is valid. Review the summary before committing.' : 'Fix the mapping or source data, then validate again.'", "valid ? __('Preview is valid. Review the summary before committing.', 'viswiz') : __('Fix the mapping or source data, then validate again.', 'viswiz')"),
    ("error.message || 'Could not validate the import.'", "error.message || __('Could not validate the import.', 'viswiz')"),
    ("window.confirm('Commit this import? The current dataset state will remain available in revisions.')", "window.confirm(__('Commit this import? The current dataset state will remain available in revisions.', 'viswiz'))"),
    ("setMessage(root, 'Committing import…', 'info');", "setMessage(root, __('Committing import…', 'viswiz'), 'info');"),
    ("setMessage(root, 'Import committed. Reloading the dataset editor…', 'success');", "setMessage(root, __('Import committed. Reloading the dataset editor…', 'viswiz'), 'success');"),
    ("cfg.i18n?.conflict || error.message", "__('This dataset changed in another editor. Reload before saving.', 'viswiz')"),
    ("message || 'Could not commit the import.'", "message || __('Could not commit the import.', 'viswiz')"),
])

# ---------------------------------------------------------------------------
# Spreadsheet editor.
# ---------------------------------------------------------------------------
ensure_i18n('assets/viswiz-spreadsheet-editor.js', ['__', '_n', 'sprintf'])
js_replace('assets/viswiz-spreadsheet-editor.js', [
    ("cfg.i18n?.error || `HTTP ${response.status}`", "__('The change could not be saved.', 'viswiz') || `HTTP ${response.status}`"),
    ("noun: 'row',", "noun: __('row', 'viswiz'),"),
    ("plural: 'rows',", "plural: __('rows', 'viswiz'),"),
    ("label: 'Label'", "label: __('Label', 'viswiz')"),
    ("label: 'Value'", "label: __('Value', 'viswiz')"),
    ("`${definition.label || path} is required.`", "sprintf(__('%s is required.', 'viswiz'), definition.label || path)"),
    ("`${definition.label || path} must be a number.`", "sprintf(__('%s must be a number.', 'viswiz'), definition.label || path)"),
    ("`${definition.label || path} must be at least ${definition.min}.`", "sprintf(__('%1$s must be at least %2$s.', 'viswiz'), definition.label || path, definition.min)"),
    ("`${definition.label || path} must be at most ${definition.max}.`", "sprintf(__('%1$s must be at most %2$s.', 'viswiz'), definition.label || path, definition.max)"),
    ("`${definition.label || path} must be a valid date/time.`", "sprintf(__('%s must be a valid date/time.', 'viswiz'), definition.label || path)"),
    ("`${definition.label || path} must be a hexadecimal color such as #2563eb.`", "sprintf(__('%s must be a hexadecimal color such as #2563eb.', 'viswiz'), definition.label || path)"),
    ("'Conflict: newer server revision detected'", "__('Conflict: newer server revision detected', 'viswiz')"),
    ("`All changes saved · r${sheet.revision}`", "sprintf(__('All changes saved · r%d', 'viswiz'), sheet.revision)"),
    ("'Save or discard spreadsheet changes before searching.'", "__('Save or discard spreadsheet changes before searching.', 'viswiz')"),
    ("'Save or discard spreadsheet changes first.'", "__('Save or discard spreadsheet changes first.', 'viswiz')"),
    ('>Save changes</button>', ">${__('Save changes', 'viswiz')}</button>"),
    ('>Discard changes</button>', ">${__('Discard changes', 'viswiz')}</button>"),
    ('>Reload server version</button>', ">${__('Reload server version', 'viswiz')}</button>"),
    ('Edit cells directly. Tab / Shift+Tab moves between cells, Enter moves down, and Arrow Up/Down moves between text cells. Paste tab-separated rows from spreadsheet software into any cell. Changes remain local until <strong>Save changes</strong>.', "${__('Edit cells directly. Tab / Shift+Tab moves between cells, Enter moves down, and Arrow Up/Down moves between text cells. Paste tab-separated rows from spreadsheet software into any cell. Changes remain local until Save changes.', 'viswiz')}"),
    ('Save or discard the pending grid changes before searching, changing pages or replacing dataset state from another control.', "${__('Save or discard the pending grid changes before searching, changing pages or replacing dataset state from another control.', 'viswiz')}"),
    ('<th>Row</th>', "<th>${__('Row', 'viswiz')}</th>"),
    ('>Advanced</button>', ">${__('Advanced', 'viswiz')}</button>"),
    ("${pendingDelete ? 'Undo' : 'Remove'}", "${pendingDelete ? __('Undo', 'viswiz') : __('Remove', 'viswiz')}"),
    ("`No ${esc(editor.plural || 'rows')} found. Add a row or paste data to begin.`", "sprintf(__('No %s found. Add a row or paste data to begin.', 'viswiz'), esc(editor.plural || __('rows', 'viswiz')))"),
    ('>Previous</button>', ">${__('Previous', 'viswiz')}</button>"),
    ('>Next</button>', ">${__('Next', 'viswiz')}</button>"),
    ("window.alert(`Paste is limited to ${MAX_BATCH} rows at a time.`);", "window.alert(sprintf(__('Paste is limited to %d rows at a time.', 'viswiz'), MAX_BATCH));"),
    ("issue.message || 'Validation error.'", "issue.message || __('Validation error.', 'viswiz')"),
    ("window.alert(`A single save is limited to ${MAX_BATCH} changed rows.`);", "window.alert(sprintf(__('A single save is limited to %d changed rows.', 'viswiz'), MAX_BATCH));"),
    ('<h2>Advanced row data</h2>', "<h2>${__('Advanced row data', 'viswiz')}</h2>"),
    ('<span>Stable key</span>', "<span>${__('Stable key', 'viswiz')}</span>"),
    ('<span>Additional metadata JSON</span>', "<span>${__('Additional metadata JSON', 'viswiz')}</span>"),
    ('Structured schema fields are edited in the spreadsheet. This JSON contains only additional metadata.', "${__('Structured schema fields are edited in the spreadsheet. This JSON contains only additional metadata.', 'viswiz')}"),
    ('>Cancel</button><button type="submit" class="button button-primary" value="save">Apply</button>', ">${__('Cancel', 'viswiz')}</button><button type=\"submit\" class=\"button button-primary\" value=\"save\">${__('Apply', 'viswiz')}</button>"),
    ("window.alert('Additional metadata JSON is invalid.');", "window.alert(__('Additional metadata JSON is invalid.', 'viswiz'));"),
    ("window.alert('Additional metadata must be a JSON object.');", "window.alert(__('Additional metadata must be a JSON object.', 'viswiz'));"),
    ("sheet.guardMessage = 'Save or discard spreadsheet changes before changing dataset metadata.';", "sheet.guardMessage = __('Save or discard spreadsheet changes before changing dataset metadata.', 'viswiz');"),
])
regex('assets/viswiz-spreadsheet-editor.js', r"`Saving \$\{dirtyCount\(sheet\)\} change\$\{dirtyCount\(sheet\) === 1 \? '' : 's'\}…`", "sprintf(_n('Saving %d change…', 'Saving %d changes…', dirtyCount(sheet), 'viswiz'), dirtyCount(sheet))")
regex('assets/viswiz-spreadsheet-editor.js', r"`\$\{sheet\.errors\.size\} row\$\{sheet\.errors\.size === 1 \? '' : 's'\} need attention`", "sprintf(_n('%d row needs attention', '%d rows need attention', sheet.errors.size, 'viswiz'), sheet.errors.size)")
regex('assets/viswiz-spreadsheet-editor.js', r"`\$\{count\} unsaved change\$\{count === 1 \? '' : 's'\}`", "sprintf(_n('%d unsaved change', '%d unsaved changes', count, 'viswiz'), count)")
regex('assets/viswiz-spreadsheet-editor.js', r"aria-label=\"\$\{esc\(definition\.label \|\| path\)\} row \$\{rowIndex \+ 1\}\"", "aria-label=\"${esc(sprintf(__('%1$s row %2$d', 'viswiz'), definition.label || path, rowIndex + 1))}\"")
regex('assets/viswiz-spreadsheet-editor.js', r"<button type=\"button\" class=\"button\" data-grid-action=\"add\">Add \$\{esc\(editor\.noun \|\| 'row'\)\}</button>", "<button type=\"button\" class=\"button\" data-grid-action=\"add\">${esc(sprintf(__('Add %s', 'viswiz'), editor.noun || __('row', 'viswiz')))}</button>")
regex('assets/viswiz-spreadsheet-editor.js', r"Page \$\{sheet\.page\} / \$\{sheet\.totalPages\} · \$\{sheet\.total\} \$\{esc\(editor\.plural \|\| 'rows'\)\}", "${esc(sprintf(__('Page %1$d / %2$d · %3$d %4$s', 'viswiz'), sheet.page, sheet.totalPages, sheet.total, editor.plural || __('rows', 'viswiz')))}")

# ---------------------------------------------------------------------------
# Legacy/base admin JS still owns visualization source switching and delete
# confirmation; its dormant embedded-payload editor is retained but all authored
# visible copy is routed through gettext as a regression requirement.
# ---------------------------------------------------------------------------
ensure_i18n('assets/viswiz-admin.js', ['__', 'sprintf'])
wrap_builder_literals('assets/viswiz-admin.js')
js_replace('assets/viswiz-admin.js', [
    ("cfg.i18n?.error || `HTTP ${response.status}`", "__('The change could not be saved.', 'viswiz') || `HTTP ${response.status}`"),
    ("window.confirm('Delete this dataset and detach its visualizations?')", "window.confirm(__('Delete this dataset and detach its visualizations?', 'viswiz'))"),
    ("cfg.i18n?.saved || 'Saved.'", "__('Saved.', 'viswiz')"),
    ("cfg.i18n?.conflict || error.message", "__('This dataset changed in another editor. Reload before saving.', 'viswiz')"),
    ("table.innerHTML = '<thead><tr><th>Label</th><th>Value</th><th>X/date</th><th>Y</th><th>Lat</th><th>Lng</th><th></th></tr></thead><tbody></tbody>';", "table.innerHTML = `<thead><tr><th>${__('Label', 'viswiz')}</th><th>${__('Value', 'viswiz')}</th><th>${__('X/date', 'viswiz')}</th><th>${__('Y', 'viswiz')}</th><th>${__('Lat', 'viswiz')}</th><th>${__('Lng', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;"),
    ("row.label || row.row_key || 'Untitled'", "row.label || row.row_key || __('Untitled', 'viswiz')"),
    ("row ? 'Edit row' : 'Add row'", "row ? __('Edit row', 'viswiz') : __('Add row', 'viswiz')"),
    ('>Cancel</button><button type="submit" class="button button-primary">Save row</button>', ">${__('Cancel', 'viswiz')}</button><button type=\"submit\" class=\"button button-primary\">${__('Save row', 'viswiz')}</button>"),
    ("'Metadata JSON is invalid.'", "__('Metadata JSON is invalid.', 'viswiz')"),
    ("headingNodes.textContent = 'Nodes';", "headingNodes.textContent = __('Nodes', 'viswiz');"),
    ("table.innerHTML = '<thead><tr><th>Node</th><th>Type</th><th>Slug</th><th>Degree</th><th></th></tr></thead><tbody></tbody>';", "table.innerHTML = `<thead><tr><th>${__('Node', 'viswiz')}</th><th>${__('Type', 'viswiz')}</th><th>${__('Slug', 'viswiz')}</th><th>${__('Degree', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;"),
    ("headingRelations.textContent='Relations';", "headingRelations.textContent=__('Relations', 'viswiz');"),
    ("rtable.innerHTML='<thead><tr><th>From</th><th>Relation</th><th>To</th><th>Direction</th><th></th></tr></thead><tbody></tbody>';", "rtable.innerHTML=`<thead><tr><th>${__('From', 'viswiz')}</th><th>${__('Relation', 'viswiz')}</th><th>${__('To', 'viswiz')}</th><th>${__('Direction', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;"),
    ("from?.title||from?.slug||'Missing'", "from?.title||from?.slug||__('Missing', 'viswiz')"),
    ("to?.title||to?.slug||'Missing'", "to?.title||to?.slug||__('Missing', 'viswiz')"),
    ("node?'Edit node':'Add node'", "node?__('Edit node', 'viswiz'):__('Add node', 'viswiz')"),
    ('<span>Node type</span>', "<span>${__('Node type', 'viswiz')}</span>"),
    ('>Select type</option>', ">${__('Select type', 'viswiz')}</option>"),
    ('<span>Subtype</span>', "<span>${__('Subtype', 'viswiz')}</span>"),
    ('>Cancel</button><button type="submit" class="button button-primary">Save node</button>', ">${__('Cancel', 'viswiz')}</button><button type=\"submit\" class=\"button button-primary\">${__('Save node', 'viswiz')}</button>"),
    ("title:'Choose featured image'", "title:__('Choose featured image', 'viswiz')"),
    ("title:'Choose node images'", "title:__('Choose node images', 'viswiz')"),
    ("sub.innerHTML='<option value=\"\">No subtype</option>'", "sub.innerHTML=`<option value=\"\">${__('No subtype', 'viswiz')}</option>`"),
    ("'Create at least two nodes first.'", "__('Create at least two nodes first.', 'viswiz')"),
    ("rel?'Edit relation':'Add relation'", "rel?__('Edit relation', 'viswiz'):__('Add relation', 'viswiz')"),
    ('<span>From</span>', "<span>${__('From', 'viswiz')}</span>"),
    ('<span>To</span>', "<span>${__('To', 'viswiz')}</span>"),
    ('<span>Relation type</span>', "<span>${__('Relation type', 'viswiz')}</span>"),
    ('>Unspecified</option>', ">${__('Unspecified', 'viswiz')}</option>"),
    ('<span>Direction</span>', "<span>${__('Direction', 'viswiz')}</span>"),
    ('>Cancel</button><button type="submit" class="button button-primary">Save relation</button>', ">${__('Cancel', 'viswiz')}</button><button type=\"submit\" class=\"button button-primary\">${__('Save relation', 'viswiz')}</button>"),
    ("'Invalid JSON.'", "__('Invalid JSON.', 'viswiz')"),
    ("note:'JSON import'", "note:__('JSON import', 'viswiz')"),
    ("`Restore revision ${revision}? The current state will remain in history.`", "sprintf(__('Restore revision %d? The current state will remain in history.', 'viswiz'), revision)"),
    ("'Graph datasets cannot receive WooCommerce row snapshots.'", "__('Graph datasets cannot receive WooCommerce row snapshots.', 'viswiz')"),
    ("close.setAttribute('aria-label','Close');", "close.setAttribute('aria-label',__('Close', 'viswiz'));"),
    ("cfg.i18n?.confirmDelete||'Delete this item?'", "__('Delete this item?', 'viswiz')"),
])
regex('assets/viswiz-admin.js', r"button\('Previous', 'button button-small'\)", "button(__('Previous', 'viswiz'), 'button button-small')", required=False)
regex('assets/viswiz-admin.js', r"button\('Next', 'button button-small'\)", "button(__('Next', 'viswiz'), 'button button-small')", required=False)

# Normalize the shared legacy admin config fallbacks before the strict architecture scan.
for candidate in ROOT.glob('assets/*.js'):
    javascript = candidate.read_text()
    javascript = javascript.replace("cfg.i18n?.error || `HTTP ${response.status}`", "__('The request failed.', 'viswiz')")
    javascript = javascript.replace("cfg.i18n?.saved || 'Saved.'", "__('Saved.', 'viswiz')")
    javascript = javascript.replace("(cfg.i18n?.conflict || error.message)", "__('This dataset changed in another editor. Reload before saving.', 'viswiz')")
    javascript = javascript.replace("cfg.i18n?.confirmDelete || 'Delete this item?'", "__('Delete this item?', 'viswiz')")
    candidate.write_text(javascript)

# ---------------------------------------------------------------------------
# Remove any remaining legacy translation-map reads from audited JS.
# ---------------------------------------------------------------------------
audited_js = [
    'assets/viswiz-admin.js',
    'assets/viswiz-dataset-editor.js',
    'assets/viswiz-import.js',
    'assets/viswiz-node-public-fields.js',
    'assets/viswiz-node-rich-editor.js',
    'assets/viswiz-renderer-settings.js',
    'assets/viswiz-spreadsheet-editor.js',
    'assets/viswiz-visualization-presets.js',
    'assets/viswiz-visualization-preview.js',
    'assets/viswiz-woo-source-selection.js',
    'assets/viswiz.js',
    'assets/viswiz-graph-runtime.js',
    'assets/viswiz-block.js',
]
for path in audited_js:
    text = read(path)
    for forbidden in ('cfg.i18n', 'previewCfg.i18n', 'VisWizFrontendV2?.i18n', 'const tr = (key, fallback)'):
        if forbidden in text:
            raise RuntimeError(f'legacy i18n pattern remains in {path}: {forbidden}')

# ---------------------------------------------------------------------------
# Preserve the pre-existing Greek public graph labels as real WordPress script
# translation catalogs rather than an in-runtime language sniff/dictionary.
# ---------------------------------------------------------------------------
header = {
    'domain': 'messages',
    'lang': 'el',
    'plural-forms': 'nplurals=2; plural=(n != 1);',
}
frontend_el = {
    '': header,
    'Visualization': ['Οπτικοποίηση'],
    'Search nodes': ['Αναζήτηση nodes'],
    'Filter node type': ['Φίλτρο τύπου node'],
    'All node types': ['Όλοι οι τύποι node'],
    'Filter relation type': ['Φίλτρο τύπου σχέσης'],
    'All relation types': ['Όλοι οι τύποι σχέσης'],
    'Zoom in': ['Μεγέθυνση'],
    'Zoom out': ['Σμίκρυνση'],
    'Reset zoom': ['Επαναφορά μεγέθυνσης'],
    'nodes': ['nodes'],
    'relations': ['σχέσεις'],
    'No matching nodes': ['Δεν βρέθηκαν nodes'],
    'Previous image': ['Προηγούμενη εικόνα'],
    'Next image': ['Επόμενη εικόνα'],
    'Node graph': ['Γράφημα nodes'],
    'View node': ['Προβολή node'],
    'Close': ['Κλείσιμο'],
    'Node': ['Node'],
    'Related nodes': ['Σχετικά nodes'],
    'Relation': ['Σχέση'],
    'No data available.': ['Δεν υπάρχουν διαθέσιμα δεδομένα.'],
    'Full screen': ['Πλήρης οθόνη'],
    'Exit full screen': ['Έξοδος από πλήρη οθόνη'],
    'Could not load visualization.': ['Δεν ήταν δυνατή η φόρτωση της οπτικοποίησης.'],
}
graph_el = {
    '': header,
    'Clear search': ['Καθαρισμός αναζήτησης'],
    'Clear all filters': ['Καθαρισμός όλων των φίλτρων'],
    'Clear filter': ['Καθαρισμός φίλτρου'],
    'Selected filters': ['Επιλεγμένα φίλτρα'],
    'Type': ['Τύπος'],
    'Property': ['Ιδιότητα'],
    'Highlight in graph': ['Επισήμανση στο γράφημα'],
    'Close': ['Κλείσιμο'],
    'Focus on connections': ['Εστίαση στις συνδέσεις'],
    'Connections': ['Συνδέσεις'],
    'Clear focus': ['Καθαρισμός εστίασης'],
    '1 hop': ['1 hop'],
    '2 hops': ['2 hops'],
    'Node': ['Node'],
    'Related nodes': ['Σχετικά nodes'],
    'Relation': ['Σχέση'],
}
for handle, messages, source in [
    ('viswiz-frontend', frontend_el, 'assets/viswiz.js'),
    ('viswiz-graph-runtime', graph_el, 'assets/viswiz-graph-runtime.js'),
]:
    catalog = {
        'translation-revision-date': '2026-09-06 00:00+0000',
        'generator': 'VisWiz localization consolidation',
        'source': source,
        'domain': 'messages',
        'locale_data': {'messages': messages},
    }
    write(f'languages/viswiz-el-{handle}.json', json.dumps(catalog, ensure_ascii=False, separators=(',', ':')) + '\n')

# ---------------------------------------------------------------------------
# Architecture/source-contract coverage.
# ---------------------------------------------------------------------------
test = r'''<?php
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
'''
write('tests/JavaScriptLocalizationTest.php', test)

doc = '''# JavaScript localization contract\n\nVisWiz user-visible JavaScript copy uses the WordPress `wp.i18n` API with the `viswiz` text domain. Static UI strings live at their call sites as gettext msgids (`__`, `_n`, `sprintf`) rather than in PHP-to-JavaScript translation maps or runtime language dictionaries.\n\n## Rules\n\n- Scripts that author user-visible copy declare `wp-i18n` and call `wp_set_script_translations()` after registration/enqueue.\n- PHP-localized globals carry runtime data/configuration only; they do not carry static translation tables.\n- REST/API error messages remain server-owned. JavaScript translates only its local fallback message.\n- Registry/schema labels, server data, user content, enum/storage keys and import header aliases are data/contracts, not JavaScript msgids.\n- Plural or interpolated UI copy uses `_n()`/`sprintf()` rather than English suffix construction.\n- The public graph runtime no longer performs locale sniffing or maintains a Greek/English dictionary. Existing Greek public graph labels are retained as normal WordPress Jed JSON catalogs in `languages/`.\n\n## Audited surfaces\n\nThe consolidation covers the base admin UI, server-aware dataset editor, spreadsheet editor, guided import, node public fields/rich editor adapters, renderer settings, visualization preview/presets, WooCommerce source selection, public renderer, graph enhancement runtime and Gutenberg block editor.\n\n`tests/JavaScriptLocalizationTest.php` protects the architecture by rejecting the former `cfg.i18n`/`VisWizFrontendV2.i18n`/`tr()` patterns and verifying script translation registration plus the Greek public catalogs.\n'''
write('docs/JAVASCRIPT_LOCALIZATION.md', doc)

# Print candidate human-readable literals still present outside gettext calls.
# This is intentionally advisory: selectors, enum values and data-contract keys
# are expected to remain literal and are reviewed from the workflow log/diff.
print('\n=== Remaining human-readable literal candidates ===')
for path in audited_js:
    text = read(path)
    candidates = []
    for match in re.finditer(r"(?<![A-Za-z0-9_])(['\"])([^\n'\"]*[A-Za-z][^\n'\"]*\s+[^\n'\"]*)\1", text):
        value = match.group(2)
        prefix = text[max(0, match.start()-30):match.start()]
        if "__(" in prefix or "_n(" in prefix or value.startswith(('.', '#', '[', 'data-', 'viswiz-', '/')):
            continue
        if any(token in value for token in ('class=', 'data-', 'aria-', 'application/json', 'same-origin', 'Content-Type', 'X-WP-', 'X-VisWiz-', 'button button', 'notice notice', 'viswiz-')):
            continue
        candidates.append(value)
    if candidates:
        print(f'-- {path}')
        for value in sorted(set(candidates))[:120]:
            print('  ', value)

print('\nLocalization codemod completed.')
