(() => {
  'use strict';

  const cfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c]));
  const uuid = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
  const PAGE_SIZE = 100;
  const NODE_RELATION_PAGE_SIZE = 20;

  async function request(path, options = {}) {
    const method = options.method || 'GET';
    const response = await fetch(`${cfg.restUrl}${path}`, {
      credentials: 'same-origin',
      method,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.code) {
      const error = new Error(data?.message || cfg.i18n?.error || `HTTP ${response.status}`);
      error.code = data?.code || '';
      error.data = data?.data || {};
      throw error;
    }
    return { data, response };
  }

  function collectionMeta(response) {
    return {
      total: Number(response.headers.get('X-WP-Total') || 0),
      totalPages: Math.max(1, Number(response.headers.get('X-WP-TotalPages') || 1)),
      page: Math.max(1, Number(response.headers.get('X-VisWiz-Page') || 1)),
    };
  }

  function notice(root, message, kind = 'success') {
    let box = $('[data-viswiz-editor-notice]', root);
    if (!box) {
      box = document.createElement('div');
      box.dataset.viswizEditorNotice = '1';
      root.prepend(box);
    }
    box.className = `notice notice-${kind} inline viswiz-editor-notice`;
    box.innerHTML = `<p>${esc(message)}</p>`;
    window.setTimeout(() => { if (box.isConnected) box.remove(); }, kind === 'error' ? 9000 : 3500);
  }

  function clearFieldError(form, name) {
    const control = form.elements.namedItem(name);
    if (!(control instanceof HTMLElement)) return;
    control.removeAttribute('aria-invalid');
    const field = control.closest('.viswiz-field');
    if (!field) return;
    field.classList.remove('form-invalid');
    $('[data-viswiz-field-error]', field)?.remove();
  }

  function showFieldError(form, name, message) {
    clearFieldError(form, name);
    const control = form.elements.namedItem(name);
    if (!(control instanceof HTMLElement)) return;
    const field = control.closest('.viswiz-field');
    if (!field) return;
    control.setAttribute('aria-invalid', 'true');
    field.classList.add('form-invalid');
    const error = document.createElement('span');
    error.className = 'description viswiz-field-error';
    error.dataset.viswizFieldError = name;
    error.setAttribute('role', 'alert');
    error.style.color = '#d63638';
    error.textContent = message;
    field.appendChild(error);
    control.focus();
  }

  function button(text, className = 'button') {
    const element = document.createElement('button');
    element.type = 'button';
    element.className = className;
    element.textContent = text;
    return element;
  }

  function statusText(text) {
    const span = document.createElement('span');
    span.className = 'viswiz-editor-status';
    span.textContent = text;
    return span;
  }

  function field(label, name, value = '', type = 'text', extra = '') {
    return `<label class="viswiz-field"><span>${esc(label)}</span><input type="${esc(type)}" name="${esc(name)}" value="${esc(value ?? '')}" ${extra}></label>`;
  }

  function textareaField(label, name, value = '', rows = 4) {
    return `<label class="viswiz-field"><span>${esc(label)}</span><textarea name="${esc(name)}" rows="${rows}">${esc(value)}</textarea></label>`;
  }

  function nullable(value) {
    if (value === '' || value === null || value === undefined) return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function sentenceCase(value) {
    const text = String(value || 'row');
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  function schemaEditor(state) {
    const editor = cfg.schemas?.[state.schema]?.editor;
    if (editor && Array.isArray(editor.fields) && editor.fields.length) return editor;
    return {
      noun: 'row',
      plural: 'rows',
      fields: [
        { path: 'label', label: 'Label', type: 'text', table: true },
        { path: 'value', label: 'Value', type: 'number', table: true, step: 'any' },
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
      if (index === parts.length - 1) {
        cursor[part] = value;
        return;
      }
      if (!cursor[part] || typeof cursor[part] !== 'object') cursor[part] = {};
      cursor = cursor[part];
    });
  }

  function schemaFieldAttributes(definition) {
    const attributes = [];
    if (definition.required) attributes.push('required');
    if (definition.step !== undefined) attributes.push(`step="${esc(definition.step)}"`);
    if (definition.min !== undefined) attributes.push(`min="${esc(definition.min)}"`);
    if (definition.max !== undefined) attributes.push(`max="${esc(definition.max)}"`);
    if (definition.placeholder) attributes.push(`placeholder="${esc(definition.placeholder)}"`);
    return attributes.join(' ');
  }

  function dateTimeInputValue(value) {
    const text = String(value || '').trim();
    if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(text)) return text.slice(0, 16);
    if (/^\d{4}-\d{2}-\d{2}$/.test(text)) return `${text}T00:00`;
    return text;
  }

  function schemaFieldMarkup(definition, current) {
    const path = String(definition.path || '');
    let value = pathValue(current, path);
    const type = definition.type || 'text';
    const attributes = schemaFieldAttributes(definition);
    if ('textarea' === type) {
      return `<label class="viswiz-field viswiz-schema-field viswiz-schema-field-wide"><span>${esc(definition.label || path)}</span><textarea data-viswiz-schema-field="${esc(path)}" name="${esc(path)}" rows="${Number(definition.rows || 4)}" ${attributes}>${esc(value ?? '')}</textarea></label>`;
    }
    if ('datetime-local' === type) value = dateTimeInputValue(value);
    if ('color' === type && !value) value = '#2563eb';
    return `<label class="viswiz-field viswiz-schema-field"><span>${esc(definition.label || path)}</span><input data-viswiz-schema-field="${esc(path)}" type="${esc(type)}" name="${esc(path)}" value="${esc(value ?? '')}" ${attributes}></label>`;
  }

  function schemaFieldValue(definition, value) {
    if ('number' === definition.type) return nullable(value);
    return value === null || value === undefined ? '' : String(value);
  }

  function compactText(value, max = 120) {
    const text = String(value ?? '').replace(/\s+/g, ' ').trim();
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
  }

  function schemaTableValue(definition, row) {
    const value = pathValue(row, definition.path);
    if ('color' === definition.type) {
      if (!value) return '';
      return `<span class="viswiz-schema-color"><span class="viswiz-schema-color-swatch" style="background:${esc(value)}"></span><code>${esc(value)}</code></span>`;
    }
    if ('textarea' === definition.type) return esc(compactText(value));
    return esc(value ?? '');
  }

  function knownMetaKeys(editor) {
    return editor.fields
      .map((definition) => String(definition.path || ''))
      .filter((path) => path.startsWith('meta.') && !path.slice(5).includes('.'))
      .map((path) => path.slice(5));
  }

  function additionalMeta(current, editor) {
    const meta = current.meta && typeof current.meta === 'object' ? { ...current.meta } : {};
    knownMetaKeys(editor).forEach((key) => delete meta[key]);
    return meta;
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value ?? {}));
  }

  function makeDialog(title) {
    const dialog = document.createElement('dialog');
    dialog.className = 'viswiz-editor-dialog';
    const body = document.createElement('div');
    body.className = 'viswiz-editor-dialog-body';
    const head = document.createElement('div');
    head.className = 'viswiz-dialog-heading';
    head.innerHTML = `<h2>${esc(title)}</h2>`;
    const close = button('×', 'viswiz-dialog-close');
    close.setAttribute('aria-label', 'Close');
    close.addEventListener('click', () => dialog.close());
    head.appendChild(close);
    body.appendChild(head);
    dialog.appendChild(body);
    dialog.addEventListener('close', () => dialog.remove());
    return { dialog, body };
  }

  function queryString(params) {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) query.set(key, String(value));
    });
    return query.toString();
  }

  function collectionState() {
    return { items: [], page: 1, total: 0, totalPages: 1, loading: false };
  }

  async function loadCollection(state, kind, page = null) {
    const collection = state[kind];
    if (!collection || collection.loading) return;
    collection.loading = true;
    if (page !== null) collection.page = Math.max(1, page);
    try {
      const qs = queryString({ page: collection.page, per_page: PAGE_SIZE, search: state.search });
      const { data, response } = await request(`/datasets/${state.id}/${kind}?${qs}`);
      const meta = collectionMeta(response);
      collection.items = Array.isArray(data) ? data : [];
      collection.total = meta.total;
      collection.totalPages = meta.totalPages;
      collection.page = Math.min(meta.page, meta.totalPages);
      if (!collection.items.length && collection.page > 1 && collection.total > 0) {
        collection.page -= 1;
        collection.loading = false;
        return loadCollection(state, kind, collection.page);
      }
    } catch (error) {
      notice(state.root, error.message, 'error');
    } finally {
      collection.loading = false;
    }
  }

  async function refresh(state, kinds = null) {
    const targets = kinds || (state.schema === 'graph' ? ['nodes', 'relations'] : ['rows']);
    await Promise.all(targets.map((kind) => loadCollection(state, kind)));
    render(state);
  }

  function updateRevision(state, result) {
    if (!result?.revision) return;
    state.revision = Number(result.revision);
    state.root.dataset.revision = String(state.revision);
    const heading = document.querySelector('.viswiz-admin-wrap h1 small');
    if (heading) heading.textContent = `r${state.revision}`;
    const metadataRevision = document.querySelector('input[name="expected_revision"]');
    if (metadataRevision) metadataRevision.value = String(state.revision);
  }

  async function mutate(state, path, method, body, refreshKinds, options = {}) {
    if (state.saving) return false;
    state.saving = true;
    state.root.classList.add('is-saving');
    try {
      const { data } = await request(path, { method, body: { ...body, expected_revision: state.revision } });
      updateRevision(state, data);
      notice(state.root, cfg.i18n?.saved || 'Saved.');
      await refresh(state, refreshKinds);
      return true;
    } catch (error) {
      const message = error.code === 'viswiz_revision_conflict' ? (cfg.i18n?.conflict || error.message) : error.message;
      notice(options.errorRoot || state.root, message, 'error');
      if (typeof options.onError === 'function') options.onError(error);
      if (error.code === 'viswiz_revision_conflict') state.root.classList.add('has-conflict');
      return false;
    } finally {
      state.saving = false;
      state.root.classList.remove('is-saving');
    }
  }

  function appendPager(host, collection, noun, onChange) {
    if (collection.totalPages <= 1) return;
    const pager = document.createElement('div');
    pager.className = 'viswiz-editor-pager';
    const previous = button('Previous', 'button button-small');
    const next = button('Next', 'button button-small');
    previous.disabled = collection.page <= 1;
    next.disabled = collection.page >= collection.totalPages;
    pager.append(previous, statusText(`Page ${collection.page} / ${collection.totalPages} · ${collection.total} ${noun}`), next);
    previous.addEventListener('click', () => onChange(collection.page - 1));
    next.addEventListener('click', () => onChange(collection.page + 1));
    host.appendChild(pager);
  }

  function render(state) {
    state.root.replaceChildren();
    if (state.schema === 'graph') renderGraph(state); else renderRows(state);
  }

  function renderRows(state) {
    const collection = state.rows;
    const editor = schemaEditor(state);
    const tableFields = editor.fields.filter((definition) => definition.table !== false);
    const bar = document.createElement('div');
    bar.className = 'viswiz-editor-toolbar viswiz-server-status';
    const add = button(`Add ${editor.noun || 'row'}`, 'button button-primary');
    bar.append(
      add,
      statusText(`${collection.total} ${editor.plural || 'rows'} · revision ${state.revision}`),
      statusText(cfg.schemas?.[state.schema]?.label || state.schema),
      statusText('Server paged')
    );
    state.root.appendChild(bar);

    const table = document.createElement('table');
    table.className = 'widefat striped viswiz-table viswiz-schema-table';
    table.dataset.viswizSchemaTable = state.schema;
    table.innerHTML = `<thead><tr>${tableFields.map((definition) => `<th>${esc(definition.label || definition.path)}</th>`).join('')}<th></th></tr></thead><tbody></tbody>`;
    const tbody = $('tbody', table);
    if (!collection.items.length) {
      tbody.innerHTML = `<tr class="viswiz-empty-row"><td colspan="${tableFields.length + 1}">No ${esc(editor.plural || 'rows')} found.</td></tr>`;
    }
    collection.items.forEach((row) => {
      const tr = document.createElement('tr');
      tr.dataset.viswizItemUuid = row.uuid;
      tr.innerHTML = `${tableFields.map((definition) => `<td data-viswiz-field-path="${esc(definition.path)}">${schemaTableValue(definition, row)}</td>`).join('')}<td class="viswiz-row-actions"></td>`;
      const edit = button('Edit', 'button button-small');
      const del = button('Delete', 'button-link-delete');
      $('.viswiz-row-actions', tr).append(edit, document.createTextNode(' '), del);
      edit.addEventListener('click', () => openRowDialog(state, row));
      del.addEventListener('click', async () => {
        if (!window.confirm(cfg.i18n?.confirmDelete || 'Delete this item?')) return;
        await mutate(state, `/datasets/${state.id}/editor/rows/${row.uuid}`, 'DELETE', {}, ['rows']);
      });
      tbody.appendChild(tr);
    });
    state.root.appendChild(table);
    appendPager(state.root, collection, editor.plural || 'rows', async (page) => { await loadCollection(state, 'rows', page); render(state); });
    add.addEventListener('click', () => openRowDialog(state, null));
  }

  function openRowDialog(state, row) {
    const editor = schemaEditor(state);
    const current = row || { uuid: uuid(), label: '', row_key: '', value: null, x_value: '', x_numeric: null, y_value: null, latitude: null, longitude: null, color: '', meta: {} };
    if (!row && !current.row_key) current.row_key = `manual-${current.uuid.replace(/-/g, '').slice(0, 12)}`;
    const noun = editor.noun || 'row';
    const modal = makeDialog(`${row ? 'Edit' : 'Add'} ${noun}`);
    const form = document.createElement('form');
    form.className = 'viswiz-dialog-form viswiz-schema-dialog-form';
    form.dataset.viswizSchemaForm = state.schema;
    form.innerHTML = `
      <div class="viswiz-form-grid viswiz-schema-fields">${editor.fields.map((definition) => schemaFieldMarkup(definition, current)).join('')}</div>
      <details class="viswiz-editor-advanced">
        <summary>Advanced</summary>
        <div class="viswiz-form-grid">${field('Stable key', 'row_key', current.row_key)}</div>
        ${textareaField('Additional metadata JSON', 'meta_advanced', JSON.stringify(additionalMeta(current, editor), null, 2), 5)}
      </details>
      <div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>Cancel</button><button type="submit" class="button button-primary">Save ${esc(noun)}</button></div>`;
    modal.body.appendChild(form);
    document.body.appendChild(modal.dialog);
    modal.dialog.showModal();
    $('[data-viswiz-schema-field]', form)?.focus();
    $('[data-cancel]', form).addEventListener('click', () => modal.dialog.close());
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = new FormData(form);
      let meta = {};
      try { meta = JSON.parse(fd.get('meta_advanced') || '{}'); } catch (_) { notice(modal.body, 'Additional metadata JSON is invalid.', 'error'); return; }
      if (!meta || Array.isArray(meta) || typeof meta !== 'object') {
        notice(modal.body, 'Additional metadata must be a JSON object.', 'error');
        return;
      }
      const data = {
        uuid: current.uuid,
        row_key: fd.get('row_key'),
        label: '',
        value: null,
        x_value: '',
        x_numeric: null,
        y_value: null,
        latitude: null,
        longitude: null,
        color: '',
        meta,
      };
      editor.fields.forEach((definition) => {
        setPath(data, definition.path, schemaFieldValue(definition, fd.get(definition.path)));
      });
      if (state.schema === 'time_series' && data.x_value) {
        const timestamp = Date.parse(data.x_value);
        data.x_numeric = Number.isFinite(timestamp) ? Math.floor(timestamp / 1000) : null;
      }
      if (await mutate(state, `/datasets/${state.id}/editor/rows`, 'POST', { row: data }, ['rows'], { errorRoot: modal.body })) modal.dialog.close();
    });
  }

  function nodeTitle(node) {
    return node?.title || node?.label || node?.slug || node?.uuid || 'Node';
  }

  function duplicateNodeSeed(node) {
    const id = uuid();
    const baseSlug = String(node.slug || node.title || 'node').toLowerCase().replace(/[^a-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '') || 'node';
    return {
      ...clone(node),
      uuid: id,
      slug: `${baseSlug}-copy-${id.replace(/-/g, '').slice(0, 8)}`,
      title: `${nodeTitle(node)} copy`,
      label: node.label || nodeTitle(node),
    };
  }

  function duplicateRelationSeed(relation) {
    return { ...clone(relation), uuid: uuid() };
  }

  function renderGraph(state) {
    const nodes = state.nodes;
    const relations = state.relations;
    const bar = document.createElement('div');
    bar.className = 'viswiz-editor-toolbar viswiz-server-status';
    const addNode = button('Add node', 'button button-primary');
    const addRelation = button('Add relation', 'button');
    bar.append(addNode, addRelation, statusText(`${nodes.total} nodes · ${relations.total} relations · revision ${state.revision}`), statusText('Server paged'));
    state.root.appendChild(bar);

    const nodeSection = document.createElement('section');
    nodeSection.className = 'viswiz-server-editor-section';
    nodeSection.innerHTML = '<h3>Nodes</h3>';
    const table = document.createElement('table');
    table.className = 'widefat striped viswiz-table';
    table.innerHTML = '<thead><tr><th>Node</th><th>Type</th><th>Slug</th><th>Degree</th><th></th></tr></thead><tbody></tbody>';
    const tbody = $('tbody', table);
    if (!nodes.items.length) tbody.innerHTML = '<tr><td colspan="5">No nodes found.</td></tr>';
    nodes.items.forEach((node) => {
      const tr = document.createElement('tr');
      tr.dataset.viswizItemUuid = node.uuid;
      tr.innerHTML = `<td><strong>${esc(nodeTitle(node))}</strong></td><td>${esc(node.node_type || '')}${node.node_subtype ? ` / ${esc(node.node_subtype)}` : ''}</td><td><code>${esc(node.slug || '')}</code></td><td>${esc(node.degree || 0)}</td><td class="viswiz-row-actions"></td>`;
      const edit = button('Edit', 'button button-small');
      const addFromNode = button('Add relation', 'button button-small');
      const duplicate = button('Duplicate', 'button button-small');
      const del = button('Delete', 'button-link-delete');
      $('.viswiz-row-actions', tr).append(edit, document.createTextNode(' '), addFromNode, document.createTextNode(' '), duplicate, document.createTextNode(' '), del);
      edit.addEventListener('click', () => openNodeDialog(state, node));
      addFromNode.addEventListener('click', () => openRelationDialog(state, null, { fromNode: node }));
      duplicate.addEventListener('click', () => openNodeDialog(state, null, { title: 'Duplicate node', seed: duplicateNodeSeed(node) }));
      del.addEventListener('click', async () => {
        if (!window.confirm(cfg.i18n?.confirmDelete || 'Delete this item?')) return;
        await mutate(state, `/datasets/${state.id}/editor/nodes/${node.uuid}`, 'DELETE', {}, ['nodes', 'relations']);
      });
      tbody.appendChild(tr);
    });
    nodeSection.appendChild(table);
    appendPager(nodeSection, nodes, 'nodes', async (page) => { await loadCollection(state, 'nodes', page); render(state); });
    state.root.appendChild(nodeSection);

    const relationSection = document.createElement('section');
    relationSection.className = 'viswiz-server-editor-section';
    relationSection.innerHTML = '<h3>Relations</h3>';
    const rtable = document.createElement('table');
    rtable.className = 'widefat striped viswiz-table';
    rtable.innerHTML = '<thead><tr><th>From</th><th>Relation</th><th>To</th><th>Direction</th><th></th></tr></thead><tbody></tbody>';
    const rbody = $('tbody', rtable);
    if (!relations.items.length) rbody.innerHTML = '<tr><td colspan="5">No relations found.</td></tr>';
    relations.items.forEach((rel) => {
      const tr = document.createElement('tr');
      tr.dataset.viswizItemUuid = rel.uuid;
      tr.innerHTML = `<td>${esc(rel.from_title || rel.from_slug || 'Missing')}</td><td>${esc(rel.label || rel.relation_type || '')}</td><td>${esc(rel.to_title || rel.to_slug || 'Missing')}</td><td>${esc(rel.direction || 'directed')}</td><td class="viswiz-row-actions"></td>`;
      const edit = button('Edit', 'button button-small');
      const duplicate = button('Duplicate', 'button button-small');
      const del = button('Delete', 'button-link-delete');
      $('.viswiz-row-actions', tr).append(edit, document.createTextNode(' '), duplicate, document.createTextNode(' '), del);
      edit.addEventListener('click', () => openRelationDialog(state, rel));
      duplicate.addEventListener('click', () => openRelationDialog(state, null, { title: 'Duplicate relation', seed: duplicateRelationSeed(rel) }));
      del.addEventListener('click', async () => {
        if (!window.confirm(cfg.i18n?.confirmDelete || 'Delete this item?')) return;
        await mutate(state, `/datasets/${state.id}/editor/relations/${rel.uuid}`, 'DELETE', {}, ['nodes', 'relations']);
      });
      rbody.appendChild(tr);
    });
    relationSection.appendChild(rtable);
    appendPager(relationSection, relations, 'relations', async (page) => { await loadCollection(state, 'relations', page); render(state); });
    state.root.appendChild(relationSection);

    addNode.addEventListener('click', () => openNodeDialog(state, null));
    addRelation.addEventListener('click', () => openRelationDialog(state, null));
  }

  async function renderNodeRelationsPanel(state, node, panel, page = 1) {
    panel.dataset.loading = '1';
    panel.innerHTML = '<p class="description">Loading connected relations…</p>';
    try {
      const qs = queryString({ node_uuid: node.uuid, page, per_page: NODE_RELATION_PAGE_SIZE });
      const { data, response } = await request(`/datasets/${state.id}/relations?${qs}`);
      const meta = collectionMeta(response);
      const items = Array.isArray(data) ? data : [];
      panel.replaceChildren();

      const heading = document.createElement('div');
      heading.className = 'viswiz-section-heading';
      const headingText = document.createElement('div');
      headingText.innerHTML = `<h3>Connected relations</h3><p class="description">${meta.total} relation${meta.total === 1 ? '' : 's'} for ${esc(nodeTitle(node))}.</p>`;
      const add = button('Add relation from this node', 'button button-small');
      heading.append(headingText, add);
      panel.appendChild(heading);
      add.addEventListener('click', () => openRelationDialog(state, null, { fromNode: node, onSaved: () => renderNodeRelationsPanel(state, node, panel, meta.page) }));

      const table = document.createElement('table');
      table.className = 'widefat striped viswiz-table viswiz-node-relations-table';
      table.innerHTML = '<thead><tr><th>Role</th><th>Relation</th><th>Other node</th><th></th></tr></thead><tbody></tbody>';
      const body = $('tbody', table);
      if (!items.length) body.innerHTML = '<tr><td colspan="4">No connected relations.</td></tr>';
      items.forEach((relation) => {
        const outgoing = relation.from_node_uuid === node.uuid;
        const other = outgoing
          ? (relation.to_title || relation.to_slug || relation.to_node_uuid)
          : (relation.from_title || relation.from_slug || relation.from_node_uuid);
        const tr = document.createElement('tr');
        tr.dataset.relationUuid = relation.uuid;
        tr.innerHTML = `<td>${outgoing ? 'Outgoing' : 'Incoming'}</td><td>${esc(relation.label || relation.relation_type || 'Unspecified')}</td><td>${esc(other)}</td><td class="viswiz-row-actions"></td>`;
        const edit = button('Edit', 'button button-small');
        $('.viswiz-row-actions', tr).appendChild(edit);
        edit.addEventListener('click', () => openRelationDialog(state, relation, { onSaved: () => renderNodeRelationsPanel(state, node, panel, meta.page) }));
        body.appendChild(tr);
      });
      panel.appendChild(table);

      if (meta.totalPages > 1) {
        const pagerState = { page: meta.page, total: meta.total, totalPages: meta.totalPages };
        appendPager(panel, pagerState, 'relations', (nextPage) => renderNodeRelationsPanel(state, node, panel, nextPage));
      }
    } catch (error) {
      panel.innerHTML = '';
      notice(panel, error.message, 'error');
    } finally {
      delete panel.dataset.loading;
    }
  }

  function openNodeDialog(state, node, options = {}) {
    const current = clone(node || options.seed || { uuid: uuid(), slug: '', title: '', label: '', node_type: '', node_subtype: '', description: '', main_image_id: 0, other_image_ids: [], meta: {} });
    if (!current.uuid) current.uuid = uuid();
    const modal = makeDialog(options.title || (node ? 'Edit node' : 'Add node'));
    const form = document.createElement('form');
    form.className = 'viswiz-dialog-form';
    const typeOptions = Object.entries(cfg.nodeTypes || {}).map(([key, item]) => `<option value="${esc(key)}" ${current.node_type === key ? 'selected' : ''}>${esc(item.label || key)}</option>`).join('');
    form.innerHTML = `${field('Title', 'title', current.title)}<div class="viswiz-form-grid">${field('Slug', 'slug', current.slug)}${field('Label', 'label', current.label)}</div><div class="viswiz-form-grid"><label class="viswiz-field"><span>Node type</span><select name="node_type"><option value="">Select type</option>${typeOptions}</select></label><label class="viswiz-field"><span>Subtype</span><select name="node_subtype"></select></label></div>${textareaField('Description (safe HTML)', 'description', current.description || current.description_html || '', 7)}<div class="viswiz-form-grid">${field('Featured image ID', 'main_image_id', current.main_image_id, 'number', 'min="0"')}${field('Other image IDs', 'other_image_ids', (current.other_image_ids || []).join(','))}</div>${textareaField('Metadata JSON', 'meta', JSON.stringify(current.meta || {}, null, 2), 5)}<div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>Cancel</button><button type="submit" class="button button-primary">Save node</button></div>`;

    if (node) {
      const panel = document.createElement('section');
      panel.className = 'viswiz-node-relations-panel';
      panel.dataset.viswizNodeRelations = node.uuid;
      form.insertBefore(panel, $('.viswiz-dialog-actions', form));
      renderNodeRelationsPanel(state, node, panel, 1);
    }

    modal.body.appendChild(form);
    document.body.appendChild(modal.dialog);
    modal.dialog.showModal();
    $('[name="title"]', form)?.focus();

    const mainImage = $('[name="main_image_id"]', form);
    const otherImages = $('[name="other_image_ids"]', form);
    if (window.wp?.media && mainImage && otherImages) {
      const mainButton = button('Choose featured image', 'button viswiz-media-button');
      mainImage.insertAdjacentElement('afterend', mainButton);
      mainButton.addEventListener('click', () => {
        const frame = wp.media({ title: 'Choose featured image', multiple: false, library: { type: 'image' } });
        frame.on('select', () => { const item = frame.state().get('selection').first()?.toJSON(); if (item) mainImage.value = String(item.id || 0); });
        frame.open();
      });
      const otherButton = button('Choose other images', 'button viswiz-media-button');
      otherImages.insertAdjacentElement('afterend', otherButton);
      otherButton.addEventListener('click', () => {
        const frame = wp.media({ title: 'Choose node images', multiple: true, library: { type: 'image' } });
        frame.on('select', () => { otherImages.value = frame.state().get('selection').map((item) => item.toJSON().id).filter(Boolean).join(','); });
        frame.open();
      });
    }

    const type = $('[name="node_type"]', form);
    const subtype = $('[name="node_subtype"]', form);
    const refreshSubtype = () => {
      const selected = current.node_subtype || subtype.value;
      const entries = cfg.nodeTypes?.[type.value]?.subtypes || {};
      subtype.innerHTML = '<option value="">No subtype</option>' + Object.entries(entries).map(([key, value]) => `<option value="${esc(key)}" ${selected === key ? 'selected' : ''}>${esc(value)}</option>`).join('');
    };
    type.addEventListener('change', () => { current.node_subtype = ''; refreshSubtype(); });
    refreshSubtype();

    const nodeErrorFields = {
      viswiz_duplicate_node_slug: ['slug'],
      viswiz_unknown_node_type: ['node_type'],
      viswiz_unknown_node_subtype: ['node_subtype'],
      viswiz_invalid_node: ['title', 'node_type'],
    };
    const trackedNodeErrorFields = new Set(Object.values(nodeErrorFields).flat());
    trackedNodeErrorFields.forEach((name) => {
      const control = form.elements.namedItem(name);
      if (!(control instanceof HTMLElement)) return;
      const clear = () => clearFieldError(form, name);
      control.addEventListener('input', clear);
      control.addEventListener('change', clear);
    });

    $('[data-cancel]', form).addEventListener('click', () => modal.dialog.close());
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      trackedNodeErrorFields.forEach((name) => clearFieldError(form, name));
      const fd = new FormData(form);
      let meta = {};
      try { meta = JSON.parse(fd.get('meta') || '{}'); } catch (_) { notice(modal.body, 'Metadata JSON is invalid.', 'error'); return; }
      const data = {
        uuid: current.uuid,
        title: fd.get('title'),
        slug: fd.get('slug'),
        label: fd.get('label'),
        node_type: fd.get('node_type'),
        node_subtype: fd.get('node_subtype'),
        description: fd.get('description'),
        main_image_id: Number(fd.get('main_image_id') || 0),
        other_image_ids: String(fd.get('other_image_ids') || '').split(',').map(Number).filter(Boolean),
        meta,
      };
      if (await mutate(state, `/datasets/${state.id}/editor/nodes`, 'POST', { node: data }, ['nodes', 'relations'], {
        errorRoot: modal.body,
        onError: (error) => {
          (nodeErrorFields[error.code] || []).forEach((name) => showFieldError(form, name, error.message));
        },
      })) {
        if (typeof options.onSaved === 'function') await options.onSaved(data);
        modal.dialog.close();
      }
    });
  }

  function nodePicker(state, label, name, selectedUuid, selectedLabel, selectedMeta = {}) {
    const wrapper = document.createElement('div');
    wrapper.className = 'viswiz-node-picker';
    const title = document.createElement('label');
    title.textContent = label;
    const search = document.createElement('input');
    search.type = 'search';
    search.placeholder = 'Search nodes…';
    search.autocomplete = 'off';
    search.setAttribute('aria-label', `${label} node search`);
    const select = document.createElement('select');
    select.name = name;
    select.size = 7;
    select.required = true;
    select.setAttribute('aria-label', `${label} node`);
    const create = button('Create node…', 'button button-small viswiz-create-endpoint-node');
    create.setAttribute('aria-label', `Create ${label.toLowerCase()} node`);
    wrapper.append(title, search, select, create);

    let timer = 0;
    let generation = 0;
    const setOptionMeta = (option, node) => {
      option.dataset.nodeType = node?.node_type || '';
      option.dataset.nodeSubtype = node?.node_subtype || '';
      option.dataset.nodeTitle = nodeTitle(node);
      option.dataset.nodeSlug = node?.slug || '';
    };
    const load = async (term, preferredUuid = '', preferredMeta = selectedMeta) => {
      const currentGeneration = ++generation;
      try {
        const qs = queryString({ search: term, per_page: 30 });
        const { data } = await request(`/datasets/${state.id}/nodes/options?${qs}`);
        if (currentGeneration !== generation) return;
        const items = Array.isArray(data) ? data : [];
        select.replaceChildren();
        items.forEach((node) => {
          const option = document.createElement('option');
          option.value = node.uuid;
          option.textContent = `${nodeTitle(node)} — ${node.slug}`;
          setOptionMeta(option, node);
          select.appendChild(option);
        });
        if (preferredUuid && !items.some((item) => item.uuid === preferredUuid)) {
          const option = document.createElement('option');
          option.value = preferredUuid;
          option.textContent = selectedLabel || preferredMeta?.title || preferredUuid;
          setOptionMeta(option, { ...preferredMeta, uuid: preferredUuid, title: selectedLabel || preferredMeta?.title || preferredUuid });
          select.prepend(option);
        }
        if (preferredUuid) select.value = preferredUuid;
        else select.selectedIndex = -1;
        select.dispatchEvent(new Event('change', { bubbles: true }));
      } catch (error) {
        notice(wrapper, error.message, 'error');
      }
    };
    const selectedNode = () => {
      const option = select.selectedOptions?.[0];
      if (!option) return null;
      return {
        uuid: option.value,
        title: option.dataset.nodeTitle || option.textContent || option.value,
        slug: option.dataset.nodeSlug || '',
        node_type: option.dataset.nodeType || '',
        node_subtype: option.dataset.nodeSubtype || '',
      };
    };
    search.addEventListener('input', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => load(search.value.trim()), 180);
    });
    search.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowDown' && select.options.length) {
        event.preventDefault();
        select.focus();
        if (select.selectedIndex < 0) select.selectedIndex = 0;
      }
    });
    select.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') search.focus();
    });
    load(selectedUuid || '', selectedUuid || '', selectedMeta);
    return { wrapper, search, select, create, load, selectedNode };
  }

  function relationConstraintMessages(relationType, fromNode, toNode) {
    const schema = cfg.relationTypes?.[relationType];
    if (!schema) return [];
    const messages = [];
    const nodeTypeLabel = (type) => cfg.nodeTypes?.[type]?.label || type;
    const subtypeLabel = (type, subtype) => cfg.nodeTypes?.[type]?.subtypes?.[subtype] || subtype;
    if (fromNode && schema.source_type && fromNode.node_type && schema.source_type !== fromNode.node_type) {
      messages.push(`Source should be ${nodeTypeLabel(schema.source_type)}; selected ${nodeTypeLabel(fromNode.node_type)}.`);
    }
    if (fromNode && schema.source_subtype && fromNode.node_subtype && schema.source_subtype !== fromNode.node_subtype) {
      messages.push(`Source subtype should be ${subtypeLabel(schema.source_type, schema.source_subtype)}; selected ${subtypeLabel(fromNode.node_type, fromNode.node_subtype)}.`);
    }
    if (toNode && schema.target_type && toNode.node_type && schema.target_type !== toNode.node_type) {
      messages.push(`Target should be ${nodeTypeLabel(schema.target_type)}; selected ${nodeTypeLabel(toNode.node_type)}.`);
    }
    if (toNode && schema.target_subtype && toNode.node_subtype && schema.target_subtype !== toNode.node_subtype) {
      messages.push(`Target subtype should be ${subtypeLabel(schema.target_type, schema.target_subtype)}; selected ${subtypeLabel(toNode.node_type, toNode.node_subtype)}.`);
    }
    return messages;
  }

  function openRelationDialog(state, relation, options = {}) {
    const current = clone(relation || options.seed || { uuid: uuid(), from_node_uuid: '', to_node_uuid: '', relation_type: '', label: '', inverse_label: '', direction: 'directed', intensity: 1, meta: {} });
    if (!current.uuid) current.uuid = uuid();
    if (options.fromNode && !current.from_node_uuid) {
      current.from_node_uuid = options.fromNode.uuid;
      current.from_title = nodeTitle(options.fromNode);
      current.from_slug = options.fromNode.slug || '';
      current.from_type = options.fromNode.node_type || '';
      current.from_subtype = options.fromNode.node_subtype || '';
    }

    const modal = makeDialog(options.title || (relation ? 'Edit relation' : 'Add relation'));
    const form = document.createElement('form');
    form.className = 'viswiz-dialog-form';
    const pickerGrid = document.createElement('div');
    pickerGrid.className = 'viswiz-form-grid';
    const fromPicker = nodePicker(
      state,
      'From',
      'from_node_uuid',
      current.from_node_uuid,
      current.from_title || current.from_slug || 'Current source',
      { title: current.from_title || current.from_slug || '', slug: current.from_slug || '', node_type: current.from_type || '', node_subtype: current.from_subtype || '' }
    );
    const toPicker = nodePicker(
      state,
      'To',
      'to_node_uuid',
      current.to_node_uuid,
      current.to_title || current.to_slug || 'Current target',
      { title: current.to_title || current.to_slug || '', slug: current.to_slug || '', node_type: current.to_type || '', node_subtype: current.to_subtype || '' }
    );
    pickerGrid.append(fromPicker.wrapper, toPicker.wrapper);
    form.appendChild(pickerGrid);

    const relationOptions = Object.entries(cfg.relationTypes || {}).map(([key, item]) => `<option value="${esc(key)}" ${current.relation_type === key ? 'selected' : ''}>${esc(item.label || key)}</option>`).join('');
    const rest = document.createElement('div');
    rest.innerHTML = `<label class="viswiz-field"><span>Relation type</span><select name="relation_type"><option value="">Unspecified</option>${relationOptions}</select></label><div class="notice notice-warning inline viswiz-relation-constraint-warning" data-viswiz-relation-constraint hidden><p></p></div><div class="viswiz-form-grid">${field('Label', 'label', current.label)}${field('Inverse label', 'inverse_label', current.inverse_label)}</div><div class="viswiz-form-grid"><label class="viswiz-field"><span>Direction</span><select name="direction">${['directed', 'bidirectional', 'undirected'].map((direction) => `<option ${current.direction === direction ? 'selected' : ''}>${direction}</option>`).join('')}</select></label>${field('Intensity', 'intensity', current.intensity, 'number', 'step="0.1" min="0.1" max="20"')}</div>${textareaField('Metadata JSON', 'meta', JSON.stringify(current.meta || {}, null, 2), 5)}<div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>Cancel</button><button type="submit" class="button button-primary">Save relation</button></div>`;
    while (rest.firstChild) form.appendChild(rest.firstChild);
    modal.body.appendChild(form);
    document.body.appendChild(modal.dialog);
    modal.dialog.showModal();
    fromPicker.search.focus();

    const type = $('[name="relation_type"]', form);
    const constraint = $('[data-viswiz-relation-constraint]', form);
    const updateConstraintWarning = () => {
      const messages = relationConstraintMessages(type.value, fromPicker.selectedNode(), toPicker.selectedNode());
      constraint.hidden = messages.length === 0;
      $('p', constraint).textContent = messages.join(' ');
    };
    fromPicker.select.addEventListener('change', updateConstraintWarning);
    toPicker.select.addEventListener('change', updateConstraintWarning);
    type.addEventListener('change', () => {
      const metadata = cfg.relationTypes?.[type.value];
      if (metadata && !relation) {
        $('[name="label"]', form).value = metadata.label || '';
        $('[name="inverse_label"]', form).value = metadata.inverse_label || '';
        $('[name="direction"]', form).value = metadata.direction || 'directed';
        $('[name="intensity"]', form).value = metadata.intensity || 1;
      }
      updateConstraintWarning();
    });

    const quickCreate = (picker, side) => {
      picker.create.addEventListener('click', () => {
        openNodeDialog(state, null, {
          title: `Create ${side.toLowerCase()} node`,
          onSaved: async (created) => {
            await picker.load(created.title, created.uuid, created);
            picker.search.value = created.title || '';
            updateConstraintWarning();
          },
        });
      });
    };
    quickCreate(fromPicker, 'From');
    quickCreate(toPicker, 'To');

    $('[data-cancel]', form).addEventListener('click', () => modal.dialog.close());
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = new FormData(form);
      if (!fd.get('from_node_uuid') || !fd.get('to_node_uuid')) {
        notice(modal.body, 'Choose both relation endpoints.', 'error');
        return;
      }
      let meta = {};
      try { meta = JSON.parse(fd.get('meta') || '{}'); } catch (_) { notice(modal.body, 'Metadata JSON is invalid.', 'error'); return; }
      const data = {
        uuid: current.uuid,
        from_node_uuid: fd.get('from_node_uuid'),
        to_node_uuid: fd.get('to_node_uuid'),
        relation_type: fd.get('relation_type'),
        label: fd.get('label'),
        inverse_label: fd.get('inverse_label'),
        direction: fd.get('direction'),
        intensity: Number(fd.get('intensity') || 1),
        meta,
      };
      if (await mutate(state, `/datasets/${state.id}/editor/relations`, 'POST', { relation: data }, ['nodes', 'relations'], { errorRoot: modal.body })) {
        if (typeof options.onSaved === 'function') await options.onSaved(data);
        modal.dialog.close();
      }
    });
    window.setTimeout(updateConstraintWarning, 0);
  }

  function bindSideActions(state) {
    const importButton = $('[data-viswiz-import-button]');
    const importText = $('[data-viswiz-import-json]');
    if (importButton && importText) {
      importButton.addEventListener('click', async () => {
        let value;
        try { value = JSON.parse(importText.value); } catch (_) { notice(state.root, 'Invalid JSON.', 'error'); return; }
        try {
          const payload = value.payload || value;
          await request(`/datasets/${state.id}`, { method: 'POST', body: { payload, note: 'JSON import', expected_revision: state.revision } });
          window.location.reload();
        } catch (error) { notice(state.root, error.message, 'error'); }
      });
    }

    $$('[data-viswiz-restore-revision]').forEach((restore) => restore.addEventListener('click', async () => {
      const revision = Number(restore.dataset.viswizRestoreRevision || 0);
      if (!window.confirm(`Restore revision ${revision}? The current state will remain in history.`)) return;
      try {
        await request(`/datasets/${state.id}/revisions/${revision}/restore`, { method: 'POST', body: { expected_revision: state.revision } });
        window.location.reload();
      } catch (error) { notice(state.root, error.message, 'error'); }
    }));

    const snapshot = $('[data-viswiz-commerce-snapshot]');
    if (snapshot) snapshot.addEventListener('click', async () => {
      const config = {};
      $$('[data-viswiz-woo]').forEach((input) => {
        const key = input.dataset.viswizWoo;
        config[key] = input.type === 'checkbox' ? input.checked : input.value;
      });
      if (config.product_ids) config.product_ids = String(config.product_ids).split(',').map(Number).filter(Boolean);
      if (config.category_ids) config.category_ids = String(config.category_ids).split(',').map(Number).filter(Boolean);
      try {
        await request(`/datasets/${state.id}/commerce-snapshot`, { method: 'POST', body: { config, expected_revision: state.revision } });
        window.location.reload();
      } catch (error) { notice(state.root, error.message, 'error'); }
    });
  }

  async function init() {
    const root = $('#viswiz-dataset-editor[data-viswiz-server-editor]');
    if (!root || !cfg.restUrl) return;
    const state = {
      root,
      id: Number(root.dataset.datasetId || 0),
      schema: root.dataset.schema || 'categorical',
      revision: Number(root.dataset.revision || 0),
      search: '',
      saving: false,
      rows: collectionState(),
      nodes: collectionState(),
      relations: collectionState(),
    };
    root.__viswizServerState = state;
    bindSideActions(state);

    const search = $('[data-viswiz-dataset-search]');
    let searchTimer = 0;
    if (search) search.addEventListener('input', () => {
      window.clearTimeout(searchTimer);
      searchTimer = window.setTimeout(async () => {
        state.search = search.value.trim();
        state.rows.page = 1;
        state.nodes.page = 1;
        state.relations.page = 1;
        await refresh(state);
      }, 220);
    });
    await refresh(state);
  }

  init();
})();