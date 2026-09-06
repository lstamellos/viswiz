(() => {
  'use strict';
  const { __, _n, sprintf } = window.wp.i18n;

  const cfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c]));
  const uuid = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
  const PAGE_SIZE = 100;
  const MAX_BATCH = 500;
  const SIDE_MUTATION_SELECTORS = [
    '[data-viswiz-import-button]',
    '[data-viswiz-commerce-snapshot]',
    '[data-viswiz-restore-revision]',
  ];

  async function request(path, options = {}) {
    const response = await fetch(`${cfg.restUrl}${path}`, {
      credentials: 'same-origin',
      method: options.method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.code) {
      const error = new Error(data?.message || __('The change could not be saved.', 'viswiz') || `HTTP ${response.status}`);
      error.code = data?.code || '';
      error.data = data?.data || {};
      throw error;
    }
    return { data, response };
  }

  function queryString(params) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
    });
    return query.toString();
  }

  function schemaEditor(sheet) {
    const editor = cfg.schemas?.[sheet.schema]?.editor;
    if (editor && Array.isArray(editor.fields) && editor.fields.length) return editor;
    return {
      noun: __('row', 'viswiz'),
      plural: __('rows', 'viswiz'),
      fields: [
        { path: 'label', label: __('Label', 'viswiz'), type: 'text', table: true, required: true },
        { path: 'value', label: __('Value', 'viswiz'), type: 'number', table: true, required: true, step: 'any' },
      ],
    };
  }

  function pathValue(source, path) {
    return String(path || '').split('.').reduce((value, key) => (value && typeof value === 'object' ? value[key] : undefined), source);
  }

  function setPath(target, path, value) {
    const parts = String(path || '').split('.').filter(Boolean);
    if (!parts.length) return;
    let cursor = target;
    parts.forEach((part, index) => {
      if (index === parts.length - 1) cursor[part] = value;
      else {
        if (!cursor[part] || typeof cursor[part] !== 'object') cursor[part] = {};
        cursor = cursor[part];
      }
    });
  }

  function cloneRow(row) {
    return JSON.parse(JSON.stringify(row || {}));
  }

  function emptyRow() {
    const id = uuid();
    return {
      uuid: id,
      row_key: `manual-${id.replace(/-/g, '').slice(0, 12)}`,
      label: '',
      value: null,
      x_value: '',
      x_numeric: null,
      y_value: null,
      latitude: null,
      longitude: null,
      color: '',
      meta: {},
    };
  }

  function dateTimeInputValue(value) {
    let text = String(value || '').trim();
    if (/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}/.test(text)) text = text.replace(/\s+/, 'T');
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text)) return text.slice(0, 16);
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return `${text}T00:00`;
    return text;
  }

  function tableFields(sheet) {
    return schemaEditor(sheet).fields.filter((definition) => definition.table !== false);
  }

  function knownMetaKeys(editor) {
    return editor.fields
      .map((definition) => String(definition.path || ''))
      .filter((path) => path.startsWith('meta.') && !path.slice(5).includes('.'))
      .map((path) => path.slice(5));
  }

  function additionalMeta(row, editor) {
    const meta = row?.meta && typeof row.meta === 'object' ? { ...row.meta } : {};
    knownMetaKeys(editor).forEach((key) => delete meta[key]);
    return meta;
  }

  function mergeAdditionalMeta(row, editor, advanced) {
    const structured = {};
    const current = row?.meta && typeof row.meta === 'object' ? row.meta : {};
    knownMetaKeys(editor).forEach((key) => {
      if (Object.prototype.hasOwnProperty.call(current, key)) structured[key] = current[key];
    });
    row.meta = { ...advanced, ...structured };
  }

  function dirtyUuids(sheet) {
    return new Set([...sheet.drafts.keys(), ...sheet.deletes]);
  }

  function dirtyCount(sheet) {
    return dirtyUuids(sheet).size;
  }

  function isDirty(sheet) {
    return dirtyCount(sheet) > 0;
  }

  function baseRow(sheet, rowUuid) {
    return sheet.items.find((row) => row.uuid === rowUuid) || null;
  }

  function draftEntry(sheet, rowUuid) {
    return sheet.drafts.get(rowUuid) || null;
  }

  function currentRow(sheet, rowUuid) {
    return draftEntry(sheet, rowUuid)?.row || baseRow(sheet, rowUuid);
  }

  function ensureDraft(sheet, rowUuid) {
    let entry = draftEntry(sheet, rowUuid);
    if (entry) return entry;
    const base = baseRow(sheet, rowUuid);
    if (!base) return null;
    entry = { row: cloneRow(base), isNew: false };
    sheet.drafts.set(rowUuid, entry);
    return entry;
  }

  function createDraft(sheet) {
    const row = emptyRow();
    const entry = { row, isNew: true };
    sheet.drafts.set(row.uuid, entry);
    return entry;
  }

  function displayedRows(sheet) {
    const baseIds = new Set(sheet.items.map((row) => row.uuid));
    const rows = sheet.items.map((row) => draftEntry(sheet, row.uuid)?.row || row);
    sheet.drafts.forEach((entry, rowUuid) => {
      if (entry.isNew && !baseIds.has(rowUuid)) rows.push(entry.row);
    });
    return rows;
  }

  function fieldValueForInput(definition, row) {
    let value = pathValue(row, definition.path);
    if (value === null || value === undefined) value = '';
    if (definition.type === 'datetime-local') value = dateTimeInputValue(value);
    return String(value);
  }

  function fieldAttributes(definition) {
    const attrs = [];
    if (definition.required) attrs.push('required');
    if (definition.step !== undefined) attrs.push(`step="${esc(definition.step)}"`);
    if (definition.min !== undefined) attrs.push(`min="${esc(definition.min)}"`);
    if (definition.max !== undefined) attrs.push(`max="${esc(definition.max)}"`);
    if (definition.placeholder) attrs.push(`placeholder="${esc(definition.placeholder)}"`);
    return attrs.join(' ');
  }

  function gridInput(definition, row, rowIndex, colIndex, disabled) {
    const path = String(definition.path || '');
    const value = fieldValueForInput(definition, row);
    const type = definition.type === 'color' ? 'text' : (definition.type || 'text');
    const common = `class="viswiz-grid-input" data-grid-row="${esc(row.uuid)}" data-grid-index="${rowIndex}" data-grid-col="${colIndex}" data-field-path="${esc(path)}" aria-label="${esc(sprintf(__('%1$s row %2$d', 'viswiz'), definition.label || path, rowIndex + 1))}" ${fieldAttributes(definition)} ${disabled ? 'disabled' : ''}`;
    if (definition.type === 'textarea') {
      return `<textarea ${common} rows="2">${esc(value)}</textarea>`;
    }
    return `<input ${common} type="${esc(type)}" value="${esc(value)}" ${definition.type === 'color' ? 'placeholder="#2563eb"' : ''}>`;
  }

  function validateRow(sheet, row) {
    const errors = {};
    schemaEditor(sheet).fields.forEach((definition) => {
      const path = String(definition.path || '');
      const value = pathValue(row, path);
      const blank = value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
      if (definition.required && blank) {
        errors[path] = sprintf(__('%s is required.', 'viswiz'), definition.label || path);
        return;
      }
      if (blank) return;
      if (definition.type === 'number') {
        const number = Number(value);
        if (!Number.isFinite(number)) {
          errors[path] = sprintf(__('%s must be a number.', 'viswiz'), definition.label || path);
          return;
        }
        if (definition.min !== undefined && number < Number(definition.min)) errors[path] = sprintf(__('%1$s must be at least %2$s.', 'viswiz'), definition.label || path, definition.min);
        if (definition.max !== undefined && number > Number(definition.max)) errors[path] = sprintf(__('%1$s must be at most %2$s.', 'viswiz'), definition.label || path, definition.max);
      }
      if (definition.type === 'datetime-local' && Number.isNaN(Date.parse(String(value)))) {
        errors[path] = sprintf(__('%s must be a valid date/time.', 'viswiz'), definition.label || path);
      }
      if (definition.type === 'color' && String(value).trim() && !/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(value).trim())) {
        errors[path] = sprintf(__('%s must be a hexadecimal color such as #2563eb.', 'viswiz'), definition.label || path);
      }
    });
    return errors;
  }

  function setRowErrors(sheet, rowUuid, errors) {
    if (errors && Object.keys(errors).length) sheet.errors.set(rowUuid, errors);
    else sheet.errors.delete(rowUuid);
  }

  function validateDrafts(sheet) {
    sheet.errors.clear();
    sheet.drafts.forEach((entry, rowUuid) => {
      if (!sheet.deletes.has(rowUuid)) setRowErrors(sheet, rowUuid, validateRow(sheet, entry.row));
    });
    return sheet.errors.size === 0;
  }

  function updateRevision(sheet, result) {
    if (!result?.revision) return;
    sheet.revision = Number(result.revision);
    sheet.root.dataset.revision = String(sheet.revision);
    if (sheet.baseState) sheet.baseState.revision = sheet.revision;
    const heading = document.querySelector('.viswiz-admin-wrap h1 small');
    if (heading) heading.textContent = `r${sheet.revision}`;
    const expected = document.querySelector('input[name="expected_revision"]');
    if (expected) expected.value = String(sheet.revision);
  }

  function statusLabel(sheet) {
    if (sheet.saving) return { text: sprintf(_n('Saving %d change…', 'Saving %d changes…', dirtyCount(sheet), 'viswiz'), dirtyCount(sheet)), kind: 'is-saving' };
    if (sheet.conflict) return { text: __('Conflict: newer server revision detected', 'viswiz'), kind: 'is-conflict' };
    if (sheet.errors.size) return { text: sprintf(_n('%d row needs attention', '%d rows need attention', sheet.errors.size, 'viswiz'), sheet.errors.size), kind: 'is-error' };
    if (sheet.serverMessage) return { text: sheet.serverMessage, kind: 'is-error' };
    const count = dirtyCount(sheet);
    if (count) return { text: sprintf(_n('%d unsaved change', '%d unsaved changes', count, 'viswiz'), count), kind: 'is-dirty' };
    return { text: sprintf(__('All changes saved · r%d', 'viswiz'), sheet.revision), kind: '' };
  }

  function setGuardedDisabled(control, disabled, title) {
    if (!control || !('disabled' in control)) return;
    if (disabled) {
      if (!control.hasAttribute('data-viswiz-grid-guarded')) {
        control.dataset.viswizGridGuarded = control.disabled ? 'was-disabled' : 'enabled';
      }
      control.disabled = true;
      control.title = title;
      return;
    }
    if (!control.hasAttribute('data-viswiz-grid-guarded')) return;
    if (control.dataset.viswizGridGuarded === 'enabled') control.disabled = false;
    control.removeAttribute('data-viswiz-grid-guarded');
    control.removeAttribute('title');
  }

  function updateExternalControls(sheet) {
    const dirty = isDirty(sheet) || sheet.saving;
    if (sheet.searchInput) {
      sheet.searchInput.disabled = dirty;
      sheet.searchInput.title = dirty ? __('Save or discard spreadsheet changes before searching.', 'viswiz') : '';
    }
    SIDE_MUTATION_SELECTORS.forEach((selector) => {
      $$(selector).forEach((control) => setGuardedDisabled(control, dirty, __('Save or discard spreadsheet changes first.', 'viswiz')));
    });
    if (sheet.metadataForm) {
      $$('button[type="submit"],input[type="submit"]', sheet.metadataForm).forEach((control) => {
        setGuardedDisabled(control, dirty, __('Save or discard spreadsheet changes first.', 'viswiz'));
      });
    }
  }

  function updateStatusOnly(sheet) {
    const status = $('[data-viswiz-grid-state]', sheet.root);
    if (status) {
      const current = statusLabel(sheet);
      status.className = `viswiz-grid-state ${current.kind}`.trim();
      status.textContent = current.text;
    }
    const save = $('[data-grid-action="save"]', sheet.root);
    if (save) save.disabled = !isDirty(sheet) || sheet.saving || sheet.errors.size > 0;
    const discard = $('[data-grid-action="discard"]', sheet.root);
    if (discard) discard.disabled = !isDirty(sheet) || sheet.saving;
    updateExternalControls(sheet);
  }

  function rowErrorMarkup(errors, path) {
    const message = errors?.[path];
    return message ? `<span class="viswiz-grid-cell-error">${esc(message)}</span>` : '';
  }

  function render(sheet) {
    const editor = schemaEditor(sheet);
    const fields = tableFields(sheet);
    const rows = displayedRows(sheet);
    const dirty = isDirty(sheet);
    const status = statusLabel(sheet);
    const newCount = [...sheet.drafts.values()].filter((entry) => entry.isNew).length;
    const shownTotal = sheet.total + newCount;

    sheet.root.dataset.viswizSpreadsheetEditor = '1';
    sheet.root.innerHTML = `
      <div class="viswiz-spreadsheet-toolbar">
        <button type="button" class="button" data-grid-action="add">${esc(sprintf(__('Add %s', 'viswiz'), editor.noun || __('row', 'viswiz')))}</button>
        <button type="button" class="button button-primary" data-grid-action="save" ${!dirty || sheet.saving || sheet.errors.size ? 'disabled' : ''}>${__('Save changes', 'viswiz')}</button>
        <button type="button" class="button" data-grid-action="discard" ${!dirty || sheet.saving ? 'disabled' : ''}>${__('Discard changes', 'viswiz')}</button>
        ${sheet.conflict ? '<button type="button" class="button" data-grid-action="reload">${__('Reload server version', 'viswiz')}</button>' : ''}
        <span>${shownTotal} ${esc(editor.plural || 'rows')} · ${esc(cfg.schemas?.[sheet.schema]?.label || sheet.schema)}</span>
        <span class="viswiz-grid-state ${esc(status.kind)}" data-viswiz-grid-state>${esc(status.text)}</span>
      </div>
      ${sheet.serverMessage ? `<div class="notice notice-error inline viswiz-spreadsheet-server-error" data-viswiz-spreadsheet-server-error><p>${esc(sheet.serverMessage)}</p></div>` : ''}
      ${sheet.guardMessage ? `<div class="notice notice-warning inline viswiz-spreadsheet-guard-message" data-viswiz-spreadsheet-guard-message><p>${esc(sheet.guardMessage)}</p></div>` : ''}
      <p class="viswiz-grid-help">${__('Edit cells directly. Tab / Shift+Tab moves between cells, Enter moves down, and Arrow Up/Down moves between text cells. Paste tab-separated rows from spreadsheet software into any cell. Changes remain local until Save changes.', 'viswiz')}</p>
      ${dirty ? '<p class="viswiz-grid-unsaved-note">${__('Save or discard the pending grid changes before searching, changing pages or replacing dataset state from another control.', 'viswiz')}</p>' : ''}
      <div class="viswiz-grid-wrap">
        <table class="widefat striped viswiz-grid" data-viswiz-grid>
          <thead><tr><th class="viswiz-grid-index">#</th>${fields.map((definition) => `<th>${esc(definition.label || definition.path)}</th>`).join('')}<th>${__('Row', 'viswiz')}</th></tr></thead>
          <tbody>
            ${rows.length ? rows.map((row, rowIndex) => {
              const entry = draftEntry(sheet, row.uuid);
              const pendingDelete = sheet.deletes.has(row.uuid);
              const errors = sheet.errors.get(row.uuid) || {};
              const classes = [entry ? 'is-dirty' : '', entry?.isNew ? 'is-new' : '', pendingDelete ? 'is-pending-delete' : ''].filter(Boolean).join(' ');
              const serverIndex = sheet.search ? rowIndex + 1 : ((sheet.page - 1) * PAGE_SIZE) + rowIndex + 1;
              return `<tr class="${classes}" data-grid-row-uuid="${esc(row.uuid)}">
                <td class="viswiz-grid-index">${serverIndex}</td>
                ${fields.map((definition, colIndex) => `<td class="viswiz-grid-cell ${errors[definition.path] ? 'is-invalid' : ''}" data-grid-cell-path="${esc(definition.path)}">${gridInput(definition, row, rowIndex, colIndex, pendingDelete)}${rowErrorMarkup(errors, definition.path)}</td>`).join('')}
                <td class="viswiz-grid-actions">
                  <button type="button" class="button button-small" data-grid-action="advanced" data-row-uuid="${esc(row.uuid)}" ${pendingDelete ? 'disabled' : ''}>${__('Advanced', 'viswiz')}</button>
                  <button type="button" class="${pendingDelete ? 'button button-small' : 'button-link-delete'}" data-grid-action="delete" data-row-uuid="${esc(row.uuid)}">${pendingDelete ? __('Undo', 'viswiz') : __('Remove', 'viswiz')}</button>
                  ${Object.keys(errors).some((path) => !fields.some((definition) => definition.path === path)) ? `<span class="viswiz-grid-row-error">${esc(Object.values(errors)[0])}</span>` : ''}
                </td>
              </tr>`;
            }).join('') : `<tr class="viswiz-grid-empty"><td colspan="${fields.length + 2}">No ${esc(editor.plural || 'rows')} found. Add a row or paste data to begin.</td></tr>`}
          </tbody>
        </table>
      </div>
      ${sheet.totalPages > 1 ? `<div class="viswiz-grid-pager"><button type="button" class="button button-small" data-grid-action="previous" ${sheet.page <= 1 || dirty ? 'disabled' : ''}>${__('Previous', 'viswiz')}</button><span class="viswiz-editor-status">${esc(sprintf(__('Page %1$d / %2$d · %3$d %4$s', 'viswiz'), sheet.page, sheet.totalPages, sheet.total, editor.plural || __('rows', 'viswiz')))}</span><button type="button" class="button button-small" data-grid-action="next" ${sheet.page >= sheet.totalPages || dirty ? 'disabled' : ''}>${__('Next', 'viswiz')}</button></div>` : ''}`;
    updateExternalControls(sheet);
  }

  async function load(sheet, page = null) {
    if (sheet.loading || isDirty(sheet)) return false;
    sheet.loading = true;
    if (page !== null) sheet.page = Math.max(1, page);
    try {
      const qs = queryString({ page: sheet.page, per_page: PAGE_SIZE, search: sheet.search });
      const { data, response } = await request(`/datasets/${sheet.id}/rows?${qs}`);
      sheet.items = Array.isArray(data) ? data : [];
      sheet.total = Number(response.headers.get('X-WP-Total') || 0);
      sheet.totalPages = Math.max(1, Number(response.headers.get('X-WP-TotalPages') || 1));
      sheet.page = Math.min(Math.max(1, Number(response.headers.get('X-VisWiz-Page') || sheet.page)), sheet.totalPages);
      updateRevision(sheet, { revision: Number(response.headers.get('X-VisWiz-Revision') || 0) });
      if (!sheet.items.length && sheet.page > 1 && sheet.total > 0) {
        sheet.page -= 1;
        sheet.loading = false;
        return load(sheet, sheet.page);
      }
      sheet.serverMessage = '';
      render(sheet);
      return true;
    } catch (error) {
      sheet.serverMessage = error.message;
      render(sheet);
      return false;
    } finally {
      sheet.loading = false;
    }
  }

  function applyRenderedErrors(sheet, rowUuid) {
    const tr = sheet.root.querySelector(`[data-grid-row-uuid="${rowUuid}"]`);
    if (!tr) return;
    const errors = sheet.errors.get(rowUuid) || {};
    $$('[data-grid-cell-path]', tr).forEach((cell) => {
      const path = cell.dataset.gridCellPath;
      cell.classList.toggle('is-invalid', Boolean(errors[path]));
      const old = $('.viswiz-grid-cell-error', cell);
      if (old) old.remove();
      if (errors[path]) {
        const message = document.createElement('span');
        message.className = 'viswiz-grid-cell-error';
        message.textContent = errors[path];
        cell.appendChild(message);
      }
    });
    tr.classList.toggle('is-dirty', sheet.drafts.has(rowUuid));
    updateStatusOnly(sheet);
  }

  function applyCellChange(sheet, input) {
    const rowUuid = input.dataset.gridRow;
    const path = input.dataset.fieldPath;
    if (!rowUuid || !path) return;
    const entry = ensureDraft(sheet, rowUuid);
    if (!entry) return;
    setPath(entry.row, path, input.value);
    if (sheet.schema === 'time_series' && path === 'x_value') entry.row.x_numeric = null;
    setRowErrors(sheet, rowUuid, validateRow(sheet, entry.row));
    applyRenderedErrors(sheet, rowUuid);
  }

  function focusCell(sheet, rowIndex, colIndex) {
    const input = sheet.root.querySelector(`[data-grid-index="${rowIndex}"][data-grid-col="${colIndex}"]:not(:disabled)`);
    if (input) {
      input.focus();
      if (typeof input.select === 'function' && input.tagName !== 'TEXTAREA') input.select();
      return true;
    }
    return false;
  }

  function focusFirstError(sheet) {
    for (const row of displayedRows(sheet)) {
      if (sheet.deletes.has(row.uuid)) continue;
      const errors = sheet.errors.get(row.uuid);
      if (!errors) continue;
      const firstPath = Object.keys(errors)[0];
      const input = sheet.root.querySelector(`[data-grid-row="${row.uuid}"][data-field-path="${firstPath}"]`);
      if (input) {
        input.focus();
        return;
      }
    }
  }

  function addRow(sheet, focusCol = 0) {
    const entry = createDraft(sheet);
    setRowErrors(sheet, entry.row.uuid, validateRow(sheet, entry.row));
    const rowIndex = displayedRows(sheet).length - 1;
    render(sheet);
    window.requestAnimationFrame(() => focusCell(sheet, rowIndex, focusCol));
    return entry;
  }

  function toggleDelete(sheet, rowUuid) {
    const entry = draftEntry(sheet, rowUuid);
    if (entry?.isNew) {
      sheet.drafts.delete(rowUuid);
      sheet.errors.delete(rowUuid);
      render(sheet);
      return;
    }
    if (sheet.deletes.has(rowUuid)) sheet.deletes.delete(rowUuid);
    else {
      sheet.deletes.add(rowUuid);
      sheet.errors.delete(rowUuid);
    }
    render(sheet);
  }

  function parsePaste(text) {
    const normalized = String(text || '').replace(/\r\n?/g, '\n');
    let lines = normalized.split('\n');
    if (lines.length > 1 && lines[lines.length - 1] === '') lines = lines.slice(0, -1);
    return lines.map((line) => line.split('\t'));
  }

  function pasteMatrix(sheet, input, matrix) {
    const fields = tableFields(sheet);
    const startRow = Number(input.dataset.gridIndex || 0);
    const startCol = Number(input.dataset.gridCol || 0);
    if (matrix.length > MAX_BATCH) {
      window.alert(sprintf(__('Paste is limited to %d rows at a time.', 'viswiz'), MAX_BATCH));
      return;
    }

    let rows = displayedRows(sheet);
    let changed = 0;
    let finalRow = startRow;
    let finalCol = startCol;
    matrix.forEach((values, rowOffset) => {
      const targetIndex = startRow + rowOffset;
      while (targetIndex >= rows.length) {
        createDraft(sheet);
        rows = displayedRows(sheet);
      }
      const target = rows[targetIndex];
      if (sheet.deletes.has(target.uuid)) return;
      const entry = ensureDraft(sheet, target.uuid);
      if (!entry) return;
      values.forEach((value, colOffset) => {
        const col = startCol + colOffset;
        if (col >= fields.length) return;
        const definition = fields[col];
        let cellValue = value;
        if (definition.type === 'datetime-local') cellValue = dateTimeInputValue(cellValue);
        setPath(entry.row, definition.path, cellValue);
        if (sheet.schema === 'time_series' && definition.path === 'x_value') entry.row.x_numeric = null;
        ++changed;
        finalRow = targetIndex;
        finalCol = col;
      });
      setRowErrors(sheet, target.uuid, validateRow(sheet, entry.row));
    });
    render(sheet);
    window.requestAnimationFrame(() => focusCell(sheet, finalRow, finalCol));
    if (changed) updateStatusOnly(sheet);
  }

  function mapServerIssues(sheet, issues) {
    sheet.errors.clear();
    (Array.isArray(issues) ? issues : []).forEach((issue) => {
      const entry = issue.uuid ? draftEntry(sheet, issue.uuid) : [...sheet.drafts.values()][Number(issue.index || 0)];
      const rowUuid = issue.uuid || entry?.row?.uuid;
      if (!rowUuid) return;
      const errors = sheet.errors.get(rowUuid) || {};
      errors[issue.field || '_row'] = issue.message || __('Validation error.', 'viswiz');
      sheet.errors.set(rowUuid, errors);
    });
  }

  async function save(sheet) {
    if (sheet.saving || !isDirty(sheet)) return;
    if (!validateDrafts(sheet)) {
      render(sheet);
      focusFirstError(sheet);
      return;
    }
    const changed = dirtyCount(sheet);
    if (changed > MAX_BATCH) {
      window.alert(sprintf(__('A single save is limited to %d changed rows.', 'viswiz'), MAX_BATCH));
      return;
    }

    const rows = [...sheet.drafts.entries()]
      .filter(([rowUuid]) => !sheet.deletes.has(rowUuid))
      .map(([, entry]) => entry.row);
    const deleteUuids = [...sheet.deletes];
    sheet.saving = true;
    sheet.conflict = false;
    sheet.serverMessage = '';
    sheet.guardMessage = '';
    updateStatusOnly(sheet);
    try {
      const { data } = await request(`/datasets/${sheet.id}/editor/rows/batch`, {
        method: 'POST',
        body: { rows, delete_uuids: deleteUuids, expected_revision: sheet.revision },
      });
      updateRevision(sheet, data);
      sheet.drafts.clear();
      sheet.deletes.clear();
      sheet.errors.clear();
      sheet.serverMessage = '';
      sheet.saving = false;
      await load(sheet, sheet.page);
    } catch (error) {
      sheet.saving = false;
      if (error.code === 'viswiz_revision_conflict') {
        sheet.conflict = true;
      } else if (error.code === 'viswiz_row_batch_validation') {
        mapServerIssues(sheet, error.data?.issues);
      } else {
        sheet.serverMessage = error.message;
      }
      render(sheet);
      if (sheet.errors.size) focusFirstError(sheet);
    }
  }

  async function discard(sheet) {
    const reloadAfterConflict = sheet.conflict;
    sheet.drafts.clear();
    sheet.deletes.clear();
    sheet.errors.clear();
    sheet.conflict = false;
    sheet.serverMessage = '';
    sheet.guardMessage = '';
    if (reloadAfterConflict) await load(sheet, sheet.page);
    else render(sheet);
  }

  function openAdvanced(sheet, rowUuid) {
    const row = currentRow(sheet, rowUuid);
    if (!row) return;
    const editor = schemaEditor(sheet);
    const dialog = document.createElement('dialog');
    dialog.className = 'viswiz-grid-advanced-dialog';
    dialog.innerHTML = `<form method="dialog" class="viswiz-grid-advanced-body">
      <h2>${__('Advanced row data', 'viswiz')}</h2>
      <label class="viswiz-field"><span>${__('Stable key', 'viswiz')}</span><input name="row_key" value="${esc(row.row_key || '')}"></label>
      <label class="viswiz-field"><span>${__('Additional metadata JSON', 'viswiz')}</span><textarea name="meta" rows="8">${esc(JSON.stringify(additionalMeta(row, editor), null, 2))}</textarea></label>
      <p class="description">${__('Structured schema fields are edited in the spreadsheet. This JSON contains only additional metadata.', 'viswiz')}</p>
      <div class="viswiz-grid-dialog-actions"><button type="button" class="button" data-advanced-cancel>${__('Cancel', 'viswiz')}</button><button type="submit" class="button button-primary" value="save">${__('Apply', 'viswiz')}</button></div>
    </form>`;
    document.body.appendChild(dialog);
    dialog.showModal();
    $('[name="row_key"]', dialog)?.focus();
    $('[data-advanced-cancel]', dialog).addEventListener('click', () => dialog.close('cancel'));
    dialog.addEventListener('close', () => {
      if (dialog.returnValue === 'save') {
        let advanced;
        try {
          advanced = JSON.parse($('[name="meta"]', dialog).value || '{}');
        } catch (_) {
          window.alert(__('Additional metadata JSON is invalid.', 'viswiz'));
          dialog.remove();
          return;
        }
        if (!advanced || Array.isArray(advanced) || typeof advanced !== 'object') {
          window.alert(__('Additional metadata must be a JSON object.', 'viswiz'));
          dialog.remove();
          return;
        }
        const entry = ensureDraft(sheet, rowUuid);
        if (entry) {
          entry.row.row_key = $('[name="row_key"]', dialog).value;
          mergeAdditionalMeta(entry.row, editor, advanced);
          setRowErrors(sheet, rowUuid, validateRow(sheet, entry.row));
          render(sheet);
        }
      }
      dialog.remove();
    }, { once: true });
  }

  function bindGrid(sheet) {
    sheet.root.addEventListener('input', (event) => {
      const input = event.target.closest?.('.viswiz-grid-input');
      if (input) applyCellChange(sheet, input);
    });

    sheet.root.addEventListener('paste', (event) => {
      const input = event.target.closest?.('.viswiz-grid-input');
      if (!input) return;
      const text = event.clipboardData?.getData('text/plain') || '';
      if (input.tagName === 'TEXTAREA' && !text.includes('\t')) return;
      if (!text.includes('\t') && !/[\r\n]/.test(text)) return;
      event.preventDefault();
      pasteMatrix(sheet, input, parsePaste(text));
    });

    sheet.root.addEventListener('keydown', (event) => {
      const input = event.target.closest?.('.viswiz-grid-input');
      if (!input) return;
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        save(sheet);
        return;
      }
      const rowIndex = Number(input.dataset.gridIndex || 0);
      const colIndex = Number(input.dataset.gridCol || 0);
      if (event.key === 'Tab') {
        const inputs = $$('.viswiz-grid-input:not(:disabled)', sheet.root);
        const position = inputs.indexOf(input);
        const next = inputs[position + (event.shiftKey ? -1 : 1)];
        if (next) {
          event.preventDefault();
          next.focus();
          if (typeof next.select === 'function' && next.tagName !== 'TEXTAREA') next.select();
        }
        return;
      }
      if (event.key === 'Enter' && input.tagName !== 'TEXTAREA') {
        event.preventDefault();
        if (!focusCell(sheet, rowIndex + 1, colIndex)) addRow(sheet, colIndex);
        return;
      }
      if ((event.key === 'ArrowUp' || event.key === 'ArrowDown') && input.tagName !== 'TEXTAREA' && input.type === 'text') {
        event.preventDefault();
        focusCell(sheet, rowIndex + (event.key === 'ArrowUp' ? -1 : 1), colIndex);
      }
    });

    sheet.root.addEventListener('click', async (event) => {
      const action = event.target.closest?.('[data-grid-action]');
      if (!action) return;
      const name = action.dataset.gridAction;
      if (name === 'add') addRow(sheet, 0);
      else if (name === 'save') await save(sheet);
      else if (name === 'discard') await discard(sheet);
      else if (name === 'reload') window.location.reload();
      else if (name === 'delete') toggleDelete(sheet, action.dataset.rowUuid);
      else if (name === 'advanced') openAdvanced(sheet, action.dataset.rowUuid);
      else if (name === 'previous' && !isDirty(sheet)) await load(sheet, sheet.page - 1);
      else if (name === 'next' && !isDirty(sheet)) await load(sheet, sheet.page + 1);
    });
  }

  function bindSearch(sheet) {
    const original = document.querySelector('[data-viswiz-dataset-search]');
    if (!original) return;
    const search = original.cloneNode(true);
    original.replaceWith(search);
    sheet.searchInput = search;
    search.value = sheet.search;
    let timer = 0;
    search.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(async () => {
        if (isDirty(sheet)) return;
        sheet.search = search.value.trim();
        sheet.page = 1;
        await load(sheet, 1);
      }, 220);
    });
  }

  function bindExternalGuards(sheet) {
    if (!sheet.metadataForm) return;
    sheet.metadataForm.addEventListener('submit', (event) => {
      if (!isDirty(sheet) && !sheet.saving) return;
      event.preventDefault();
      sheet.guardMessage = __('Save or discard spreadsheet changes before changing dataset metadata.', 'viswiz');
      render(sheet);
    }, true);
  }

  async function waitForBaseEditor(root) {
    for (let attempt = 0; attempt < 160; attempt += 1) {
      const state = root.__viswizServerState;
      if (state && state.schema !== 'graph' && state.rows && state.rows.loading === false) return state;
      await new Promise((resolve) => window.setTimeout(resolve, 25));
    }
    return null;
  }

  async function init() {
    const root = $('#viswiz-dataset-editor[data-viswiz-server-editor]');
    if (!root || !cfg.restUrl || root.dataset.schema === 'graph') return;
    const baseState = await waitForBaseEditor(root);
    if (!baseState) return;

    const metadataForm = document.querySelector('form input[name="action"][value="viswiz_dataset_update"]')?.closest('form') || null;
    const sheet = {
      root,
      baseState,
      id: Number(root.dataset.datasetId || baseState.id || 0),
      schema: root.dataset.schema || baseState.schema || 'categorical',
      revision: Number(root.dataset.revision || baseState.revision || 0),
      search: '',
      page: 1,
      total: 0,
      totalPages: 1,
      items: [],
      drafts: new Map(),
      deletes: new Set(),
      errors: new Map(),
      loading: false,
      saving: false,
      conflict: false,
      serverMessage: '',
      guardMessage: '',
      searchInput: null,
      metadataForm,
    };
    root.__viswizSpreadsheetState = sheet;
    bindGrid(sheet);
    bindSearch(sheet);
    bindExternalGuards(sheet);
    window.addEventListener('beforeunload', (event) => {
      if (!isDirty(sheet)) return;
      event.preventDefault();
      event.returnValue = '';
    });
    await load(sheet, 1);
  }

  init();
})();