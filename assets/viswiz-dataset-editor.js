(() => {
  'use strict';

  const cfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c]));
  const uuid = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
  const PAGE_SIZE = 100;

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
    return value === '' || value === null ? null : Number(value);
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

  async function mutate(state, path, method, body, refreshKinds) {
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
      notice(state.root, message, 'error');
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
    const bar = document.createElement('div');
    bar.className = 'viswiz-editor-toolbar viswiz-server-status';
    const add = button('Add row', 'button button-primary');
    bar.append(add, statusText(`${collection.total} rows · revision ${state.revision}`), statusText('Server paged'));
    state.root.appendChild(bar);

    const table = document.createElement('table');
    table.className = 'widefat striped viswiz-table';
    table.innerHTML = '<thead><tr><th>Label</th><th>Value</th><th>X/date</th><th>Y</th><th>Lat</th><th>Lng</th><th></th></tr></thead><tbody></tbody>';
    const tbody = $('tbody', table);
    if (!collection.items.length) {
      tbody.innerHTML = '<tr><td colspan="7">No rows found.</td></tr>';
    }
    collection.items.forEach((row) => {
      const tr = document.createElement('tr');
      tr.dataset.viswizItemUuid = row.uuid;
      tr.innerHTML = `<td><strong>${esc(row.label || row.row_key || 'Untitled')}</strong></td><td>${esc(row.value ?? '')}</td><td>${esc(row.x_value ?? row.x_numeric ?? '')}</td><td>${esc(row.y_value ?? '')}</td><td>${esc(row.latitude ?? '')}</td><td>${esc(row.longitude ?? '')}</td><td class="viswiz-row-actions"></td>`;
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
    appendPager(state.root, collection, 'rows', async (page) => { await loadCollection(state, 'rows', page); render(state); });
    add.addEventListener('click', () => openRowDialog(state, null));
  }

  function openRowDialog(state, row) {
    const current = row || { uuid: uuid(), label: '', row_key: '', value: '', x_value: '', x_numeric: '', y_value: '', latitude: '', longitude: '', color: '', meta: {} };
    const modal = makeDialog(row ? 'Edit row' : 'Add row');
    const form = document.createElement('form');
    form.className = 'viswiz-dialog-form';
    form.innerHTML = `
      ${field('Label', 'label', current.label)} ${field('Key', 'row_key', current.row_key)}
      <div class="viswiz-form-grid">${field('Value', 'value', current.value, 'number', 'step="any"')}${field('X / date', 'x_value', current.x_value)}${field('X numeric', 'x_numeric', current.x_numeric, 'number', 'step="any"')}${field('Y', 'y_value', current.y_value, 'number', 'step="any"')}</div>
      <div class="viswiz-form-grid">${field('Latitude', 'latitude', current.latitude, 'number', 'step="any" min="-90" max="90"')}${field('Longitude', 'longitude', current.longitude, 'number', 'step="any" min="-180" max="180"')}${field('Color', 'color', current.color || '#2563eb', 'color')}</div>
      ${textareaField('Metadata JSON', 'meta', JSON.stringify(current.meta || {}, null, 2), 5)}
      <div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>Cancel</button><button type="submit" class="button button-primary">Save row</button></div>`;
    modal.body.appendChild(form);
    document.body.appendChild(modal.dialog);
    modal.dialog.showModal();
    $('[name="label"]', form)?.focus();
    $('[data-cancel]', form).addEventListener('click', () => modal.dialog.close());
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const fd = new FormData(form);
      let meta = {};
      try { meta = JSON.parse(fd.get('meta') || '{}'); } catch (_) { notice(modal.body, 'Metadata JSON is invalid.', 'error'); return; }
      const data = {
        uuid: current.uuid,
        label: fd.get('label'),
        row_key: fd.get('row_key'),
        value: nullable(fd.get('value')),
        x_value: fd.get('x_value'),
        x_numeric: nullable(fd.get('x_numeric')),
        y_value: nullable(fd.get('y_value')),
        latitude: nullable(fd.get('latitude')),
        longitude: nullable(fd.get('longitude')),
        color: fd.get('color'),
        meta,
      };
      if (await mutate(state, `/datasets/${state.id}/editor/rows`, 'POST', { row: data }, ['rows'])) modal.dialog.close();
    });
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
      tr.innerHTML = `<td><strong>${esc(node.title || node.label || node.slug)}</strong></td><td>${esc(node.node_type || '')}${node.node_subtype ? ` / ${esc(node.node_subtype)}` : ''}</td><td><code>${esc(node.slug || '')}</code></td><td>${esc(node.degree || 0)}</td><td class="viswiz-row-actions"></td>`;
      const edit = button('Edit', 'button button-small');
      const del = button('Delete', 'button-link-delete');
      $('.viswiz-row-actions', tr).append(edit, document.createTextNode(' '), del);
      edit.addEventListener('click', () => openNodeDialog(state, node));
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
      const del = button('Delete', 'button-link-delete');
      $('.viswiz-row-actions', tr).append(edit, document.createTextNode(' '), del);
      edit.addEventListener('click', () => openRelationDialog(state, rel));
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

  function openNodeDialog(state, node) {
    const current = node || { uuid: uuid(), slug: '', title: '', label: '', node_type: '', node_subtype: '', description: '', main_image_id: 0, other_image_ids: [], meta: {} };
    const modal = makeDialog(node ? 'Edit node' : 'Add node');
    const form = document.createElement('form');
    form.className = 'viswiz-dialog-form';
    const typeOptions = Object.entries(cfg.nodeTypes || {}).map(([key, item]) => `<option value="${esc(key)}" ${current.node_type === key ? 'selected' : ''}>${esc(item.label || key)}</option>`).join('');
    form.innerHTML = `${field('Title', 'title', current.title)}<div class="viswiz-form-grid">${field('Slug', 'slug', current.slug)}${field('Label', 'label', current.label)}</div><div class="viswiz-form-grid"><label class="viswiz-field"><span>Node type</span><select name="node_type"><option value="">Select type</option>${typeOptions}</select></label><label class="viswiz-field"><span>Subtype</span><select name="node_subtype"></select></label></div>${textareaField('Description (safe HTML)', 'description', current.description || current.description_html || '', 7)}<div class="viswiz-form-grid">${field('Featured image ID', 'main_image_id', current.main_image_id, 'number', 'min="0"')}${field('Other image IDs', 'other_image_ids', (current.other_image_ids || []).join(','))}</div>${textareaField('Metadata JSON', 'meta', JSON.stringify(current.meta || {}, null, 2), 5)}<div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>Cancel</button><button type="submit" class="button button-primary">Save node</button></div>`;
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
    $('[data-cancel]', form).addEventListener('click', () => modal.dialog.close());
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
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
      if (await mutate(state, `/datasets/${state.id}/editor/nodes`, 'POST', { node: data }, ['nodes', 'relations'])) modal.dialog.close();
    });
  }

  function nodePicker(state, label, name, selectedUuid, selectedLabel) {
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
    wrapper.append(title, search, select);

    let timer = 0;
    let generation = 0;
    const load = async (term, preferredUuid = '') => {
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
          option.textContent = `${node.title || node.label || node.slug} — ${node.slug}`;
          option.dataset.title = node.title || node.label || node.slug;
          select.appendChild(option);
        });
        if (preferredUuid && !items.some((item) => item.uuid === preferredUuid)) {
          const option = document.createElement('option');
          option.value = preferredUuid;
          option.textContent = selectedLabel || preferredUuid;
          select.prepend(option);
        }
        if (preferredUuid) select.value = preferredUuid;
        else select.selectedIndex = -1;
      } catch (error) {
        notice(wrapper, error.message, 'error');
      }
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
    load(selectedUuid || '', selectedUuid || '');
    return { wrapper, search, select };
  }

  function openRelationDialog(state, relation) {
    if (!relation && state.nodes.total < 2) {
      notice(state.root, 'Create at least two nodes first.', 'error');
      return;
    }
    const current = relation || { uuid: uuid(), from_node_uuid: '', to_node_uuid: '', relation_type: '', label: '', inverse_label: '', direction: 'directed', intensity: 1, meta: {} };
    const modal = makeDialog(relation ? 'Edit relation' : 'Add relation');
    const form = document.createElement('form');
    form.className = 'viswiz-dialog-form';
    const pickerGrid = document.createElement('div');
    pickerGrid.className = 'viswiz-form-grid';
    const fromPicker = nodePicker(state, 'From', 'from_node_uuid', current.from_node_uuid, current.from_title || current.from_slug || 'Current source');
    const toPicker = nodePicker(state, 'To', 'to_node_uuid', current.to_node_uuid, current.to_title || current.to_slug || 'Current target');
    pickerGrid.append(fromPicker.wrapper, toPicker.wrapper);
    form.appendChild(pickerGrid);

    const relationOptions = Object.entries(cfg.relationTypes || {}).map(([key, item]) => `<option value="${esc(key)}" ${current.relation_type === key ? 'selected' : ''}>${esc(item.label || key)}</option>`).join('');
    const rest = document.createElement('div');
    rest.innerHTML = `<label class="viswiz-field"><span>Relation type</span><select name="relation_type"><option value="">Unspecified</option>${relationOptions}</select></label><div class="viswiz-form-grid">${field('Label', 'label', current.label)}${field('Inverse label', 'inverse_label', current.inverse_label)}</div><div class="viswiz-form-grid"><label class="viswiz-field"><span>Direction</span><select name="direction">${['directed', 'bidirectional', 'undirected'].map((direction) => `<option ${current.direction === direction ? 'selected' : ''}>${direction}</option>`).join('')}</select></label>${field('Intensity', 'intensity', current.intensity, 'number', 'step="0.1" min="0.1" max="20"')}</div>${textareaField('Metadata JSON', 'meta', JSON.stringify(current.meta || {}, null, 2), 5)}<div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>Cancel</button><button type="submit" class="button button-primary">Save relation</button></div>`;
    while (rest.firstChild) form.appendChild(rest.firstChild);
    modal.body.appendChild(form);
    document.body.appendChild(modal.dialog);
    modal.dialog.showModal();
    fromPicker.search.focus();

    const type = $('[name="relation_type"]', form);
    type.addEventListener('change', () => {
      const metadata = cfg.relationTypes?.[type.value];
      if (!metadata || relation) return;
      $('[name="label"]', form).value = metadata.label || '';
      $('[name="inverse_label"]', form).value = metadata.inverse_label || '';
      $('[name="direction"]', form).value = metadata.direction || 'directed';
      $('[name="intensity"]', form).value = metadata.intensity || 1;
    });
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
      if (await mutate(state, `/datasets/${state.id}/editor/relations`, 'POST', { relation: data }, ['nodes', 'relations'])) modal.dialog.close();
    });
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
