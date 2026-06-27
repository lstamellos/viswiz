(function ($) {
  const graphNodeSubtypes = (window.VisWizAdmin && VisWizAdmin.nodeSubtypes) || {};
  let autosaveTimer = null;

  function getSubtypeEntries(nodeType) {
    const options = graphNodeSubtypes[nodeType] || [];
    if (Array.isArray(options)) {
      return options;
    }
    return Object.keys(options).map((key) => ({ value: key, label: options[key] }));
  }

  function updateNodeSubtypeOptions(card) {
    const typeSelect = card.querySelector('[data-viswiz-node-type]');
    const subtypeSelect = card.querySelector('[data-viswiz-node-subtype]');
    if (!typeSelect || !subtypeSelect) return;
    const current = subtypeSelect.value;
    const options = getSubtypeEntries(typeSelect.value);
    subtypeSelect.innerHTML = '<option value="">No subtype</option>';
    options.forEach((item) => {
      const option = document.createElement('option');
      option.value = item.value;
      option.textContent = item.label;
      subtypeSelect.appendChild(option);
    });
    const proposed = document.createElement('option');
    proposed.value = 'proposed';
    proposed.textContent = 'Other / proposed subtype';
    subtypeSelect.appendChild(proposed);
    subtypeSelect.value = current && (options.some((item) => item.value === current) || current === 'proposed') ? current : '';
    updateProposedSubtype(card);
  }

  function updateProposedSubtype(card) {
    const subtypeSelect = card.querySelector('[data-viswiz-node-subtype]');
    const proposed = card.querySelector('[data-viswiz-proposed-subtype]');
    if (!subtypeSelect || !proposed) return;
    proposed.hidden = subtypeSelect.value !== 'proposed';
  }


  function getNodeTypeAutosavePayload(card) {
    const formData = new FormData();
    const typeSelect = card.querySelector('[data-viswiz-node-type]');
    const subtypeSelect = card.querySelector('[data-viswiz-node-subtype]');
    if (!window.VisWizAdmin || !VisWizAdmin.ajaxUrl || !VisWizAdmin.postId || !typeSelect || !subtypeSelect) {
      return null;
    }

    const nodeId = card.querySelector('[data-viswiz-node-id]');
    const nodeTitle = card.querySelector('[data-viswiz-node-title]');
    const nodeLabel = card.querySelector('[name$="[label][]"]');
    formData.append('action', 'viswiz_autosave_node_type');
    formData.append('nonce', VisWizAdmin.nonce || '');
    formData.append('post_id', VisWizAdmin.postId);
    formData.append('node_index', card.dataset.nodeIndex || Array.from(card.parentNode.children).indexOf(card));
    formData.append('node_id', nodeId ? nodeId.value : '');
    formData.append('node_title', nodeTitle ? nodeTitle.value : '');
    formData.append('node_label', nodeLabel ? nodeLabel.value : '');
    formData.append('node_type', typeSelect.value);
    formData.append('node_subtype', subtypeSelect.value);
    ['proposed_subtype_label', 'proposed_subtype_reason', 'proposed_subtype_example', 'proposed_subtype_gap', 'proposed_subtype_status'].forEach((key) => {
      const field = card.querySelector(`[name$="[${key}][]"]`);
      formData.append(key, field ? field.value : '');
    });

    return formData;
  }

  function autosaveNodeType(card) {
    const payload = getNodeTypeAutosavePayload(card);
    if (!payload) return;
    card.dataset.viswizTypeAutosave = 'saving';
    window.fetch(VisWizAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload })
      .then((response) => response.json())
      .then((response) => {
        card.dataset.viswizTypeAutosave = response && response.success ? 'saved' : 'error';
      })
      .catch(() => { card.dataset.viswizTypeAutosave = 'error'; });
  }

  function queueNodeTypeAutosave(card) {
    if (!card) return;
    window.clearTimeout(autosaveTimer);
    autosaveTimer = window.setTimeout(() => autosaveNodeType(card), 300);
  }

  function updateVisualizationFields() {
    const type = $('[data-viswiz-type]').val();
    const source = $('[data-viswiz-source]').val() || 'auto';
    const periodMode = $('[data-viswiz-period-mode]').val();
    if (!type) {
      return;
    }
    $('[data-viswiz-types]').each(function () {
      const typeAttr = $(this).attr('data-viswiz-types');
      if (!typeAttr) {
        return;
      }
      const supported = typeAttr.split(',');
      const sourceFilter = $(this).attr('data-viswiz-sources');
      const sources = sourceFilter ? sourceFilter.split(',') : [];
      const matchesType = supported.includes(type);
      const matchesSource = sources.length === 0 || sources.includes(source);
      if (matchesType && matchesSource) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });

    $('[data-viswiz-period]').each(function () {
      const supported = $(this).attr('data-viswiz-period');
      if (!supported || supported === periodMode) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  }

  function addRow(containerId, placeholders, namePrefix, keys, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) {
      return;
    }
    const row = document.createElement('div');
    row.className = 'viswiz-row';
    keys.forEach((key, index) => {
      const input = document.createElement('input');
      const placeholder = placeholders[index];
      input.name = `${namePrefix}[${key}][]`;
      input.className = 'regular-text';
      input.placeholder = placeholder;

      if (options.typeMap && options.typeMap[key]) {
        input.type = options.typeMap[key];
      } else {
        input.type = 'text';
      }

      if (input.type === 'number') {
        input.step = '0.01';
      }

      row.appendChild(input);
    });

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'button viswiz-remove-row';
    remove.textContent = 'Remove';
    row.appendChild(remove);
    container.appendChild(row);
  }

  function addProgressRow(containerId, namePrefix) {
    const container = document.getElementById(containerId);
    if (!container) {
      return;
    }
    const index = getNextProgressIndex(container);
    const row = document.createElement('div');
    row.className = 'viswiz-row';
    row.dataset.progressIndex = index;
    row.innerHTML = `
      <input type="text" name="${namePrefix}[label][]" placeholder="Label" class="regular-text" />
      <input type="number" name="${namePrefix}[value][]" placeholder="Value" step="0.01" />
      <div class="viswiz-targets" data-name-prefix="${namePrefix}">
        <div class="viswiz-target-row">
          <input type="text" name="${namePrefix}[targets][name][${index}][]" placeholder="Target name" class="regular-text" />
          <input type="number" name="${namePrefix}[targets][value][${index}][]" placeholder="Target value" step="0.01" />
          <button type="button" class="button viswiz-remove-target">Remove</button>
        </div>
      </div>
      <button type="button" class="button viswiz-add-target">Add Target</button>
      <button type="button" class="button viswiz-remove-row">Remove</button>
    `;
    container.appendChild(row);
  }

  function getNextProgressIndex(container) {
    const values = Array.from(container.querySelectorAll('.viswiz-row')).map((row) =>
      parseInt(row.dataset.progressIndex, 10)
    );
    const max = values.length ? Math.max(...values) : -1;
    return max + 1;
  }

  function reindexProgressRows(container) {
    Array.from(container.querySelectorAll('.viswiz-row')).forEach((row, index) => {
      row.dataset.progressIndex = index;
      row.querySelectorAll('.viswiz-target-row input').forEach((input) => {
        const name = input.getAttribute('name');
        if (!name) {
          return;
        }
        const updated = name.replace(/targets\]\[(name|value)\]\[\d+\]\[/, `targets][$1][${index}][`);
        input.setAttribute('name', updated);
      });
    });
  }
  function addDiagramSection(containerId, namePrefix) {
    const container = document.getElementById(containerId);
    if (!container) {
      return;
    }
    const index = getNextDiagramIndex(container);
    const section = document.createElement('div');
    section.className = 'viswiz-section';
    section.dataset.sectionIndex = index;
    section.innerHTML = `
      <input type="text" name="${namePrefix}[title][]" placeholder="Section Title" class="regular-text" />
      <div class="viswiz-items">
        <div class="viswiz-item-row">
          <input type="text" name="${namePrefix}[items][${index}][]" placeholder="Item" class="regular-text" />
          <button type="button" class="button viswiz-remove-item">Remove</button>
        </div>
      </div>
      <button type="button" class="button viswiz-add-item">Add Item</button>
      <button type="button" class="button viswiz-remove-section">Remove Section</button>
    `;
    container.appendChild(section);
  }

  function getNextDiagramIndex(container) {
    const values = Array.from(container.querySelectorAll('.viswiz-section')).map((section) =>
      parseInt(section.dataset.sectionIndex, 10)
    );
    const max = values.length ? Math.max(...values) : -1;
    return max + 1;
  }


  function getNextGraphNodeId(container) {
    const ids = Array.from(container.querySelectorAll('[data-viswiz-node-id]')).map((input) => input.value);
    let index = ids.length + 1;
    while (ids.includes('node-' + index)) {
      index += 1;
    }
    return 'node-' + index;
  }

  function addGraphNode(containerId, namePrefix) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const index = container.querySelectorAll('[data-viswiz-node-card]').length;
    const id = getNextGraphNodeId(container);
    const editorId = 'viswiz_node_desc_dynamic_' + Date.now();
    const row = document.createElement('details');
    row.className = 'viswiz-node-card viswiz-sortable-card';
    row.open = false;
    row.dataset.viswizNodeCard = '1';
    row.dataset.nodeIndex = index;
    row.dataset.viswizNodeSearchText = 'new node';
    row.dataset.viswizNodeTypeValue = '';
    row.dataset.viswizNodeSubtypeValue = '';
    row.innerHTML = `
      <summary><strong>New node</strong> <span class="viswiz-node-card-summary-meta">No type</span> <code data-viswiz-node-id-display>${id}</code></summary>
      <div class="viswiz-node-card-media"><span class="viswiz-node-card-image-placeholder" aria-hidden="true">No image</span></div>
      <input type="hidden" name="${namePrefix}[id][]" value="${id}" data-viswiz-node-id />
      <div class="viswiz-node-grid">
        <label>Title <input type="text" name="${namePrefix}[title][]" placeholder="Node title" class="regular-text" data-viswiz-node-title /></label>
        <label>Short label <input type="text" name="${namePrefix}[label][]" placeholder="Optional short label" class="regular-text" /></label>
        <label>Node type <select name="${namePrefix}[node_type][]" data-viswiz-node-type><option value="">Select node type</option><option value="person">Person</option><option value="organization">Organization</option><option value="event">Event</option><option value="place">Place</option><option value="publication">Publication</option><option value="legal_case">Legal case</option><option value="state_body">State body</option><option value="symbol">Symbol</option><option value="concept">Concept</option><option value="asset">Asset</option></select></label>
        <label>Node subtype <select name="${namePrefix}[node_subtype][]" data-viswiz-node-subtype><option value="">No subtype</option><option value="proposed">Other / proposed subtype</option></select></label>
        <label>Main image <span class="viswiz-media-field"><input type="hidden" name="${namePrefix}[main_image][]" value="" data-viswiz-media-value /><button type="button" class="button" data-viswiz-media-select="single">Select/upload</button><span data-viswiz-media-label>No image selected</span></span></label>
        <label>Other images <span class="viswiz-media-field"><input type="hidden" name="${namePrefix}[other_images][]" value="" data-viswiz-media-value /><button type="button" class="button" data-viswiz-media-select="multiple">Select/upload</button><span data-viswiz-media-label>No images selected</span></span></label>
      </div>

      <div class="viswiz-proposed-subtype" data-viswiz-proposed-subtype hidden>
        <strong>Proposed subtype workflow</strong>
        <p class="description">Editors should propose new subtypes instead of adding top-level node types for routine work.</p>
        <label>Proposed label <input type="text" name="${namePrefix}[proposed_subtype_label][]" class="regular-text" /></label>
        <label>Reason <textarea name="${namePrefix}[proposed_subtype_reason][]" rows="2"></textarea></label>
        <label>Example entity <input type="text" name="${namePrefix}[proposed_subtype_example][]" class="regular-text" /></label>
        <label>Why existing types do not fit <textarea name="${namePrefix}[proposed_subtype_gap][]" rows="2"></textarea></label>
        <label>Review status <select name="${namePrefix}[proposed_subtype_status][]"><option value="proposed">Proposed</option><option value="approved">Approved</option><option value="merged">Merged</option><option value="renamed">Renamed</option><option value="rejected">Rejected</option></select></label>
      </div>
      <label class="viswiz-full-field">Formatted description<textarea id="${editorId}" name="${namePrefix}[description][]" rows="4"></textarea></label>
      <div class="viswiz-custom-labels"><strong>Custom labels</strong><button type="button" class="button viswiz-add-custom-label">Add custom label</button></div>
      <div class="viswiz-node-relation-tools" data-viswiz-node-relation-tools>
        <strong>Relations for this node</strong>
        <div class="viswiz-node-relation-list" data-viswiz-node-relation-list></div>
        <div class="viswiz-node-relation-editor" data-viswiz-node-relation-editor hidden></div>
        <button type="button" class="button" data-viswiz-node-add-relation>Add relation from this node</button>
      </div>
      <p class="viswiz-node-actions"><button type="submit" class="button button-primary" data-viswiz-save-node>Save node</button> <button type="button" class="button" data-viswiz-close-node>Close & autosave</button> <span class="description" data-viswiz-node-autosave-status></span> <button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button viswiz-remove-row">Remove node</button></p>`;
    container.appendChild(row);
    openNodeModal(row);
    updateNodeSubtypeOptions(row);
    refreshNodeDatalist();
    refreshNodeRelationTools();
    buildNodeTypeFilterOptions();
    filterNodeList();
    if (window.wp && wp.editor && wp.editor.initialize) {
      wp.editor.initialize(editorId, { tinymce: { wpautop: true }, quicktags: true, mediaButtons: false });
    }
  }

  function getNodeCardText(card) {
    const values = [
      card.querySelector('[data-viswiz-node-title]')?.value || '',
      card.querySelector('[name$="[label][]"]')?.value || '',
      card.querySelector('[name$="[description][]"]')?.value || '',
    ];
    return values.join(' ').toLowerCase();
  }

  function getNodeTypeLabel(card) {
    const typeSelect = card.querySelector('[data-viswiz-node-type]');
    const subtypeSelect = card.querySelector('[data-viswiz-node-subtype]');
    const typeText = typeSelect && typeSelect.selectedIndex >= 0 ? typeSelect.options[typeSelect.selectedIndex].text : '';
    const subtypeText = subtypeSelect && subtypeSelect.value && subtypeSelect.selectedIndex >= 0 ? subtypeSelect.options[subtypeSelect.selectedIndex].text : '';
    return subtypeText ? `${typeText} / ${subtypeText}` : (typeText || 'Uncategorized');
  }

  function getSelectedNodeTypeFilters() {
    return Array.from(document.querySelectorAll('[data-viswiz-node-type-filter-option]:checked')).map((input) => input.value);
  }

  function getNodeFilterValue(card) {
    const type = card.querySelector('[data-viswiz-node-type]')?.value || card.dataset.viswizNodeTypeValue || '';
    const subtype = card.querySelector('[data-viswiz-node-subtype]')?.value || card.dataset.viswizNodeSubtypeValue || '';
    return `${type}::${subtype}`;
  }

  function buildNodeTypeFilterOptions() {
    const optionsWrap = document.querySelector('[data-viswiz-node-type-filter-options]');
    if (!optionsWrap) return;
    const selected = new Set(getSelectedNodeTypeFilters());
    const entries = new Map();
    document.querySelectorAll('[data-viswiz-node-card]').forEach((card) => {
      const value = getNodeFilterValue(card);
      entries.set(value, getNodeTypeLabel(card));
    });
    optionsWrap.innerHTML = '';
    Array.from(entries.entries()).sort((a, b) => a[1].localeCompare(b[1])).forEach(([value, label]) => {
      const row = document.createElement('label');
      row.className = 'viswiz-node-type-filter-option';
      row.dataset.viswizNodeTypeFilterText = label.toLowerCase();
      row.innerHTML = `<input type="checkbox" value="${value}" data-viswiz-node-type-filter-option ${selected.has(value) ? 'checked' : ''}> <span>${label}</span>`;
      optionsWrap.appendChild(row);
    });
    updateNodeTypeFilterLabel();
  }

  function updateNodeTypeFilterLabel() {
    const button = document.querySelector('[data-viswiz-node-type-filter-toggle]');
    if (!button) return;
    const count = getSelectedNodeTypeFilters().length;
    button.textContent = count ? `${count} type/subtype filter${count === 1 ? '' : 's'} selected` : 'All node types and subtypes';
  }

  function filterNodeTypeDropdown() {
    const query = (document.querySelector('[data-viswiz-node-type-filter-search]')?.value || '').toLowerCase();
    document.querySelectorAll('[data-viswiz-node-type-filter-text]').forEach((row) => {
      row.hidden = query && !row.dataset.viswizNodeTypeFilterText.includes(query);
    });
  }

  function filterNodeList() {
    const search = (document.querySelector('[data-viswiz-node-search]')?.value || '').toLowerCase();
    const selectedTypes = getSelectedNodeTypeFilters();
    let shown = 0;
    let total = 0;
    document.querySelectorAll('[data-viswiz-node-card]').forEach((card) => {
      total += 1;
      card.dataset.viswizNodeSearchText = getNodeCardText(card);
      card.dataset.viswizNodeTypeValue = card.querySelector('[data-viswiz-node-type]')?.value || '';
      card.dataset.viswizNodeSubtypeValue = card.querySelector('[data-viswiz-node-subtype]')?.value || '';
      const matchesSearch = search.length < 3 || card.dataset.viswizNodeSearchText.includes(search);
      const matchesType = selectedTypes.length === 0 || selectedTypes.includes(getNodeFilterValue(card));
      card.hidden = !(matchesSearch && matchesType);
      if (!card.hidden) shown += 1;
    });
    const status = document.querySelector('[data-viswiz-node-search-status]');
    if (status) {
      status.textContent = `${shown} of ${total} nodes shown${search.length > 0 && search.length < 3 ? ' (enter 3+ characters to search)' : ''}.`;
    }
    updateNodeTypeFilterLabel();
  }

  function refreshNodeDatalist() {
    const datalist = document.querySelector('[data-viswiz-node-options]');
    if (!datalist) return;
    datalist.innerHTML = '';
    document.querySelectorAll('#viswiz-visual-graph-nodes [data-viswiz-node-card]').forEach((card) => {
      const id = card.querySelector('[data-viswiz-node-id]')?.value || '';
      const title = card.querySelector('[data-viswiz-node-title]')?.value || card.querySelector('[name$="[label][]"]')?.value || id;
      if (!id) return;
      const option = document.createElement('option');
      option.value = id;
      option.textContent = title;
      datalist.appendChild(option);
    });
  }

  function updateNodeSummary(card) {
    if (!card) return;
    const title = card.querySelector('[data-viswiz-node-title]')?.value || 'New node';
    const titleEl = card.querySelector('summary strong');
    if (titleEl) titleEl.textContent = title;
    const meta = card.querySelector('.viswiz-node-card-summary-meta');
    if (meta) meta.textContent = getNodeTypeLabel(card);
  }


  function setNodeAutosaveStatus(card, message, state) {
    const status = card?.querySelector('[data-viswiz-node-autosave-status]');
    if (!status) return;
    status.textContent = message || '';
    status.dataset.viswizAutosaveState = state || '';
  }

  function openNodeModal(card) {
    if (!card || card.classList.contains('is-editing')) return;
    const form = card.closest('form');
    if (!form) return;
    card.dataset.viswizOpening = '1';
    document.querySelectorAll('[data-viswiz-node-card].is-editing').forEach((openCard) => {
      if (openCard !== card) closeNodeModal(openCard);
    });

    const placeholder = document.createElement('div');
    placeholder.className = 'viswiz-node-card viswiz-node-card-placeholder';
    placeholder.dataset.viswizNodePlaceholder = '1';
    placeholder.setAttribute('aria-hidden', 'true');
    const summary = card.querySelector('summary');
    placeholder.innerHTML = summary ? summary.outerHTML : '<summary><strong>Editing node…</strong></summary>';
    card.parentNode.insertBefore(placeholder, card);

    const modal = document.createElement('div');
    modal.className = 'viswiz-node-editor-modal';
    modal.dataset.viswizNodeEditorModal = '1';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');
    const modalContent = document.createElement('div');
    modalContent.className = 'viswiz-node-editor-modal-content';
    modal.appendChild(modalContent);
    form.appendChild(modal);
    modalContent.appendChild(card);

    card.open = true;
    card.classList.add('is-editing');
    card.dataset.viswizModalPlaceholder = '1';
    document.body.classList.add('viswiz-node-modal-open');
    refreshNodeRelationTools();
    window.setTimeout(() => {
      delete card.dataset.viswizOpening;
    }, 0);
  }

  function closeNodeModal(card) {
    if (!card) return;
    card.dataset.viswizClosing = '1';
    const modal = card.closest('[data-viswiz-node-editor-modal]');
    const placeholder = document.querySelector('[data-viswiz-node-placeholder]');
    card.open = false;
    card.classList.remove('is-editing');
    delete card.dataset.viswizModalPlaceholder;
    if (placeholder && placeholder.parentNode) {
      placeholder.parentNode.insertBefore(card, placeholder);
      placeholder.remove();
    }
    if (modal) modal.remove();
    if (!document.querySelector('[data-viswiz-node-card].is-editing')) {
      document.body.classList.remove('viswiz-node-modal-open');
    }
    window.setTimeout(() => {
      delete card.dataset.viswizClosing;
    }, 0);
  }

  function autosaveNodeAndClose(card) {
    if (!card) return;
    const form = card.closest('form');
    const postId = (window.VisWizAdmin && VisWizAdmin.postId) || document.getElementById('post_ID')?.value || 0;
    if (!form || !window.fetch || !window.VisWizAdmin || !VisWizAdmin.ajaxUrl || !postId) {
      if (form) {
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
      return;
    }
    if (window.tinyMCE && tinyMCE.triggerSave) {
      tinyMCE.triggerSave();
    }
    setNodeAutosaveStatus(card, 'Autosaving…', 'saving');
    const formData = new FormData();
    formData.set('action', 'viswiz_autosave_graph_node');
    formData.set('nonce', VisWizAdmin.nonce || '');
    formData.set('post_id', postId);
    form.querySelectorAll('[name^="viswiz_meta[graph_data]"]').forEach((field) => {
      if (field.disabled || ((field.type === 'checkbox' || field.type === 'radio') && !field.checked)) return;
      formData.append(field.name, field.value);
    });
    window.fetch(VisWizAdmin.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Autosave failed');
        }
        return response.json();
      })
      .then((response) => {
        if (!response || !response.success) {
          throw new Error('Autosave failed');
        }
        setNodeAutosaveStatus(card, 'Autosaved.', 'saved');
        closeNodeModal(card);
      })
      .catch(() => {
        setNodeAutosaveStatus(card, 'Autosave failed. Use Save node to retry.', 'error');
      });
  }

  function getRelationNodeIds(relationCard) {
    return {
      from: relationCard.querySelector('[data-viswiz-relation-from]')?.value || '',
      to: relationCard.querySelector('[data-viswiz-relation-to]')?.value || '',
    };
  }

  function updateRelationCardDataset(relationCard) {
    const ids = getRelationNodeIds(relationCard);
    relationCard.dataset.relationFrom = ids.from;
    relationCard.dataset.relationTo = ids.to;
  }



  function escapeAttribute(value) {
    return String(value || '').replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#039;',
    }[char]));
  }

  function openNodeRelationEditor(nodeCard, relationCard) {
    const editor = nodeCard?.querySelector('[data-viswiz-node-relation-editor]');
    if (!editor || !relationCard) return;
    const index = Array.from(document.querySelectorAll('#viswiz-visual-graph-links [data-viswiz-relation-card]')).indexOf(relationCard);
    const ids = getRelationNodeIds(relationCard);
    const label = relationCard.querySelector('input[name$="[label][]"]')?.value || '';
    const relationType = relationCard.querySelector('input[name$="[relation_type][]"]')?.value || '';
    editor.hidden = false;
    editor.dataset.relationIndex = index;
    editor.innerHTML = `
      <strong>Edit relation</strong>
      <label>From <input type="text" value="${escapeAttribute(ids.from)}" data-viswiz-node-relation-quick="from" list="viswiz_visual_relation_nodes" /></label>
      <label>To <input type="text" value="${escapeAttribute(ids.to)}" data-viswiz-node-relation-quick="to" list="viswiz_visual_relation_nodes" /></label>
      <label>Label <input type="text" value="${escapeAttribute(label)}" data-viswiz-node-relation-quick="label" /></label>
      <label>Relation type <input type="text" value="${escapeAttribute(relationType)}" data-viswiz-node-relation-quick="relation_type" /></label>
      <button type="button" class="button" data-viswiz-close-node-relation-editor>Done editing relation</button>
    `;
  }

  function syncQuickRelationEditor(input) {
    const editor = input.closest('[data-viswiz-node-relation-editor]');
    const relationIndex = parseInt(editor?.dataset.relationIndex, 10);
    const relation = document.querySelectorAll('#viswiz-visual-graph-links [data-viswiz-relation-card]')[relationIndex];
    if (!relation) return;
    const selectors = {
      from: '[data-viswiz-relation-from]',
      to: '[data-viswiz-relation-to]',
      label: 'input[name$="[label][]"]',
      relation_type: 'input[name$="[relation_type][]"]',
    };
    const target = relation.querySelector(selectors[input.dataset.viswizNodeRelationQuick]);
    if (target) {
      target.value = input.value;
      updateRelationCardDataset(relation);
      refreshNodeRelationTools();
    }
  }

  function refreshNodeRelationTools() {
    document.querySelectorAll('#viswiz-visual-graph-links [data-viswiz-relation-card]').forEach(updateRelationCardDataset);
    document.querySelectorAll('#viswiz-visual-graph-nodes [data-viswiz-node-card]').forEach((nodeCard) => {
      const nodeId = nodeCard.querySelector('[data-viswiz-node-id]')?.value || '';
      const list = nodeCard.querySelector('[data-viswiz-node-relation-list]');
      if (!list) return;
      list.innerHTML = '';
      document.querySelectorAll('#viswiz-visual-graph-links [data-viswiz-relation-card]').forEach((relationCard, index) => {
        const ids = getRelationNodeIds(relationCard);
        if (ids.from !== nodeId && ids.to !== nodeId) return;
        const label = relationCard.querySelector('input[name$="[label][]"]')?.value || 'Untitled relation';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button-small';
        button.dataset.viswizEditRelation = index;
        button.textContent = `${ids.from || '…'} → ${ids.to || '…'}: ${label}`;
        list.appendChild(button);
      });
      if (!list.children.length) {
        const empty = document.createElement('span');
        empty.className = 'description';
        empty.textContent = 'No relations yet.';
        list.appendChild(empty);
      }
    });
  }

  function addGraphLink(containerId, namePrefix) {
    const container = document.getElementById(containerId);
    if (!container) return;
    const row = document.createElement('details');
    row.className = 'viswiz-relation-card viswiz-sortable-card';
    row.open = true;
    row.dataset.viswizRelationCard = '1';
    row.dataset.relationIndex = container.querySelectorAll('[data-viswiz-relation-card]').length;
    row.innerHTML = `<summary><span class="viswiz-drag-handle" aria-hidden="true">↕</span><strong>Relation</strong></summary>
      <div class="viswiz-relation-grid">
        <label>From <input type="text" name="${namePrefix}[from][]" placeholder="Search/select source node" class="regular-text" data-viswiz-relation-from list="viswiz_visual_relation_nodes" /></label>
        <label>To <input type="text" name="${namePrefix}[to][]" placeholder="Search/select target node" class="regular-text" data-viswiz-relation-to list="viswiz_visual_relation_nodes" /></label>
        <input type="text" name="${namePrefix}[label][]" placeholder="Relation label" class="regular-text" />
        <select name="${namePrefix}[direction][]"><option value="directed">Directed</option><option value="undirected">Undirected</option><option value="bidirectional">Bidirectional</option></select>
        <input type="number" name="${namePrefix}[intensity][]" placeholder="Intensity" value="1" min="0" step="0.01" />
        <input type="text" name="${namePrefix}[relation_type][]" placeholder="Relation type" class="regular-text" />
      </div><p><button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button viswiz-remove-row">Remove relation</button></p>`;
    container.appendChild(row);
    refreshNodeRelationTools();
  }

  const addHandlers = {
    progress() {
      addProgressRow('viswiz-progress-rows', 'viswiz_manual_progress');
    },
    pie() {
      addRow('viswiz-pie-rows', ['Label', 'Value', 'Color'], 'viswiz_manual_pie', ['label', 'value', 'color'], {
        typeMap: { value: 'number', color: 'color' },
      });
    },
    diagram() {
      addDiagramSection('viswiz-diagram-sections', 'viswiz_diagram_data');
    },
    'graph-node': function () {
      addGraphNode('viswiz-graph-nodes', 'viswiz_graph_data[nodes]');
    },
    'graph-link': function () {
      addGraphLink('viswiz-graph-links', 'viswiz_graph_data[links]');
    },
    'visual-progress': function () {
      addProgressRow('viswiz-visual-progress', 'viswiz_meta[manual_progress]');
    },
    'visual-pie': function () {
      addRow('viswiz-visual-pie', ['Label', 'Value', 'Color'], 'viswiz_meta[manual_pie]', ['label', 'value', 'color'], {
        typeMap: { value: 'number', color: 'color' },
      });
    },
    'visual-diagram': function () {
      addDiagramSection('viswiz-visual-diagram', 'viswiz_meta[diagram_data]');
    },
    'visual-graph-node': function () {
      addGraphNode('viswiz-visual-graph-nodes', 'viswiz_meta[graph_data][nodes]');
    },
    'visual-graph-link': function () {
      addGraphLink('viswiz-visual-graph-links', 'viswiz_meta[graph_data][links]');
    },
  };

  $(document).on('click', '[data-viswiz-add]', function () {
    const key = $(this).data('viswiz-add');
    if (addHandlers[key]) {
      addHandlers[key]();
    }
  });

  $(document).on('click', '.viswiz-remove-row', function () {
    const container = $(this).closest('.viswiz-repeatable');
    const card = $(this).closest('.viswiz-sortable-card');
    if (card.length) {
      card.remove();
    } else {
      $(this).closest('.viswiz-row').remove();
    }
    if (container.length) {
      reindexProgressRows(container.get(0));
    }
    refreshNodeDatalist();
    refreshNodeRelationTools();
    buildNodeTypeFilterOptions();
    filterNodeList();
  });

  $(document).on('click', '.viswiz-remove-section', function () {
    $(this).closest('.viswiz-section').remove();
  });

  $(document).on('click', '.viswiz-add-item', function () {
    const section = $(this).closest('.viswiz-section');
    const index = section.data('section-index');
    const items = section.find('.viswiz-items');
    const row = $(
      `<div class="viswiz-item-row">
        <input type="text" name="${section.find('input[name$="[title][]"]').attr('name').replace('[title][]', `[items][${index}][]`)}" placeholder="Item" class="regular-text" />
        <button type="button" class="button viswiz-remove-item">Remove</button>
      </div>`
    );
    items.append(row);
  });

  $(document).on('click', '.viswiz-remove-item', function () {
    $(this).closest('.viswiz-item-row').remove();
  });

  $(document).on('click', '.viswiz-add-target', function () {
    const row = $(this).closest('.viswiz-row');
    const index = row.attr('data-progress-index');
    const targets = row.find('.viswiz-targets');
    const namePrefix = targets.data('name-prefix');
    if (!namePrefix) {
      return;
    }
    const rowHtml = `
      <div class="viswiz-target-row">
        <input type="text" name="${namePrefix}[targets][name][${index}][]" placeholder="Target name" class="regular-text" />
        <input type="number" name="${namePrefix}[targets][value][${index}][]" placeholder="Target value" step="0.01" />
        <button type="button" class="button viswiz-remove-target">Remove</button>
      </div>
    `;
    targets.append(rowHtml);
  });

  $(document).on('click', '.viswiz-remove-target', function () {
    $(this).closest('.viswiz-target-row').remove();
  });

  $(document).on('change', '[data-viswiz-type], [data-viswiz-source], [data-viswiz-period-mode]', updateVisualizationFields);

  $(document).on('change', '[data-viswiz-node-type]', function () {
    const card = this.closest('[data-viswiz-node-card]') || document;
    updateNodeSubtypeOptions(card);
    updateNodeSummary(card);
    buildNodeTypeFilterOptions();
    queueNodeTypeAutosave(card);
    filterNodeList();
  });

  $(document).on('change', '[data-viswiz-node-subtype]', function () {
    const card = this.closest('[data-viswiz-node-card]') || document;
    updateProposedSubtype(card);
    updateNodeSummary(card);
    buildNodeTypeFilterOptions();
    queueNodeTypeAutosave(card);
    filterNodeList();
  });

  $(document).on('input change', '[name$="[proposed_subtype_label][]"], [name$="[proposed_subtype_reason][]"], [name$="[proposed_subtype_example][]"], [name$="[proposed_subtype_gap][]"], [name$="[proposed_subtype_status][]"]', function () {
    queueNodeTypeAutosave(this.closest('[data-viswiz-node-card]'));
  });

  $(document).on('click', '.viswiz-tab-button', function () {
    const tab = $(this).data('viswiz-tab');
    $('.viswiz-tab-button').removeClass('is-active');
    $(this).addClass('is-active');
    $('.viswiz-tab-panel').removeClass('is-active');
    $(`[data-viswiz-panel="${tab}"]`).addClass('is-active');
    updateVisualizationFields();
  });


  $(document).on('click', '.viswiz-move-up, .viswiz-move-down', function () {
    const card = $(this).closest('.viswiz-sortable-card');
    if ($(this).hasClass('viswiz-move-up')) card.prev('.viswiz-sortable-card').before(card);
    else card.next('.viswiz-sortable-card').after(card);
  });

  $(document).on('click', '.viswiz-add-custom-label', function () {
    const card = $(this).closest('[data-viswiz-node-card]');
    const name = card.find('[data-viswiz-node-id]').attr('name').replace('[id][]', '');
    const index = card.index();
    const html = `<div class="viswiz-custom-label-row"><input type="text" name="${name}[custom_key][${index}][]" placeholder="Label key" pattern="[A-Za-z0-9_-]+" /> <select name="${name}[custom_type][${index}][]"><option value="short">Short text</option><option value="url">Hyperlink</option><option value="long">Long text</option><option value="formatted">Formatted text</option></select> <textarea name="${name}[custom_value][${index}][]" placeholder="Value" rows="2"></textarea> <button type="button" class="button viswiz-remove-custom-label">Remove</button></div>`;
    $(this).before(html);
  });

  $(document).on('click', '.viswiz-remove-custom-label', function () { $(this).closest('.viswiz-custom-label-row').remove(); });

  $(document).on('input', '[data-viswiz-node-title]', function () {
    const card = $(this).closest('[data-viswiz-node-card]');
    updateNodeSummary(card.get(0));
    refreshNodeDatalist();
    filterNodeList();
  });


  document.addEventListener('click', function (event) {
    const card = event.target.closest && event.target.closest('[data-viswiz-node-card]');
    if (!card || card.classList.contains('is-editing')) return;
    if (event.target.closest('button, a, input, select, textarea, label')) return;
    event.preventDefault();
    event.stopPropagation();
    openNodeModal(card);
  }, true);

  $(document).on('toggle', '[data-viswiz-node-card]', function () {
    if (this.dataset.viswizClosing === '1' || this.dataset.viswizOpening === '1') return;
    if (this.open && !this.classList.contains('is-editing')) {
      openNodeModal(this);
    } else if (!this.open) {
      closeNodeModal(this);
    }
  });


  $(document).on('click', '[data-viswiz-close-node]', function () {
    const card = this.closest('[data-viswiz-node-card]');
    if (card) {
      autosaveNodeAndClose(card);
    }
  });

  $(document).on('click', '[data-viswiz-node-add-relation]', function () {
    const card = this.closest('[data-viswiz-node-card]');
    const nodeId = card?.querySelector('[data-viswiz-node-id]')?.value || '';
    addGraphLink('viswiz-visual-graph-links', 'viswiz_meta[graph_data][links]');
    const relation = document.querySelector('#viswiz-visual-graph-links [data-viswiz-relation-card]:last-child');
    if (relation) {
      const from = relation.querySelector('[data-viswiz-relation-from]');
      if (from) from.value = nodeId;
      updateRelationCardDataset(relation);
      openNodeRelationEditor(card, relation);
    }
    refreshNodeRelationTools();
  });

  $(document).on('click', '[data-viswiz-edit-relation]', function () {
    const index = parseInt(this.dataset.viswizEditRelation, 10);
    const relation = document.querySelectorAll('#viswiz-visual-graph-links [data-viswiz-relation-card]')[index];
    if (!relation) return;
    openNodeRelationEditor(this.closest('[data-viswiz-node-card]'), relation);
  });

  $(document).on('input change', '[data-viswiz-node-relation-quick]', function () {
    syncQuickRelationEditor(this);
  });

  $(document).on('click', '[data-viswiz-close-node-relation-editor]', function () {
    const editor = this.closest('[data-viswiz-node-relation-editor]');
    if (editor) {
      editor.hidden = true;
      editor.innerHTML = '';
    }
  });

  $(document).on('input change', '[data-viswiz-relation-from], [data-viswiz-relation-to], .viswiz-relation-card input[name$="[label][]"]', function () {
    const relation = this.closest('[data-viswiz-relation-card]');
    if (relation) updateRelationCardDataset(relation);
    refreshNodeRelationTools();
  });

  $(document).on('input', '[data-viswiz-node-search]', filterNodeList);

  $(document).on('click', '[data-viswiz-node-type-filter-toggle]', function () {
    const menu = document.querySelector('[data-viswiz-node-type-filter-menu]');
    if (menu) {
      menu.hidden = !menu.hidden;
    }
  });

  $(document).on('input', '[data-viswiz-node-type-filter-search]', filterNodeTypeDropdown);

  $(document).on('change', '[data-viswiz-node-type-filter-option]', filterNodeList);

  $(document).on('input', '[name$="[label][]"], [name$="[description][]"]', function () {
    if ($(this).closest('[data-viswiz-node-card]').length) {
      filterNodeList();
    }
  });


  function reindexGraphCustomLabels() {
    $('[data-viswiz-node-card]').each(function (index) {
      const prefix = $(this).find('[data-viswiz-node-id]').attr('name').replace('[id][]', '');
      $(this).find('.viswiz-custom-label-row').each(function () {
        $(this).find('[name*="[custom_key]"]').attr('name', `${prefix}[custom_key][${index}][]`);
        $(this).find('[name*="[custom_type]"]').attr('name', `${prefix}[custom_type][${index}][]`);
        $(this).find('[name*="[custom_value]"]').attr('name', `${prefix}[custom_value][${index}][]`);
      });
    });
  }

  $(document).on('submit', 'form', function () {
    reindexGraphCustomLabels();
  });

  $(document).on('click', '[data-viswiz-media-select]', function () {
    if (!window.wp || !wp.media) return;
    const button = $(this);
    const multiple = button.data('viswiz-media-select') === 'multiple';
    const frame = wp.media({ title: 'Select node image', multiple, library: { type: 'image' } });
    frame.on('select', function () {
      const selection = frame.state().get('selection').toJSON();
      const ids = selection.map((item) => item.id).join(',');
      button.siblings('[data-viswiz-media-value]').val(ids);
      button.siblings('[data-viswiz-media-label]').text(ids ? '#' + ids : (multiple ? 'No images selected' : 'No image selected'));
    });
    frame.open();
  });

  $(document).ready(function () {
    document.querySelectorAll('[data-viswiz-node-card]').forEach((card) => updateProposedSubtype(card));
    document.querySelectorAll('[data-viswiz-node-card]').forEach((card) => updateNodeSummary(card));
    refreshNodeDatalist();
    refreshNodeRelationTools();
    buildNodeTypeFilterOptions();
    filterNodeList();
    updateVisualizationFields();
    updateSalesPeriodVisibility();
    $('.viswiz-tab-button.is-active').trigger('click');
  });

  function updateSalesPeriodVisibility() {
    const mode = $('#viswiz_sales_period_mode').val();
    $('.viswiz-period-group[data-viswiz-period]').each(function () {
      const supported = $(this).data('viswiz-period');
      if (!supported || supported === mode) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  }

  $(document).on('change', '#viswiz_sales_period_mode', function () {
    updateSalesPeriodVisibility();
  });

  const defaultColors = ['#4caf50', '#03a9f4', '#ffc107', '#e91e63', '#9c27b0', '#ff5722'];

  function getFormattingColors() {
    return {
      primary: $('#viswiz_color_primary').val() || '#4caf50',
      secondary: $('#viswiz_color_secondary').val() || '#2196f3',
      accent: $('#viswiz_color_accent').val() || '#ffc107',
      background: $('#viswiz_color_background').val() || '#ffffff',
      text: $('#viswiz_color_text').val() || '#333333',
    };
  }

  function getAnimation() {
    return $('#viswiz_animation').val() || 'none';
  }

  function applyPreviewFormatting(container, colors, animation) {
    container.style.setProperty('--viswiz-primary', colors.primary);
    container.style.setProperty('--viswiz-secondary', colors.secondary);
    container.style.setProperty('--viswiz-accent', colors.accent);
    container.style.setProperty('--viswiz-background', colors.background);
    container.style.setProperty('--viswiz-text', colors.text);
    container.className = container.className.replace(/viswiz-animate-\w+/g, '').trim();
    if (animation && animation !== 'none') {
      container.classList.add('viswiz-animate-' + animation);
    }
  }

  function gatherManualProgress() {
    const items = [];
    $('#viswiz-visual-progress .viswiz-row').each(function () {
      const $row = $(this);
      const label = $row.find('input[name$="[label][]"]').val() || '';
      const value = parseFloat($row.find('input[name$="[value][]"]').val()) || 0;
      const targets = [];
      $row.find('.viswiz-target-row').each(function () {
        const name = $(this).find('input[placeholder="Target name"]').val() || '';
        const targetValue = parseFloat($(this).find('input[placeholder="Target value"]').val()) || 0;
        if (name || targetValue) {
          targets.push({ name, value: targetValue });
        }
      });
      if (label || value || targets.length) {
        const maxTarget = targets.reduce((max, t) => Math.max(max, t.value), 0);
        items.push({ label, value, target: maxTarget, targets });
      }
    });
    return items;
  }

  function gatherManualPie() {
    const items = [];
    $('#viswiz-visual-pie .viswiz-row').each(function () {
      const label = $(this).find('input[name$="[label][]"]').val() || '';
      const value = parseFloat($(this).find('input[name$="[value][]"]').val()) || 0;
      const color = $(this).find('input[type="color"]').val() || '';
      if (label || value) {
        items.push({ label, value, color });
      }
    });
    return items;
  }

  function gatherDiagramData() {
    const sections = [];
    $('#viswiz-visual-diagram .viswiz-section').each(function () {
      const title = $(this).find('input[name$="[title][]"]').val() || '';
      const items = [];
      $(this).find('.viswiz-item-row input').each(function () {
        const val = $(this).val();
        if (val) {
          items.push(val);
        }
      });
      if (title || items.length) {
        sections.push({ title, items });
      }
    });
    return sections;
  }

  function gatherGraphData() {
    const nodes = [];
    const links = [];
    $('#viswiz-visual-graph-nodes .viswiz-node-card').each(function () {
      const id = $(this).find('input[name$="[id][]"]').val() || '';
      const title = $(this).find('input[name$="[title][]"]').val() || '';
      const label = $(this).find('input[name$="[label][]"]').val() || title;
      const entity_type = $(this).find('select[name$="[entity_type][]"]').val() || '';
      if (id || label || title) {
        nodes.push({ id, label, title, entity_type });
      }
    });
    $('#viswiz-visual-graph-links .viswiz-relation-card').each(function () {
      const from = $(this).find('input[name$="[from][]"]').val() || '';
      const to = $(this).find('input[name$="[to][]"]').val() || '';
      const label = $(this).find('input[name$="[label][]"]').val() || '';
      if (from || to) {
        const direction = $(this).find('select[name$="[direction][]"], input[name$="[direction][]"]').val() || 'directed';
        const intensity = parseFloat($(this).find('input[name$="[intensity][]"]').val()) || 1;
        const relation_type = $(this).find('input[name$="[relation_type][]"]').val() || '';
        links.push({ from, to, label, direction, intensity, relation_type });
      }
    });
    return { nodes, links };
  }

  function formatCurrency(value) {
    const amount = parseFloat(value || 0);
    return '$' + amount.toFixed(2);
  }

  function renderPreviewProgress(container, data) {
    container.innerHTML = '';
    const label = document.createElement('div');
    label.className = 'viswiz-progress-label';
    label.textContent = data.label;

    const bar = document.createElement('div');
    bar.className = 'viswiz-progress-bar';

    const fill = document.createElement('div');
    fill.className = 'viswiz-progress-fill';
    const targetValue = data.target > 0 ? data.target : getMaxTargetValue(data.targets);
    const percent = targetValue > 0 ? Math.min(100, (data.value / targetValue) * 100) : 0;
    fill.style.width = percent + '%';

    const meta = document.createElement('div');
    meta.className = 'viswiz-progress-meta';
    meta.textContent = formatCurrency(data.value) + ' / ' + formatCurrency(targetValue) + ' (' + percent.toFixed(1) + '%)';

    bar.appendChild(fill);
    container.appendChild(label);
    container.appendChild(bar);
    container.appendChild(buildProgressMarkers(data.targets, targetValue));
    container.appendChild(meta);
  }

  function getMaxTargetValue(targets) {
    if (!targets || !targets.length) {
      return 0;
    }
    return targets.reduce(function (max, target) {
      return Math.max(max, parseFloat(target.value || 0));
    }, 0);
  }

  function buildProgressMarkers(targets, maxValue) {
    const wrapper = document.createElement('div');
    wrapper.className = 'viswiz-progress-markers';
    (targets || []).forEach(function (target) {
      const targetValue = parseFloat(target.value || 0);
      if (!targetValue) {
        return;
      }
      const marker = document.createElement('div');
      marker.className = 'viswiz-progress-marker';
      const offset = maxValue > 0 ? Math.min(100, (targetValue / maxValue) * 100) : 0;
      marker.style.left = offset + '%';
      marker.title = (target.name || 'Target') + ': ' + targetValue;
      const markerLabel = document.createElement('span');
      markerLabel.className = 'viswiz-progress-marker-label';
      markerLabel.textContent = target.name || 'Target';
      marker.appendChild(markerLabel);
      wrapper.appendChild(marker);
    });
    return wrapper;
  }

  function renderPreviewProgressList(container, items, fallbackLabel) {
    container.innerHTML = '';
    items.forEach(function (item) {
      const row = document.createElement('div');
      row.className = 'viswiz-progress-item';
      renderPreviewProgress(row, {
        label: item.label || fallbackLabel,
        value: parseFloat(item.value || 0),
        target: parseFloat(item.target || 0),
        targets: item.targets || [],
      });
      container.appendChild(row);
    });
  }

  function renderPreviewPie(container, data) {
    container.innerHTML = '';
    const title = document.createElement('div');
    title.className = 'viswiz-pie-title';
    title.textContent = data.title;

    if (typeof d3 === 'undefined') {
      container.appendChild(title);
      const msg = document.createElement('p');
      msg.textContent = 'D3.js not loaded. Pie chart preview unavailable.';
      container.appendChild(msg);
      return;
    }

    const svg = d3.create('svg').attr('viewBox', '0 0 220 220').attr('class', 'viswiz-pie-chart');
    const pie = d3.pie().value(function (entry) {
      return entry.value || 0;
    });
    const arc = d3.arc().innerRadius(0).outerRadius(100);
    const g = svg.append('g').attr('transform', 'translate(110,110)');

    g.selectAll('path')
      .data(pie(data.values))
      .enter()
      .append('path')
      .attr('d', arc)
      .attr('fill', function (entry, index) {
        return entry.data.color || defaultColors[index % defaultColors.length];
      })
      .attr('stroke', '#fff')
      .attr('stroke-width', 1);

    container.appendChild(title);
    container.appendChild(svg.node());

    const legend = document.createElement('ul');
    legend.className = 'viswiz-pie-legend';
    data.values.forEach(function (entry, index) {
      const item = document.createElement('li');
      const swatch = document.createElement('span');
      swatch.className = 'viswiz-swatch';
      swatch.style.backgroundColor = entry.color || defaultColors[index % defaultColors.length];
      item.appendChild(swatch);
      item.appendChild(document.createTextNode(entry.label + ': ' + formatCurrency(entry.value)));
      legend.appendChild(item);
    });
    container.appendChild(legend);
  }

  function renderPreviewDiagram(container, data) {
    container.innerHTML = '';
    const header = document.createElement('h3');
    header.textContent = 'Diagram';
    container.appendChild(header);

    data.forEach(function (section) {
      const sectionEl = document.createElement('div');
      sectionEl.className = 'viswiz-diagram-section';

      const title = document.createElement('strong');
      title.textContent = section.title || 'Section';
      sectionEl.appendChild(title);

      const list = document.createElement('ul');
      (section.items || []).forEach(function (item) {
        const li = document.createElement('li');
        li.textContent = item;
        list.appendChild(li);
      });
      sectionEl.appendChild(list);
      container.appendChild(sectionEl);
    });
  }

  function getGraphOptions() {
    return {
      nodeRadius: parseInt($('#viswiz_graph_node_radius').val(), 10) || 20,
      linkDistance: parseInt($('#viswiz_graph_link_distance').val(), 10) || 100,
      chargeStrength: parseInt($('#viswiz_graph_charge').val(), 10) || -300,
    };
  }

  function renderPreviewGraph(container, data, options) {
    container.innerHTML = '';

    const graphOptions = options || getGraphOptions();
    const nodes = (data.nodes || []).map(function (n) {
      return { id: n.id, label: n.label || n.id };
    });
    const links = (data.links || []).map(function (l) {
      return { source: l.from, target: l.to, label: l.label || '', direction: l.direction || 'directed', intensity: parseFloat(l.intensity || 1), relation_type: l.relation_type || '' };
    });

    if (!nodes.length) {
      container.textContent = 'No graph data entered.';
      return;
    }

    if (typeof d3 === 'undefined') {
      container.textContent = 'D3.js not loaded. Graph preview unavailable.';
      return;
    }

    const width = 400;
    const height = 300;
    const nodeRadius = graphOptions.nodeRadius;
    const linkDistance = graphOptions.linkDistance;
    const chargeStrength = graphOptions.chargeStrength;
    const colors = getFormattingColors();

    const svg = d3.create('svg')
      .attr('viewBox', [0, 0, width, height])
      .attr('class', 'viswiz-graph-svg')
      .attr('width', '100%')
      .attr('height', height);

    const simulation = d3.forceSimulation(nodes)
      .force('link', d3.forceLink(links).id(function (d) { return d.id; }).distance(linkDistance))
      .force('charge', d3.forceManyBody().strength(chargeStrength))
      .force('center', d3.forceCenter(width / 2, height / 2))
      .force('collision', d3.forceCollide().radius(nodeRadius + 10));

    const defs = svg.append('defs');
    defs.append('marker')
      .attr('id', 'viswiz-preview-arrowhead')
      .attr('viewBox', '0 -5 10 10')
      .attr('refX', 20)
      .attr('refY', 0)
      .attr('markerWidth', 6)
      .attr('markerHeight', 6)
      .attr('orient', 'auto')
      .append('path')
      .attr('d', 'M0,-5L10,0L0,5')
      .attr('fill', colors.secondary);

    const link = svg.append('g')
      .attr('class', 'viswiz-graph-links-g')
      .selectAll('line')
      .data(links)
      .join('line')
      .attr('stroke', colors.secondary)
      .attr('stroke-width', function (d) { return Math.max(1, Math.min(8, d.intensity || 1)); })
      .attr('stroke-opacity', 0.6)
      .attr('marker-end', function (d) { return d.direction === 'undirected' ? null : 'url(#viswiz-preview-arrowhead)'; });

    const linkLabels = svg.append('g')
      .attr('class', 'viswiz-graph-link-labels')
      .selectAll('text')
      .data(links)
      .join('text')
      .attr('font-size', 10)
      .attr('fill', colors.text)
      .attr('text-anchor', 'middle')
      .text(function (d) { return [d.label, d.relation_type].filter(Boolean).join(' · '); });

    const node = svg.append('g')
      .attr('class', 'viswiz-graph-nodes')
      .selectAll('g')
      .data(nodes)
      .join('g');

    node.append('circle')
      .attr('r', nodeRadius)
      .attr('fill', colors.primary)
      .attr('stroke', '#fff')
      .attr('stroke-width', 2);

    node.append('text')
      .attr('dy', 4)
      .attr('text-anchor', 'middle')
      .attr('font-size', 11)
      .attr('fill', '#fff')
      .attr('pointer-events', 'none')
      .text(function (d) { return [d.label, d.relation_type].filter(Boolean).join(' · '); });

    simulation.on('tick', function () {
      link
        .attr('x1', function (d) { return d.source.x; })
        .attr('y1', function (d) { return d.source.y; })
        .attr('x2', function (d) { return d.target.x; })
        .attr('y2', function (d) { return d.target.y; });

      linkLabels
        .attr('x', function (d) { return (d.source.x + d.target.x) / 2; })
        .attr('y', function (d) { return (d.source.y + d.target.y) / 2 - 5; });

      node.attr('transform', function (d) { return 'translate(' + d.x + ',' + d.y + ')'; });
    });

    container.appendChild(svg.node());
  }

  function refreshPreview() {
    const container = document.getElementById('viswiz-preview-container');
    if (!container) {
      return;
    }

    const type = $('[data-viswiz-type]').val();
    const source = $('[data-viswiz-source]').val() || 'auto';
    const label = $('#viswiz_label').val() || 'Visualization';
    const target = parseFloat($('#viswiz_target').val()) || 0;
    const colors = getFormattingColors();
    const animation = getAnimation();

    container.innerHTML = '';

    let vizContainer;
    if (type === 'progress') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-progress';
      applyPreviewFormatting(vizContainer, colors, animation);

      if (source === 'manual') {
        const items = gatherManualProgress();
        if (items.length > 1) {
          renderPreviewProgressList(vizContainer, items, label);
        } else if (items.length === 1) {
          renderPreviewProgress(vizContainer, items[0]);
        } else {
          vizContainer.textContent = 'No manual progress data entered.';
        }
      } else {
        renderPreviewProgress(vizContainer, {
          label: label || 'Sales Progress',
          value: 7500,
          target: target || 10000,
          targets: target ? [{ name: 'Target', value: target }] : [],
        });
      }
      container.appendChild(vizContainer);
    } else if (type === 'pie') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-pie';
      applyPreviewFormatting(vizContainer, colors, animation);

      if (source === 'manual') {
        const items = gatherManualPie();
        if (items.length) {
          renderPreviewPie(vizContainer, { title: label || 'Pie Chart', values: items });
        } else {
          vizContainer.textContent = 'No manual pie data entered.';
        }
      } else {
        renderPreviewPie(vizContainer, {
          title: label || 'Sales Breakdown',
          values: [
            { label: 'Completed', value: 45, color: '#4caf50' },
            { label: 'Processing', value: 30, color: '#2196f3' },
            { label: 'Pending', value: 25, color: '#ffc107' },
          ],
        });
      }
      container.appendChild(vizContainer);
    } else if (type === 'diagram') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-diagram';
      applyPreviewFormatting(vizContainer, colors, animation);

      const data = gatherDiagramData();
      if (data.length) {
        renderPreviewDiagram(vizContainer, data);
      } else {
        vizContainer.textContent = 'No diagram data entered.';
      }
      container.appendChild(vizContainer);
    } else if (type === 'graph') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-graph';
      applyPreviewFormatting(vizContainer, colors, animation);

      const data = gatherGraphData();
      if (data.nodes.length || data.links.length) {
        renderPreviewGraph(vizContainer, data);
      } else {
        vizContainer.textContent = 'No graph data entered.';
      }
      container.appendChild(vizContainer);
    } else {
      container.textContent = 'Select a visualization type to see a preview.';
    }
  }

  $(document).on('click', '.viswiz-tab-button[data-viswiz-tab="preview"]', function () {
    refreshPreview();
  });

  $(document).on('click', '#viswiz-refresh-preview', function () {
    refreshPreview();
  });
})(jQuery);
