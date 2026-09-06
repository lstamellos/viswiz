(() => {
  'use strict';
  const { __, _n, sprintf } = window.wp.i18n;

  const cfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c]));
  const MAX_RECORDS = 20000;

  const FIELD_SETS = {
    rows: [
      ['row_key', __('Row key', 'viswiz'), ['row_key', 'row key', 'key', 'id', 'external_key', 'external key']],
      ['label', __('Label', 'viswiz'), ['label', 'name', 'title']],
      ['value', __('Value', 'viswiz'), ['value', 'amount', 'count']],
      ['x_value', __('X / date', 'viswiz'), ['x_value', 'x value', 'date', 'time', 'x']],
      ['x_numeric', __('X numeric', 'viswiz'), ['x_numeric', 'x numeric']],
      ['y_value', __('Y', 'viswiz'), ['y_value', 'y value', 'y']],
      ['latitude', __('Latitude', 'viswiz'), ['latitude', 'lat']],
      ['longitude', __('Longitude', 'viswiz'), ['longitude', 'lng', 'lon', 'long']],
      ['color', __('Color', 'viswiz'), ['color', 'colour']],
      ['meta', __('Metadata JSON', 'viswiz'), ['meta', 'metadata', 'metadata_json', 'metadata json']],
    ],
    nodes: [
      ['external_key', __('External key', 'viswiz'), ['external_key', 'external key', 'node_key', 'node key', 'key', 'id']],
      ['slug', __('Slug', 'viswiz'), ['slug']],
      ['title', __('Title', 'viswiz'), ['title', 'name']],
      ['label', __('Label', 'viswiz'), ['label']],
      ['node_type', __('Node type', 'viswiz'), ['node_type', 'node type', 'type']],
      ['node_subtype', __('Node subtype', 'viswiz'), ['node_subtype', 'node subtype', 'subtype']],
      ['description', __('Description', 'viswiz'), ['description', 'description_html', 'description html']],
      ['main_image_id', __('Main image ID', 'viswiz'), ['main_image_id', 'main image id', 'featured_image_id']],
      ['other_image_ids', __('Other image IDs', 'viswiz'), ['other_image_ids', 'other image ids', 'gallery_ids']],
      ['meta', __('Metadata JSON', 'viswiz'), ['meta', 'metadata', 'metadata_json', 'metadata json']],
    ],
    relations: [
      ['external_key', __('External key', 'viswiz'), ['external_key', 'external key', 'relation_key', 'relation key', 'key', 'id']],
      ['from_key', __('From node key', 'viswiz'), ['from_key', 'from key', 'from', 'source', 'source_key', 'source key']],
      ['to_key', __('To node key', 'viswiz'), ['to_key', 'to key', 'to', 'target', 'target_key', 'target key']],
      ['relation_type', __('Relation type', 'viswiz'), ['relation_type', 'relation type', 'type']],
      ['label', __('Label', 'viswiz'), ['label']],
      ['inverse_label', __('Inverse label', 'viswiz'), ['inverse_label', 'inverse label']],
      ['direction', __('Direction', 'viswiz'), ['direction']],
      ['intensity', __('Intensity', 'viswiz'), ['intensity', 'weight']],
      ['meta', __('Metadata JSON', 'viswiz'), ['meta', 'metadata', 'metadata_json', 'metadata json']],
    ],
  };

  async function api(path, options = {}) {
    const response = await fetch(`${cfg.restUrl}${path}`, {
      credentials: 'same-origin',
      method: options.method || 'POST',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: JSON.stringify(options.body || {}),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.code) {
      const error = new Error(data?.message || __('The import request failed.', 'viswiz') || `HTTP ${response.status}`);
      error.code = data?.code || '';
      error.data = data?.data || {};
      throw error;
    }
    return data;
  }

  function normalizeHeader(value) {
    return String(value || '').trim().toLowerCase().replace(/[-_.]+/g, ' ').replace(/\s+/g, ' ');
  }

  function parseDelimited(text, delimiter) {
    const rows = [];
    let row = [];
    let cell = '';
    let quoted = false;
    const source = String(text || '').replace(/^\uFEFF/, '');
    for (let i = 0; i < source.length; i += 1) {
      const char = source[i];
      if (quoted) {
        if (char === '"') {
          if (source[i + 1] === '"') {
            cell += '"';
            i += 1;
          } else {
            quoted = false;
          }
        } else {
          cell += char;
        }
        continue;
      }
      if (char === '"' && cell === '') {
        quoted = true;
      } else if (char === delimiter) {
        row.push(cell);
        cell = '';
      } else if (char === '\n' || char === '\r') {
        if (char === '\r' && source[i + 1] === '\n') i += 1;
        row.push(cell);
        rows.push(row);
        row = [];
        cell = '';
      } else {
        cell += char;
      }
    }
    if (quoted) throw new Error(__('A quoted field is not closed.', 'viswiz'));
    if (cell !== '' || row.length) {
      row.push(cell);
      rows.push(row);
    }
    return rows.filter((values) => values.some((value) => String(value).trim() !== ''));
  }

  function detectDelimiter(text) {
    const candidates = ['\t', ',', ';', '|'];
    let best = { delimiter: '\t', score: -1 };
    candidates.forEach((delimiter) => {
      try {
        const rows = parseDelimited(text, delimiter).slice(0, 12);
        if (!rows.length) return;
        const counts = new Map();
        rows.forEach((row) => counts.set(row.length, (counts.get(row.length) || 0) + 1));
        const [width, frequency] = [...counts.entries()].sort((a, b) => b[1] - a[1] || b[0] - a[0])[0];
        const score = width > 1 ? frequency * 100 + width : 0;
        if (score > best.score) best = { delimiter, score };
      } catch (_) {}
    });
    return best.delimiter;
  }

  function rowsToRecords(rows) {
    if (rows.length < 2) throw new Error(__('The source needs a header row and at least one data row.', 'viswiz'));
    const headers = rows[0].map((header) => String(header).trim());
    if (headers.some((header) => !header)) throw new Error(__('Every source column needs a header.', 'viswiz'));
    const normalized = headers.map(normalizeHeader);
    if (new Set(normalized).size !== normalized.length) throw new Error(__('Source headers must be unique.', 'viswiz'));
    const records = rows.slice(1).map((values) => Object.fromEntries(headers.map((header, index) => [header, values[index] ?? ''])));
    if (records.length > MAX_RECORDS) throw new Error(sprintf(__('A single import is limited to %d records.', 'viswiz'), MAX_RECORDS));
    return { headers, records };
  }

  function delimiterValue(select) {
    if (select.value === 'tab') return '\t';
    if (select.value === 'comma') return ',';
    if (select.value === 'semicolon') return ';';
    if (select.value === 'pipe') return '|';
    return '';
  }

  async function decodeFile(file, requestedEncoding) {
    const buffer = await file.arrayBuffer();
    const bytes = new Uint8Array(buffer);
    let encoding = requestedEncoding;
    if (encoding === 'auto') {
      if (bytes[0] === 0xFF && bytes[1] === 0xFE) encoding = 'utf-16le';
      else if (bytes[0] === 0xFE && bytes[1] === 0xFF) encoding = 'utf-16be';
      else {
        try {
          new TextDecoder('utf-8', { fatal: true }).decode(buffer);
          encoding = 'utf-8';
        } catch (_) {
          encoding = 'windows-1253';
        }
      }
    }
    return new TextDecoder(encoding).decode(buffer).replace(/^\uFEFF/, '');
  }

  function setMessage(root, message, kind = 'info') {
    const box = $('[data-viswiz-import-message]', root);
    box.className = `viswiz-import-message is-${kind}`;
    box.textContent = message;
    box.hidden = false;
  }

  function clearMessage(root) {
    const box = $('[data-viswiz-import-message]', root);
    box.hidden = true;
    box.textContent = '';
  }

  function currentKind(root, schema) {
    if (schema !== 'graph') return 'rows';
    return $('[data-viswiz-import-kind]', root).value;
  }

  function mappingFields(kind) {
    return FIELD_SETS[kind] || FIELD_SETS.rows;
  }

  function autoMapping(headers, kind) {
    const used = new Set();
    const result = {};
    mappingFields(kind).forEach(([target, , aliases]) => {
      const normalizedAliases = aliases.map(normalizeHeader);
      const header = headers.find((candidate) => !used.has(candidate) && normalizedAliases.includes(normalizeHeader(candidate)));
      if (header) {
        result[target] = header;
        used.add(header);
      }
    });
    return result;
  }

  function renderMapping(root, parsed, kind) {
    const host = $('[data-viswiz-import-mapping]', root);
    host.replaceChildren();
    const auto = autoMapping(parsed.headers, kind);
    const table = document.createElement('table');
    table.className = 'widefat striped viswiz-import-mapping-table';
    table.innerHTML = `<thead><tr><th>${__('VisWiz field', 'viswiz')}</th><th>${__('Source column', 'viswiz')}</th></tr></thead><tbody></tbody>`;
    const tbody = $('tbody', table);
    mappingFields(kind).forEach(([target, label]) => {
      const tr = document.createElement('tr');
      const tdLabel = document.createElement('td');
      tdLabel.textContent = label;
      const tdSelect = document.createElement('td');
      const select = document.createElement('select');
      select.dataset.viswizImportMap = target;
      select.innerHTML = `<option value="">${__('— Ignore —', 'viswiz')}</option>${parsed.headers.map((header) => `<option value="${esc(header)}">${esc(header)}</option>`).join('')}`;
      select.value = auto[target] || '';
      tdSelect.appendChild(select);
      tr.append(tdLabel, tdSelect);
      tbody.appendChild(tr);
    });
    host.appendChild(table);

    const sample = document.createElement('div');
    sample.className = 'viswiz-import-source-preview';
    sample.innerHTML = `<h4>${__('Source preview', 'viswiz')}</h4><div class="viswiz-import-table-scroll"><table class="widefat striped"><thead><tr>${parsed.headers.map((header) => `<th>${esc(header)}</th>`).join('')}</tr></thead><tbody>${parsed.records.slice(0, 5).map((record) => `<tr>${parsed.headers.map((header) => `<td>${esc(record[header])}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`;
    host.appendChild(sample);
    host.hidden = false;
  }

  function collectMapping(root) {
    const mapping = {};
    $$('[data-viswiz-import-map]', root).forEach((select) => {
      if (select.value) mapping[select.dataset.viswizImportMap] = select.value;
    });
    return mapping;
  }

  function importRequest(root, state) {
    return {
      kind: currentKind(root, state.schema),
      mode: $('[data-viswiz-import-mode]', root).value,
      mapping: collectMapping(root),
      records: state.parsed.records,
    };
  }

  function renderPreview(root, result) {
    const host = $('[data-viswiz-import-preview]', root);
    host.replaceChildren();
    host.hidden = false;
    const summary = result.summary || {};
    const chips = document.createElement('div');
    chips.className = 'viswiz-import-summary';
    [
      [__('Source', 'viswiz'), summary.source_records],
      [__('Create', 'viswiz'), summary.created],
      [__('Update', 'viswiz'), summary.updated],
      [__('Remove', 'viswiz'), summary.removed],
      ...(summary.relations_removed ? [[__('Relations removed', 'viswiz'), summary.relations_removed]] : []),
    ].forEach(([label, value]) => {
      const chip = document.createElement('span');
      chip.innerHTML = `<strong>${esc(value ?? 0)}</strong> ${esc(label)}`;
      chips.appendChild(chip);
    });
    host.appendChild(chips);

    if (result.errors?.length) {
      const errors = document.createElement('div');
      errors.className = 'notice notice-error inline viswiz-import-issues';
      errors.innerHTML = `<p><strong>${sprintf(_n('%d validation error', '%d validation errors', result.errors.length, 'viswiz'), result.errors.length)}</strong></p><ul>${result.errors.slice(0, 100).map((item) => `<li>${item.row ? sprintf(__('Row %s · ', 'viswiz'), esc(item.row)) : ''}${item.field ? `${esc(item.field)}: ` : ''}${esc(item.message)}</li>`).join('')}</ul>`;
      host.appendChild(errors);
    }
    if (result.warnings?.length) {
      const warnings = document.createElement('div');
      warnings.className = 'notice notice-warning inline viswiz-import-issues';
      warnings.innerHTML = `<p><strong>${__('Review before commit', 'viswiz')}</strong></p><ul>${result.warnings.map((message) => `<li>${esc(message)}</li>`).join('')}</ul>`;
      host.appendChild(warnings);
    }
    if (result.preview?.length) {
      const table = document.createElement('table');
      table.className = 'widefat striped viswiz-import-result-table';
      table.innerHTML = `<thead><tr><th>${__('Source row', 'viswiz')}</th><th>${__('Action', 'viswiz')}</th><th>${__('Key', 'viswiz')}</th><th>${__('Item', 'viswiz')}</th></tr></thead><tbody>${result.preview.map((item) => `<tr><td>${esc(item.source_row)}</td><td><span class="viswiz-import-action is-${esc(item.action)}">${esc(item.action)}</span></td><td><code>${esc(item.key)}</code></td><td>${esc(item.label)}</td></tr>`).join('')}</tbody>`;
      host.appendChild(table);
    }
  }

  function makeUi(schema) {
    const root = document.createElement('div');
    root.className = 'viswiz-guided-import';
    root.dataset.viswizGuidedImport = '1';
    root.innerHTML = `
      <h3>${__('Import CSV / TSV / spreadsheet data', 'viswiz')}</h3>
      <p class="description">${__('Paste cells directly from a spreadsheet or choose a delimited text file. Nothing is written until the validated preview is committed.', 'viswiz')}</p>
      <div class="viswiz-import-source-grid">
        <label class="viswiz-field"><span>${__('File', 'viswiz')}</span><input type="file" accept=".csv,.tsv,.txt,text/csv,text/tab-separated-values,text/plain" data-viswiz-import-file></label>
        <label class="viswiz-field"><span>${__('Encoding', 'viswiz')}</span><select data-viswiz-import-encoding><option value="auto">${__('Auto', 'viswiz')}</option><option value="utf-8">UTF-8</option><option value="windows-1253">${__('Windows-1253 (Greek)', 'viswiz')}</option><option value="windows-1252">Windows-1252</option><option value="utf-16le">UTF-16 LE</option></select></label>
        <label class="viswiz-field"><span>${__('Delimiter', 'viswiz')}</span><select data-viswiz-import-delimiter><option value="auto">${__('Auto', 'viswiz')}</option><option value="tab">${__('Tab', 'viswiz')}</option><option value="comma">${__('Comma', 'viswiz')}</option><option value="semicolon">${__('Semicolon', 'viswiz')}</option><option value="pipe">${__('Pipe', 'viswiz')}</option></select></label>
      </div>
      <label class="viswiz-field"><span>${__('Paste CSV / TSV / spreadsheet cells', 'viswiz')}</span><textarea rows="8" data-viswiz-import-source placeholder="row_key&#9;label&#9;value&#10;alpha&#9;Alpha&#9;10"></textarea></label>
      <div class="viswiz-import-options">
        ${schema === 'graph' ? '<label class="viswiz-field"><span>${__('Graph data', 'viswiz')}</span><select data-viswiz-import-kind><option value="nodes">${__('Nodes', 'viswiz')}</option><option value="relations">${__('Relations', 'viswiz')}</option></select></label>' : ''}
        <label class="viswiz-field"><span>${__('Import mode', 'viswiz')}</span><select data-viswiz-import-mode><option value="append">${__('Append — add new items', 'viswiz')}</option><option value="upsert">${__('Upsert — update matching keys, add missing', 'viswiz')}</option><option value="replace">${__('Replace — replace this item set', 'viswiz')}</option></select></label>
      </div>
      <p class="description" data-viswiz-import-mode-help>${__('Append adds records without changing existing ones.', 'viswiz')}</p>
      <div class="viswiz-import-actions"><button type="button" class="button" data-viswiz-import-prepare>${__('Prepare mapping', 'viswiz')}</button><button type="button" class="button button-primary" data-viswiz-import-preview-button disabled>${__('Validate preview', 'viswiz')}</button></div>
      <div class="viswiz-import-message" data-viswiz-import-message hidden></div>
      <div data-viswiz-import-mapping hidden></div>
      <div data-viswiz-import-preview hidden></div>
      <div class="viswiz-import-commit" hidden data-viswiz-import-commit-row><button type="button" class="button button-primary" data-viswiz-import-commit>${__('Commit import', 'viswiz')}</button></div>`;
    return root;
  }

  function advancedJson(rawTextarea, rawButton) {
    const label = rawTextarea.closest('label');
    if (!label || !rawButton) return;
    const details = document.createElement('details');
    details.className = 'viswiz-import-json-advanced';
    details.innerHTML = `<summary>${__('Advanced JSON replacement', 'viswiz')}</summary><p class="description">${__('Use JSON for interchange, backup or recovery. CSV/TSV import is the normal data-entry workflow.', 'viswiz')}</p>`;
    label.parentNode.insertBefore(details, label);
    details.append(label, rawButton);
  }

  function init() {
    const editor = $('#viswiz-dataset-editor');
    const rawTextarea = $('[data-viswiz-import-json]');
    const rawButton = $('[data-viswiz-import-button]');
    if (!editor || !rawTextarea || !rawButton || !cfg.restUrl) return;
    const card = rawTextarea.closest('.viswiz-card');
    if (!card || card.querySelector('[data-viswiz-guided-import]')) return;

    const state = {
      id: Number(editor.dataset.datasetId || 0),
      schema: editor.dataset.schema || 'categorical',
      parsed: null,
      preview: null,
    };
    const root = makeUi(state.schema);
    const rawLabel = rawTextarea.closest('label');
    card.insertBefore(root, rawLabel || rawTextarea);
    advancedJson(rawTextarea, rawButton);

    const file = $('[data-viswiz-import-file]', root);
    const encoding = $('[data-viswiz-import-encoding]', root);
    const delimiter = $('[data-viswiz-import-delimiter]', root);
    const source = $('[data-viswiz-import-source]', root);
    const kind = $('[data-viswiz-import-kind]', root);
    const mode = $('[data-viswiz-import-mode]', root);
    const prepare = $('[data-viswiz-import-prepare]', root);
    const previewButton = $('[data-viswiz-import-preview-button]', root);
    const commit = $('[data-viswiz-import-commit]', root);
    const commitRow = $('[data-viswiz-import-commit-row]', root);
    const modeHelp = $('[data-viswiz-import-mode-help]', root);

    const resetPrepared = () => {
      state.parsed = null;
      state.preview = null;
      $('[data-viswiz-import-mapping]', root).hidden = true;
      $('[data-viswiz-import-preview]', root).hidden = true;
      previewButton.disabled = true;
      commitRow.hidden = true;
      clearMessage(root);
    };

    source.addEventListener('input', resetPrepared);
    delimiter.addEventListener('change', resetPrepared);
    encoding.addEventListener('change', () => { if (file.files?.[0]) resetPrepared(); });
    kind?.addEventListener('change', resetPrepared);
    mode.addEventListener('change', () => {
      const help = {
        append: '${__('Append adds records without changing existing ones.', 'viswiz')}',
        upsert: state.schema === 'graph' ? __('Upsert preserves internal UUIDs for matching external keys and adds missing items.', 'viswiz') : __('Upsert matches the mapped row key, updates existing rows and adds missing rows.', 'viswiz'),
        replace: state.schema === 'graph' ? __('Replace swaps the selected node/relation set. The preview lists any dependent relations that would be removed.', 'viswiz') : __('Replace removes the current rows and replaces them with the imported rows.', 'viswiz'),
      };
      modeHelp.textContent = help[mode.value];
      state.preview = null;
      $('[data-viswiz-import-preview]', root).hidden = true;
      commitRow.hidden = true;
    });

    file.addEventListener('change', async () => {
      if (!file.files?.[0]) return;
      try {
        source.value = await decodeFile(file.files[0], encoding.value);
        resetPrepared();
        setMessage(root, sprintf(__('Loaded %s. Review the text, then prepare mapping.', 'viswiz'), file.files[0].name), 'success');
      } catch (error) {
        setMessage(root, error.message || __('Could not read this file.', 'viswiz'), 'error');
      }
    });

    prepare.addEventListener('click', () => {
      clearMessage(root);
      try {
        if (!source.value.trim()) throw new Error(__('Paste data or choose a file first.', 'viswiz'));
        const chosen = delimiterValue(delimiter) || detectDelimiter(source.value);
        const rows = parseDelimited(source.value, chosen);
        state.parsed = rowsToRecords(rows);
        const kindValue = currentKind(root, state.schema);
        renderMapping(root, state.parsed, kindValue);
        previewButton.disabled = false;
        commitRow.hidden = true;
        $('[data-viswiz-import-preview]', root).hidden = true;
        const name = chosen === '\t' ? 'tab' : chosen === ',' ? 'comma' : chosen === ';' ? 'semicolon' : 'pipe';
        setMessage(root, sprintf(__('%1$d records parsed using %2$s delimiter. Map columns, then validate preview.', 'viswiz'), state.parsed.records.length, name), 'success');
      } catch (error) {
        state.parsed = null;
        previewButton.disabled = true;
        setMessage(root, error.message || __('Could not parse the source data.', 'viswiz'), 'error');
      }
    });

    previewButton.addEventListener('click', async () => {
      if (!state.parsed) return;
      previewButton.disabled = true;
      commitRow.hidden = true;
      setMessage(root, __('Validating import preview…', 'viswiz'), 'info');
      try {
        const request = importRequest(root, state);
        const result = await api(`/datasets/${state.id}/import/preview`, { body: request });
        state.preview = result;
        renderPreview(root, result);
        const valid = !result.errors?.length;
        commitRow.hidden = !valid;
        setMessage(root, valid ? __('Preview is valid. Review the summary before committing.', 'viswiz') : __('Fix the mapping or source data, then validate again.', 'viswiz'), valid ? 'success' : 'error');
      } catch (error) {
        setMessage(root, error.message || __('Could not validate the import.', 'viswiz'), 'error');
      } finally {
        previewButton.disabled = false;
      }
    });

    commit.addEventListener('click', async () => {
      if (!state.parsed || !state.preview || state.preview.errors?.length) return;
      const summary = state.preview.summary || {};
      const destructive = mode.value === 'replace' || Number(summary.removed || 0) > 0 || Number(summary.relations_removed || 0) > 0;
      if (destructive && !window.confirm(__('Commit this import? The current dataset state will remain available in revisions.', 'viswiz'))) return;
      commit.disabled = true;
      setMessage(root, __('Committing import…', 'viswiz'), 'info');
      try {
        const request = importRequest(root, state);
        request.expected_revision = Number(editor.dataset.revision || 0);
        await api(`/datasets/${state.id}/import`, { body: request });
        setMessage(root, __('Import committed. Reloading the dataset editor…', 'viswiz'), 'success');
        window.location.reload();
      } catch (error) {
        const message = error.code === 'viswiz_revision_conflict' ? (__('This dataset changed in another editor. Reload before saving.', 'viswiz')) : error.message;
        setMessage(root, message || __('Could not commit the import.', 'viswiz'), 'error');
        commit.disabled = false;
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
