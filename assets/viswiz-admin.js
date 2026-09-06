(() => {
  'use strict';
  const { __, sprintf } = window.wp.i18n;

  const cfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const uuid = () => (window.crypto?.randomUUID ? window.crypto.randomUUID() : `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx`.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }));
  const esc = (value) => String(value ?? '').replace(/[&<>'"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;' }[c]));

  async function api(path, options = {}) {
    const response = await fetch(`${cfg.restUrl}${path}`, {
      credentials: 'same-origin',
      method: options.method || 'GET',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce || '' },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data?.code) {
      const error = new Error(data?.message || sprintf(__('The request failed with HTTP status %d.', 'viswiz'), response.status));
      error.data = data?.data || {};
      error.code = data?.code || '';
      throw error;
    }
    return data;
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

  function initVisualizationConfig() {
    const root = $('[data-viswiz-visualization-config]');
    if (!root) return;
    const source = $('[data-viswiz-source]', root);
    const renderer = $('[data-viswiz-renderer]', root);
    const dataset = $('[data-viswiz-dataset-select]', root);

    const refresh = () => {
      $$('[data-viswiz-source-panel]', root).forEach((panel) => { panel.hidden = panel.dataset.viswizSourcePanel !== source.value; });
      const rendererOption = renderer.selectedOptions[0];
      const allowed = new Set(String(rendererOption?.dataset.schemas || '').split(',').filter(Boolean));
      [...dataset.options].forEach((option, index) => { if (index) option.hidden = !allowed.has(option.dataset.schema); });
      if (dataset.selectedOptions[0]?.hidden) dataset.value = '0';
      const invalidWoo = ['graph', 'flow_diagram', 'org_chart', 'map', 'scatter', 'diagram'].includes(renderer.value);
      const wooOption = [...source.options].find((o) => o.value === 'woo_live');
      if (wooOption) wooOption.disabled = invalidWoo;
      if (invalidWoo && source.value === 'woo_live') source.value = 'dataset';
      $$('[data-viswiz-source-panel]', root).forEach((panel) => { panel.hidden = panel.dataset.viswizSourcePanel !== source.value; });
    };
    source.addEventListener('change', refresh); renderer.addEventListener('change', refresh); refresh();
  }

  function initConfirmLinks() {
    $$('[data-viswiz-confirm]').forEach((link) => link.addEventListener('click', (event) => {
      if (!window.confirm(__('Delete this dataset and detach its visualizations?', 'viswiz'))) event.preventDefault();
    }));
  }

  function initDatasetEditor() {
    const root = $('#viswiz-dataset-editor');
    const script = $('#viswiz-dataset-payload');
    if (!root || !script) return;
    let payload = {};
    try { payload = JSON.parse(script.textContent || '{}'); } catch (_) { payload = {}; }
    const state = {
      id: Number(root.dataset.datasetId || 0),
      schema: root.dataset.schema || 'categorical',
      revision: Number(root.dataset.revision || 0),
      payload,
      query: '',
      pages: { rows: 0, nodes: 0, relations: 0 },
      saving: false,
    };
    const search = $('[data-viswiz-dataset-search]');
    if (search) search.addEventListener('input', () => { state.query = search.value.trim().toLowerCase(); state.pages = { rows: 0, nodes: 0, relations: 0 }; renderEditor(root, state); });
    renderEditor(root, state);
    bindImportAndRevisions(root, state);
    bindCommerceSnapshot(root, state);
    renderInlinePreview(state);
  }

  function setResponse(state, response) {
    state.payload = response.payload || state.payload;
    state.revision = Number(response.revision || response.dataset?.revision || state.revision);
    const root = $('#viswiz-dataset-editor');
    if (root) root.dataset.revision = String(state.revision);
    const heading = document.querySelector('.viswiz-admin-wrap h1 small');
    if (heading) heading.textContent = `r${state.revision}`;
    renderInlinePreview(state);
  }

  async function mutate(root, state, path, method, body) {
    if (state.saving) return null;
    state.saving = true;
    root.classList.add('is-saving');
    try {
      const response = await api(path, { method, body: { ...body, expected_revision: state.revision } });
      setResponse(state, response);
      notice(root, __('Saved.', 'viswiz'));
      return response;
    } catch (error) {
      const message = error.code === 'viswiz_revision_conflict' ? (__('This dataset changed in another editor. Reload before saving.', 'viswiz')) : error.message;
      notice(root, message, 'error');
      if (error.code === 'viswiz_revision_conflict') root.classList.add('has-conflict');
      return null;
    } finally {
      state.saving = false;
      root.classList.remove('is-saving');
    }
  }

  function renderEditor(root, state) {
    root.replaceChildren();
    if (state.schema === 'graph') renderGraphEditor(root, state); else renderRowsEditor(root, state);
  }

  function filterText(item) {
    return Object.values(item || {}).map((value) => typeof value === 'object' ? JSON.stringify(value) : String(value ?? '')).join(' ').toLowerCase();
  }

  const EDITOR_PAGE_SIZE = 100;

  function pageSlice(items, page) {
    const maxPage = Math.max(0, Math.ceil(items.length / EDITOR_PAGE_SIZE) - 1);
    const safePage = Math.max(0, Math.min(Number(page || 0), maxPage));
    return { page: safePage, maxPage, items: items.slice(safePage * EDITOR_PAGE_SIZE, (safePage + 1) * EDITOR_PAGE_SIZE) };
  }

  function appendPager(root, total, pageInfo, onChange, noun) {
    if (total <= EDITOR_PAGE_SIZE) return;
    const pager = document.createElement('div'); pager.className = 'viswiz-editor-pager';
    const previous = button(__('Previous', 'viswiz'), 'button button-small'); const next = button(__('Next', 'viswiz'), 'button button-small');
    previous.disabled = pageInfo.page <= 0; next.disabled = pageInfo.page >= pageInfo.maxPage;
    pager.append(previous, statusText(sprintf(__('Page %1$d / %2$d · %3$d %4$s', 'viswiz'), pageInfo.page + 1, pageInfo.maxPage + 1, total, noun)), next);
    previous.addEventListener('click', () => onChange(pageInfo.page - 1)); next.addEventListener('click', () => onChange(pageInfo.page + 1));
    root.appendChild(pager);
  }

  function renderRowsEditor(root, state) {
    const rows = Array.isArray(state.payload.rows) ? state.payload.rows : [];
    const visible = state.query ? rows.filter((row) => filterText(row).includes(state.query)) : rows;
    const pageInfo = pageSlice(visible, state.pages.rows); state.pages.rows = pageInfo.page;
    const bar = document.createElement('div'); bar.className = 'viswiz-editor-toolbar';
    const add = button(__('Add row', 'viswiz'), 'button button-primary'); bar.append(add, statusText(sprintf(__('%1$d / %2$d rows · revision %3$d', 'viswiz'), visible.length, rows.length, state.revision))); root.appendChild(bar);
    const table = document.createElement('table'); table.className = 'widefat striped viswiz-table';
    table.innerHTML = `<thead><tr><th>${__('Label', 'viswiz')}</th><th>${__('Value', 'viswiz')}</th><th>${__('X/date', 'viswiz')}</th><th>${__('Y', 'viswiz')}</th><th>${__('Lat', 'viswiz')}</th><th>${__('Lng', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;
    const tbody = $('tbody', table);
    pageInfo.items.forEach((row) => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td><strong>${esc(row.label || row.row_key || __('Untitled', 'viswiz'))}</strong></td><td>${esc(row.value ?? '')}</td><td>${esc(row.x_value ?? row.x_numeric ?? '')}</td><td>${esc(row.y_value ?? '')}</td><td>${esc(row.latitude ?? '')}</td><td>${esc(row.longitude ?? '')}</td><td class="viswiz-row-actions"></td>`;
      const edit = button(__('Edit', 'viswiz'), 'button button-small'), del = button(__('Delete', 'viswiz'), 'button-link-delete');
      $('.viswiz-row-actions', tr).append(edit, document.createTextNode(' '), del);
      edit.addEventListener('click', () => openRowDialog(root, state, row));
      del.addEventListener('click', async () => { if (!confirmDelete()) return; const res = await mutate(root, state, `/datasets/${state.id}/rows/${row.uuid}`, 'DELETE', {}); if (res) renderEditor(root, state); });
      tbody.appendChild(tr);
    });
    root.appendChild(table);
    appendPager(root, visible.length, pageInfo, (page) => { state.pages.rows = page; renderEditor(root, state); }, __('rows', 'viswiz'));
    add.addEventListener('click', () => openRowDialog(root, state, null));
  }

  function openRowDialog(root, state, row) {
    const current = row || { uuid: uuid(), label: '', row_key: '', value: '', x_value: '', x_numeric: '', y_value: '', latitude: '', longitude: '', color: '', meta: {} };
    const dialog = makeDialog(row ? __('Edit row', 'viswiz') : __('Add row', 'viswiz'));
    const form = document.createElement('form'); form.method = 'dialog'; form.className = 'viswiz-dialog-form';
    form.innerHTML = `
      ${field(__('Label', 'viswiz'),'label',current.label)} ${field(__('Key', 'viswiz'),'row_key',current.row_key)}
      <div class="viswiz-form-grid">${field(__('Value', 'viswiz'),'value',current.value,'number','step="any"')}${field(__('X / date', 'viswiz'),'x_value',current.x_value)}${field(__('X numeric', 'viswiz'),'x_numeric',current.x_numeric,'number','step="any"')}${field(__('Y', 'viswiz'),'y_value',current.y_value,'number','step="any"')}</div>
      <div class="viswiz-form-grid">${field(__('Latitude', 'viswiz'),'latitude',current.latitude,'number','step="any" min="-90" max="90"')}${field(__('Longitude', 'viswiz'),'longitude',current.longitude,'number','step="any" min="-180" max="180"')}${field(__('Color', 'viswiz'),'color',current.color || '#2563eb','color')}</div>
      ${textareaField(__('Metadata JSON', 'viswiz'),'meta',JSON.stringify(current.meta || {},null,2),5)}
      <div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>${__('Cancel', 'viswiz')}</button><button type="submit" class="button button-primary">${__('Save row', 'viswiz')}</button></div>`;
    dialog.body.appendChild(form); document.body.appendChild(dialog.dialog); dialog.dialog.showModal();
    $('[data-cancel]', form).addEventListener('click', () => dialog.dialog.close());
    form.addEventListener('submit', async (event) => {
      event.preventDefault(); const fd = new FormData(form); let meta = {}; try { meta = JSON.parse(fd.get('meta') || '{}'); } catch (_) { notice(dialog.body,__('Metadata JSON is invalid.', 'viswiz'),'error'); return; }
      const data = { uuid: current.uuid, label: fd.get('label'), row_key: fd.get('row_key'), value: nullable(fd.get('value')), x_value: fd.get('x_value'), x_numeric: nullable(fd.get('x_numeric')), y_value: nullable(fd.get('y_value')), latitude: nullable(fd.get('latitude')), longitude: nullable(fd.get('longitude')), color: fd.get('color'), meta };
      const res = await mutate(root, state, `/datasets/${state.id}/rows`, 'POST', { row: data }); if (res) { dialog.dialog.close(); renderEditor(root, state); }
    }); dialog.dialog.addEventListener('close', () => dialog.dialog.remove());
  }

  function renderGraphEditor(root, state) {
    const nodes = Array.isArray(state.payload.nodes) ? state.payload.nodes : [];
    const relations = Array.isArray(state.payload.relations) ? state.payload.relations : [];
    const visibleNodes = state.query ? nodes.filter((node) => filterText(node).includes(state.query)) : nodes;
    const visibleIds = new Set(visibleNodes.map((node) => node.uuid));
    const visibleRelations = state.query ? relations.filter((rel) => visibleIds.has(rel.from_node_uuid) || visibleIds.has(rel.to_node_uuid) || filterText(rel).includes(state.query)) : relations;
    const nodePage = pageSlice(visibleNodes, state.pages.nodes); state.pages.nodes = nodePage.page;
    const relationPage = pageSlice(visibleRelations, state.pages.relations); state.pages.relations = relationPage.page;
    const nodeMap = new Map(nodes.map((node) => [node.uuid, node]));
    const bar = document.createElement('div'); bar.className = 'viswiz-editor-toolbar';
    const addNode = button(__('Add node', 'viswiz'), 'button button-primary'), addRelation = button(__('Add relation', 'viswiz'), 'button');
    bar.append(addNode, addRelation, statusText(sprintf(__('%1$d/%2$d nodes · %3$d/%4$d relations · revision %5$d', 'viswiz'), visibleNodes.length, nodes.length, visibleRelations.length, relations.length, state.revision))); root.appendChild(bar);

    const headingNodes = document.createElement('h3'); headingNodes.textContent = __('Nodes', 'viswiz'); root.appendChild(headingNodes);
    const table = document.createElement('table'); table.className = 'widefat striped viswiz-table'; table.innerHTML = `<thead><tr><th>${__('Node', 'viswiz')}</th><th>${__('Type', 'viswiz')}</th><th>${__('Slug', 'viswiz')}</th><th>${__('Degree', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;
    const tbody = $('tbody', table);
    const degree = new Map(nodes.map((n) => [n.uuid, 0])); relations.forEach((r) => { if (degree.has(r.from_node_uuid)) degree.set(r.from_node_uuid, degree.get(r.from_node_uuid)+1); if (degree.has(r.to_node_uuid)) degree.set(r.to_node_uuid, degree.get(r.to_node_uuid)+1); });
    nodePage.items.forEach((node) => {
      const tr = document.createElement('tr'); tr.innerHTML = `<td><strong>${esc(node.title || node.label || node.slug)}</strong></td><td>${esc(node.node_type || '')}${node.node_subtype ? ` / ${esc(node.node_subtype)}` : ''}</td><td><code>${esc(node.slug || '')}</code></td><td>${esc(degree.get(node.uuid) || 0)}</td><td class="viswiz-row-actions"></td>`;
      const edit = button(__('Edit', 'viswiz'),'button button-small'), del = button(__('Delete', 'viswiz'),'button-link-delete'); $('.viswiz-row-actions',tr).append(edit,document.createTextNode(' '),del);
      edit.addEventListener('click',()=>openNodeDialog(root,state,node)); del.addEventListener('click',async()=>{if(!confirmDelete())return;const res=await mutate(root,state,`/datasets/${state.id}/nodes/${node.uuid}`,'DELETE',{});if(res)renderEditor(root,state);}); tbody.appendChild(tr);
    }); root.appendChild(table);
    appendPager(root, visibleNodes.length, nodePage, (page) => { state.pages.nodes = page; renderEditor(root, state); }, __('nodes', 'viswiz'));

    const headingRelations = document.createElement('h3'); headingRelations.textContent=__('Relations', 'viswiz'); root.appendChild(headingRelations);
    const rtable=document.createElement('table');rtable.className='widefat striped viswiz-table';rtable.innerHTML=`<thead><tr><th>${__('From', 'viswiz')}</th><th>${__('Relation', 'viswiz')}</th><th>${__('To', 'viswiz')}</th><th>${__('Direction', 'viswiz')}</th><th></th></tr></thead><tbody></tbody>`;
    const rbody=$('tbody',rtable); relationPage.items.forEach((rel)=>{const from=nodeMap.get(rel.from_node_uuid),to=nodeMap.get(rel.to_node_uuid);const tr=document.createElement('tr');tr.innerHTML=`<td>${esc(from?.title||from?.slug||__('Missing', 'viswiz'))}</td><td>${esc(rel.label||rel.relation_type||'')}</td><td>${esc(to?.title||to?.slug||__('Missing', 'viswiz'))}</td><td>${esc(directionLabel(rel.direction || 'directed'))}</td><td class="viswiz-row-actions"></td>`;const edit=button(__('Edit', 'viswiz'),'button button-small'),del=button(__('Delete', 'viswiz'),'button-link-delete');$('.viswiz-row-actions',tr).append(edit,document.createTextNode(' '),del);edit.addEventListener('click',()=>openRelationDialog(root,state,rel));del.addEventListener('click',async()=>{if(!confirmDelete())return;const res=await mutate(root,state,`/datasets/${state.id}/relations/${rel.uuid}`,'DELETE',{});if(res)renderEditor(root,state);});rbody.appendChild(tr);}); root.appendChild(rtable);
    appendPager(root, visibleRelations.length, relationPage, (page) => { state.pages.relations = page; renderEditor(root, state); }, __('relations', 'viswiz'));
    addNode.addEventListener('click',()=>openNodeDialog(root,state,null)); addRelation.addEventListener('click',()=>openRelationDialog(root,state,null));
  }

  function openNodeDialog(root,state,node){
    const current=node||{uuid:uuid(),slug:'',title:'',label:'',node_type:'',node_subtype:'',description:'',main_image_id:0,other_image_ids:[],meta:{}};
    const dialog=makeDialog(node?__('Edit node', 'viswiz'):__('Add node', 'viswiz'));const form=document.createElement('form');form.className='viswiz-dialog-form';
    const typeOptions=Object.entries(cfg.nodeTypes||{}).map(([key,item])=>`<option value="${esc(key)}" ${current.node_type===key?'selected':''}>${esc(item.label||key)}</option>`).join('');
    form.innerHTML=`${field(__('Title', 'viswiz'),'title',current.title)}<div class="viswiz-form-grid">${field(__('Slug', 'viswiz'),'slug',current.slug)}${field(__('Label', 'viswiz'),'label',current.label)}</div><div class="viswiz-form-grid"><label class="viswiz-field"><span>${__('Node type', 'viswiz')}</span><select name="node_type"><option value="">${__('Select type', 'viswiz')}</option>${typeOptions}</select></label><label class="viswiz-field"><span>${__('Subtype', 'viswiz')}</span><select name="node_subtype"></select></label></div>${textareaField(__('Description (safe HTML)', 'viswiz'),'description',current.description||current.description_html||'',7)}<div class="viswiz-form-grid">${field(__('Featured image ID', 'viswiz'),'main_image_id',current.main_image_id,'number','min="0"')}${field(__('Other image IDs', 'viswiz'),'other_image_ids',(current.other_image_ids||[]).join(','))}</div>${textareaField(__('Metadata JSON', 'viswiz'),'meta',JSON.stringify(current.meta||{},null,2),5)}<div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>${__('Cancel', 'viswiz')}</button><button type="submit" class="button button-primary">${__('Save node', 'viswiz')}</button></div>`;
    dialog.body.appendChild(form);document.body.appendChild(dialog.dialog);dialog.dialog.showModal();
    const mainImage=$('[name=main_image_id]',form),otherImages=$('[name=other_image_ids]',form);
    if(window.wp?.media&&mainImage&&otherImages){
      const mainButton=button(__('Choose featured image', 'viswiz'),'button');mainButton.classList.add('viswiz-media-button');mainImage.insertAdjacentElement('afterend',mainButton);
      mainButton.addEventListener('click',()=>{const frame=wp.media({title:__('Choose featured image', 'viswiz'),multiple:false,library:{type:'image'}});frame.on('select',()=>{const item=frame.state().get('selection').first()?.toJSON();if(item)mainImage.value=String(item.id||0);});frame.open();});
      const otherButton=button(__('Choose other images', 'viswiz'),'button');otherButton.classList.add('viswiz-media-button');otherImages.insertAdjacentElement('afterend',otherButton);
      otherButton.addEventListener('click',()=>{const frame=wp.media({title:__('Choose node images', 'viswiz'),multiple:true,library:{type:'image'}});frame.on('select',()=>{otherImages.value=frame.state().get('selection').map((item)=>item.toJSON().id).filter(Boolean).join(',');});frame.open();});
    }
    const type=$('[name=node_type]',form),sub=$('[name=node_subtype]',form);const refreshSubtype=()=>{const selected=current.node_subtype||sub.value;const entries=cfg.nodeTypes?.[type.value]?.subtypes||{};sub.innerHTML=`<option value="">${__('No subtype', 'viswiz')}</option>`+Object.entries(entries).map(([k,v])=>`<option value="${esc(k)}" ${selected===k?'selected':''}>${esc(v)}</option>`).join('');};type.addEventListener('change',()=>{current.node_subtype='';refreshSubtype();});refreshSubtype();
    $('[data-cancel]',form).addEventListener('click',()=>dialog.dialog.close());form.addEventListener('submit',async(event)=>{event.preventDefault();const fd=new FormData(form);let meta={};try{meta=JSON.parse(fd.get('meta')||'{}');}catch(_){notice(dialog.body,__('Metadata JSON is invalid.', 'viswiz'),'error');return;}const data={uuid:current.uuid,title:fd.get('title'),slug:fd.get('slug'),label:fd.get('label'),node_type:fd.get('node_type'),node_subtype:fd.get('node_subtype'),description:fd.get('description'),main_image_id:Number(fd.get('main_image_id')||0),other_image_ids:String(fd.get('other_image_ids')||'').split(',').map(Number).filter(Boolean),meta};const res=await mutate(root,state,`/datasets/${state.id}/nodes`,'POST',{node:data});if(res){dialog.dialog.close();renderEditor(root,state);}});dialog.dialog.addEventListener('close',()=>dialog.dialog.remove());
  }

  function directionLabel(value){const labels={directed:__('Directed','viswiz'),bidirectional:__('Bidirectional','viswiz'),undirected:__('Undirected','viswiz')};return labels[value]||value||labels.directed;}

  function openRelationDialog(root,state,rel){
    const nodes=state.payload.nodes||[];if(nodes.length<2){notice(root,__('Create at least two nodes first.', 'viswiz'),'error');return;}const current=rel||{uuid:uuid(),from_node_uuid:nodes[0].uuid,to_node_uuid:nodes[1].uuid,relation_type:'',label:'',inverse_label:'',direction:'directed',intensity:1,meta:{}};
    const dialog=makeDialog(rel?__('Edit relation', 'viswiz'):__('Add relation', 'viswiz'));const form=document.createElement('form');form.className='viswiz-dialog-form';const nodeOptions=(selected)=>nodes.map((n)=>`<option value="${esc(n.uuid)}" ${selected===n.uuid?'selected':''}>${esc(n.title||n.slug)}</option>`).join('');const relationOptions=Object.entries(cfg.relationTypes||{}).map(([key,item])=>`<option value="${esc(key)}" ${current.relation_type===key?'selected':''}>${esc(item.label||key)}</option>`).join('');
    form.innerHTML=`<div class="viswiz-form-grid"><label class="viswiz-field"><span>${__('From', 'viswiz')}</span><select name="from_node_uuid">${nodeOptions(current.from_node_uuid)}</select></label><label class="viswiz-field"><span>${__('To', 'viswiz')}</span><select name="to_node_uuid">${nodeOptions(current.to_node_uuid)}</select></label></div><label class="viswiz-field"><span>${__('Relation type', 'viswiz')}</span><select name="relation_type"><option value="">${__('Unspecified', 'viswiz')}</option>${relationOptions}</select></label><div class="viswiz-form-grid">${field(__('Label', 'viswiz'),'label',current.label)}${field(__('Inverse label', 'viswiz'),'inverse_label',current.inverse_label)}</div><div class="viswiz-form-grid"><label class="viswiz-field"><span>${__('Direction', 'viswiz')}</span><select name="direction">${['directed','bidirectional','undirected'].map((d)=>`<option value="${d}" ${current.direction===d?'selected':''}>${directionLabel(d)}</option>`).join('')}</select></label>${field(__('Intensity', 'viswiz'),'intensity',current.intensity,'number','step="0.1" min="0.1" max="20"')}</div>${textareaField(__('Metadata JSON', 'viswiz'),'meta',JSON.stringify(current.meta||{},null,2),5)}<div class="viswiz-dialog-actions"><button type="button" class="button" data-cancel>${__('Cancel', 'viswiz')}</button><button type="submit" class="button button-primary">${__('Save relation', 'viswiz')}</button></div>`;
    dialog.body.appendChild(form);document.body.appendChild(dialog.dialog);dialog.dialog.showModal();const type=$('[name=relation_type]',form);type.addEventListener('change',()=>{const meta=cfg.relationTypes?.[type.value];if(!meta)return;if(!rel){$('[name=label]',form).value=meta.label||'';$('[name=inverse_label]',form).value=meta.inverse_label||'';$('[name=direction]',form).value=meta.direction||'directed';$('[name=intensity]',form).value=meta.intensity||1;}});$('[data-cancel]',form).addEventListener('click',()=>dialog.dialog.close());form.addEventListener('submit',async(event)=>{event.preventDefault();const fd=new FormData(form);let meta={};try{meta=JSON.parse(fd.get('meta')||'{}');}catch(_){notice(dialog.body,__('Metadata JSON is invalid.', 'viswiz'),'error');return;}const data={uuid:current.uuid,from_node_uuid:fd.get('from_node_uuid'),to_node_uuid:fd.get('to_node_uuid'),relation_type:fd.get('relation_type'),label:fd.get('label'),inverse_label:fd.get('inverse_label'),direction:fd.get('direction'),intensity:Number(fd.get('intensity')||1),meta};const res=await mutate(root,state,`/datasets/${state.id}/relations`,'POST',{relation:data});if(res){dialog.dialog.close();renderEditor(root,state);}});dialog.dialog.addEventListener('close',()=>dialog.dialog.remove());
  }

  function bindImportAndRevisions(root,state){
    const button=$('[data-viswiz-import-button]');const textarea=$('[data-viswiz-import-json]');if(button&&textarea)button.addEventListener('click',async()=>{let data;try{data=JSON.parse(textarea.value);}catch(_){notice(root,__('Invalid JSON.', 'viswiz'),'error');return;}const payload=data.payload||data;const res=await mutate(root,state,`/datasets/${state.id}`,'POST',{payload,note:__('JSON import', 'viswiz')});if(res)renderEditor(root,state);});
    $$('[data-viswiz-restore-revision]').forEach((button)=>button.addEventListener('click',async()=>{const revision=Number(button.dataset.viswizRestoreRevision);if(!window.confirm(sprintf(__('Restore revision %d? The current state will remain in history.', 'viswiz'), revision)))return;const res=await mutate(root,state,`/datasets/${state.id}/revisions/${revision}/restore`,'POST',{});if(res){renderEditor(root,state);window.location.reload();}}));
  }

  function bindCommerceSnapshot(root,state){
    const button=$('[data-viswiz-commerce-snapshot]');if(!button)return;button.addEventListener('click',async()=>{if(state.schema==='graph'){notice(root,__('Graph datasets cannot receive WooCommerce row snapshots.', 'viswiz'),'error');return;}const config={};$$('[data-viswiz-woo]').forEach((field)=>{const key=field.dataset.viswizWoo;if(field.type==='checkbox')config[key]=field.checked;else config[key]=field.value;});if(config.product_ids)config.product_ids=String(config.product_ids).split(',').map(Number).filter(Boolean);if(config.category_ids)config.category_ids=String(config.category_ids).split(',').map(Number).filter(Boolean);const res=await mutate(root,state,`/datasets/${state.id}/commerce-snapshot`,'POST',{config});if(res)renderEditor(root,state);});
  }

  function renderInlinePreview(state){
    const container=$('[data-viswiz-inline-spec]');if(!container||!window.VisWiz||state.schema!=='graph')return;window.VisWiz.render(container,{id:`dataset-${state.id}`,title:'',renderer:'graph',schema:'graph',source_type:'dataset',settings:{primary_color:'#2563eb',secondary_color:'#64748b',text_color:'#111827',background_color:'#fff',show_graph_toolbar:true,show_relation_labels:true,full_screen:false},data:state.payload,meta:{}});
  }

  function makeDialog(title){const dialog=document.createElement('dialog');dialog.className='viswiz-editor-dialog';const body=document.createElement('div');body.className='viswiz-editor-dialog-body';const head=document.createElement('div');head.className='viswiz-dialog-heading';head.innerHTML=`<h2>${esc(title)}</h2>`;const close=button('×','viswiz-dialog-close');close.type='button';close.setAttribute('aria-label',__('Close', 'viswiz'));close.addEventListener('click',()=>dialog.close());head.appendChild(close);body.appendChild(head);dialog.appendChild(body);return{dialog,body};}
  function button(text,className='button'){const b=document.createElement('button');b.type='button';b.className=className;b.textContent=text;return b;}
  function statusText(text){const s=document.createElement('span');s.className='viswiz-editor-status';s.textContent=text;return s;}
  function field(label,name,value='',type='text',extra=''){return `<label class="viswiz-field"><span>${esc(label)}</span><input type="${esc(type)}" name="${esc(name)}" value="${esc(value ?? '')}" ${extra}></label>`;}
  function textareaField(label,name,value='',rows=4){return `<label class="viswiz-field"><span>${esc(label)}</span><textarea name="${esc(name)}" rows="${rows}">${esc(value)}</textarea></label>`;}
  function nullable(value){return value===''||value===null?null:Number(value);}
  function confirmDelete(){return window.confirm(__('Delete this item?', 'viswiz'));}

  initVisualizationConfig(); initConfirmLinks(); initDatasetEditor();
})();
