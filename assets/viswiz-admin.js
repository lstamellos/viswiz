(function ($) {
  const graphNodeSubtypes = (window.VisWizAdmin && VisWizAdmin.nodeSubtypes) || {};
  const graphNodeTypes = (window.VisWizAdmin && VisWizAdmin.nodeTypes) || [];
  const relationTypes = (window.VisWizAdmin && VisWizAdmin.relationTypes) || [];
  let viswizIsDirty = false;
  let autosaveTimer = null;
  const i18n = (window.VisWizAdmin && VisWizAdmin.i18n) || {};
  const t = (key, fallback) => i18n[key] || fallback;
  const chartLikeTypes = ['pie', 'bar', 'column', 'line', 'area', 'scatter', 'counter', 'timeline', 'map'];
  const graphLikeTypes = ['graph', 'flow_diagram', 'org_chart'];
  const diagramLikeTypes = ['diagram'];

  function isChartLikeType(type) {
    return chartLikeTypes.includes(type);
  }

  function isGraphLikeType(type) {
    return graphLikeTypes.includes(type);
  }

  function isDiagramLikeType(type) {
    return diagramLikeTypes.includes(type);
  }

  function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === 'function') {
      return window.CSS.escape(value);
    }
    return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
  }

  function getVisualizationTypeLabel(type) {
    const option = document.querySelector(`[data-viswiz-type] option[value="${cssEscape(type)}"]`);
    return option ? option.textContent.trim() : (type || 'Visualization').replace(/_/g, ' ');
  }

  function getNodeTypeOptionsHtml(selected = '') {
    const defaults = [
      { slug: 'person', label: 'Person' },
      { slug: 'organization', label: 'Organization' },
      { slug: 'event', label: 'Event' },
      { slug: 'place', label: 'Place' },
      { slug: 'publication', label: 'Publication' },
      { slug: 'legal_case', label: 'Legal case' },
      { slug: 'state_body', label: 'State body' },
      { slug: 'symbol', label: 'Symbol' },
      { slug: 'concept', label: 'Concept' },
      { slug: 'asset', label: 'Asset' },
    ];
    const source = graphNodeTypes.length ? graphNodeTypes : defaults;
    return '<option value="">Select node type</option>' + source.map((item) => {
      const slug = item.slug || item.value || '';
      const label = item.label || slug;
      const description = item.description || '';
      const direction = item.direction || '';
      const intensity = item.default_intensity != null ? item.default_intensity : '';
      const sourceType = item.source_type || '';
      const sourceSubtype = item.source_subtype || '';
      const targetType = item.target_type || '';
      const targetSubtype = item.target_subtype || '';
      return `<option value="${escapeAttribute(slug)}"${slug === selected ? ' selected' : ''} data-relation-label="${escapeAttribute(label)}" data-relation-description="${escapeAttribute(description)}" data-relation-direction="${escapeAttribute(direction)}" data-relation-intensity="${escapeAttribute(intensity)}" data-relation-source="${escapeAttribute(sourceType)}" data-relation-source-subtype="${escapeAttribute(sourceSubtype)}" data-relation-target="${escapeAttribute(targetType)}" data-relation-target-subtype="${escapeAttribute(targetSubtype)}">${escapeAttribute(label)}</option>`;
    }).join('');
  }

  function getRelationTypeOptionsHtml(selected = '') {
    const source = relationTypes.length ? relationTypes : [
      { slug: 'member_of', label: 'Member of' },
      { slug: 'leader_of', label: 'Leader of' },
      { slug: 'participated_in', label: 'Participated in' },
      { slug: 'organized', label: 'Organized' },
      { slug: 'connected_to', label: 'Connected to' },
    ];
    let html = '<option value="">Select relation type</option>' + source.map((item) => {
      const slug = item.slug || item.value || '';
      const label = item.label || slug;
      const description = item.description || '';
      const direction = item.direction || '';
      const intensity = item.default_intensity != null ? item.default_intensity : '';
      const sourceType = item.source_type || '';
      const sourceSubtype = item.source_subtype || '';
      const targetType = item.target_type || '';
      const targetSubtype = item.target_subtype || '';
      return `<option value="${escapeAttribute(slug)}"${slug === selected ? ' selected' : ''} data-relation-label="${escapeAttribute(label)}" data-relation-description="${escapeAttribute(description)}" data-relation-direction="${escapeAttribute(direction)}" data-relation-intensity="${escapeAttribute(intensity)}" data-relation-source="${escapeAttribute(sourceType)}" data-relation-source-subtype="${escapeAttribute(sourceSubtype)}" data-relation-target="${escapeAttribute(targetType)}" data-relation-target-subtype="${escapeAttribute(targetSubtype)}">${escapeAttribute(label)}</option>`;
    }).join('');
    if (selected && !source.some((item) => (item.slug || item.value) === selected)) {
      html += `<option value="${escapeAttribute(selected)}" selected>${escapeAttribute(selected)}</option>`;
    }
    return html;
  }

  function markVisWizDirty() {
    viswizIsDirty = true;
    const indicator = document.querySelector('[data-viswiz-dirty-indicator]');
    if (indicator) {
      indicator.textContent = 'Unsaved changes';
      indicator.hidden = false;
    }
  }

  function updateManualDataLabels() {
    const type = $('[data-viswiz-type]').val() || 'pie';
    const labels = {
      pie: ['Slice label', 'Value', 'Color'],
      bar: ['Category', 'Value', 'Color'],
      column: ['Category', 'Value', 'Color'],
      line: ['X / date', 'Y value', 'Series color'],
      area: ['X / date', 'Y value', 'Series color'],
      scatter: ['Point label', 'Y value', 'Group color'],
      counter: ['Counter label', 'Value', 'Accent color'],
      timeline: ['Date / title', 'Order / value', 'Color'],
      map: ['Place / marker label', 'Value', 'Marker color'],
    };
    const headings = {
      pie: 'Pie slices',
      bar: 'Bar chart rows',
      column: 'Column chart rows',
      line: 'Line chart points',
      area: 'Area chart points',
      scatter: 'Scatter plot points',
      counter: 'Counter values',
      timeline: 'Timeline items',
      map: 'Map markers',
    };
    const addLabels = {
      pie: 'Add slice',
      bar: 'Add bar',
      column: 'Add column',
      line: 'Add point',
      area: 'Add point',
      scatter: 'Add point',
      counter: 'Add counter',
      timeline: 'Add item',
      map: 'Add marker',
    };
    const help = {
      scatter: 'For now, store the primary numeric value in Value. Use labels/advanced settings for x-axis mapping if your renderer needs it.',
      timeline: 'Use the label field for the date or title and the value field for ordering or magnitude.',
      map: 'Use the label field for place/marker label and the value field for magnitude. Add coordinates later through advanced settings if needed.',
    };
    const current = labels[type] || labels.pie;
    $('[data-viswiz-manual-data-heading]').text(headings[type] || 'Manual data rows');
    $('[data-viswiz-manual-data-add]').text(addLabels[type] || 'Add data row');
    $('[data-viswiz-manual-data-help]').text(help[type] || 'Use one row per label/value/color item for the selected visualization type.');
    $('#viswiz-visual-pie .viswiz-row').each(function () {
      $(this).find('input').eq(0).attr('placeholder', current[0]);
      $(this).find('input').eq(1).attr('placeholder', current[1]);
      $(this).find('input').eq(2).attr('title', current[2]);
    });
  }

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

  function getVisualizationGuide(type, source) {
    const typeLabel = getVisualizationTypeLabel(type);
    if (type === 'progress') {
      return {
        badge: source === 'manual' ? 'Manual progress' : 'WooCommerce progress',
        title: 'Progress editing guide',
        summary: source === 'manual'
          ? 'Use manual progress rows with a label, current value, and one or more target markers.'
          : 'WooCommerce progress uses the sales scope, period, product/category filters, and target settings.',
        steps: source === 'manual'
          ? ['Set the overall label/title.', 'Open the progress rows section and enter a value for each row.', 'Use named targets when one progress bar needs multiple milestones.', 'Refresh Preview to verify proportions before publishing.']
          : ['Choose sales scope and period.', 'Set product/category filters only when the scope needs them.', 'Set the target value used by the progress bar.', 'Refresh Preview to check the generated sales preview.'],
      };
    }
    if (isChartLikeType(type)) {
      return {
        badge: source === 'manual' ? 'Manual chart data' : 'WooCommerce chart data',
        title: `${typeLabel} editing guide`,
        summary: source === 'manual'
          ? 'Use type-aware data rows. Placeholders and add-button labels adapt to the selected visualization type.'
          : 'WooCommerce chart data uses the sales scope, period, product/category filters, and generated labels.',
        steps: source === 'manual'
          ? ['Enter one row per slice, category, point, marker, counter, or timeline item.', 'Use the label field as the visible category/date/place label.', 'Use the value field as the numeric magnitude or order.', 'Use Preview to confirm whether the current renderer maps the rows as expected.']
          : ['Choose sales scope and period.', 'Use product/category filters for a narrower breakdown.', 'Configure legend and axis labels only when generated labels are not enough.', 'Refresh Preview before embedding.'],
      };
    }
    if (isDiagramLikeType(type)) {
      return {
        badge: 'Legacy diagram',
        title: 'Diagram editing guide',
        summary: 'Legacy diagram visualizations use manual sections with repeatable text items. They do not use graph nodes or relation types.',
        steps: ['Add one section for each group.', 'Add repeatable items under each section.', 'Use the Display tab for colors and animation.', 'Refresh Preview to check the final layout.'],
      };
    }
    if (isGraphLikeType(type)) {
      return {
        badge: 'Graph schema workflow',
        title: `${typeLabel} editing guide`,
        summary: 'Graph-like visualizations use nodes, canonical node types/subtypes, relation types, and validation. They do not use manual diagram sections.',
        steps: ['Use Nodes to add entities and create connected nodes.', 'Use Type Usage & Proposals to review assignments and approve proposed subtypes into the global Node Types schema.', 'Use Relations for standalone edge editing and reverse/delete actions.', 'Use Validation and Preview to catch orphan endpoints, duplicate IDs, and type mismatches.'],
      };
    }
    return {
      badge: 'Guide',
      title: 'Data editing guide',
      summary: 'Choose a visualization type to see the relevant editing workflow.',
      steps: ['Select a visualization type.', 'Enter the matching data format.', 'Configure display and preview before embedding.'],
    };
  }

  function updateEditorGuide() {
    const guide = document.querySelector('[data-viswiz-editor-guide]');
    if (!guide) return;
    const type = $('[data-viswiz-type]').val() || '';
    const source = $('[data-viswiz-source]').val() || 'auto';
    const config = getVisualizationGuide(type, source);
    const title = guide.querySelector('[data-viswiz-guide-title]');
    const badge = guide.querySelector('[data-viswiz-guide-badge]');
    const summary = guide.querySelector('[data-viswiz-guide-summary]');
    const steps = guide.querySelector('[data-viswiz-guide-steps]');
    if (title) title.textContent = config.title;
    if (badge) badge.textContent = config.badge;
    if (summary) summary.textContent = config.summary;
    if (steps) {
      steps.innerHTML = '';
      config.steps.forEach((step) => {
        const item = document.createElement('li');
        item.textContent = step;
        steps.appendChild(item);
      });
    }
  }

  function updateBuilderSteps(activeTab) {
    const stepMap = {
      data: 'data',
      nodes: 'editing',
      'node-types': 'editing',
      relations: 'editing',
      formatting: 'formatting',
      preview: 'preview',
    };
    const order = ['data', 'editing', 'formatting', 'preview', 'embed'];
    const activeStep = stepMap[activeTab] || 'data';
    const activeIndex = order.indexOf(activeStep);
    document.querySelectorAll('[data-viswiz-step]').forEach((step) => {
      const stepIndex = order.indexOf(step.dataset.viswizStep);
      step.classList.toggle('is-active', step.dataset.viswizStep === activeStep);
      step.classList.toggle('is-complete', stepIndex >= 0 && activeIndex >= 0 && stepIndex < activeIndex);
    });
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
    updateManualDataLabels();
    updateEditorGuide();
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
    updateManualDataLabels();
    markVisWizDirty();
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



  function getMediaThumbUrl(item) {
    return item?.sizes?.thumbnail?.url || item?.sizes?.medium?.url || item?.url || '';
  }

  function getMediaAttachmentData(id, selectedById) {
    const selected = selectedById.get(String(id));
    if (selected) {
      return Promise.resolve(selected);
    }
    if (!window.wp || !wp.media || !wp.media.attachment) {
      return Promise.resolve({ id });
    }
    const attachment = wp.media.attachment(id);
    return attachment.fetch().then(() => attachment.toJSON(), () => ({ id }));
  }

  function renderNodeImageGallery(gallery, ids, mainId, selectedById) {
    Promise.all(ids.map((id) => getMediaAttachmentData(id, selectedById))).then((items) => {
      const title = gallery.querySelector('strong')?.outerHTML || '<strong>Node images</strong>';
      const figures = ids.map((id, index) => {
        const url = getMediaThumbUrl(items[index]);
        const isFeatured = String(id) === String(mainId);
        const image = url ? `<img class="viswiz-node-image-thumb-img" src="${escapeAttribute(url)}" alt="" />` : `<span class="viswiz-node-card-image-placeholder" aria-hidden="true">#${escapeAttribute(id)}</span>`;
        return `<figure class="viswiz-node-image-thumb${isFeatured ? ' is-featured' : ''}" data-viswiz-node-image-id="${escapeAttribute(id)}">${image}<figcaption>${isFeatured ? 'Featured image' : 'Attached image'} <span>#${escapeAttribute(id)}</span></figcaption><div class="viswiz-node-image-actions"><button type="button" class="button button-small" data-viswiz-node-image-replace="${escapeAttribute(id)}">Replace</button><button type="button" class="button button-small" data-viswiz-node-image-edit="${escapeAttribute(id)}">Edit</button><button type="button" class="button button-small button-link-delete" data-viswiz-node-image-remove="${escapeAttribute(id)}">Remove</button></div></figure>`;
      }).join('');
      gallery.innerHTML = `${title}<div class="viswiz-node-image-gallery-grid">${figures}</div>`;
    });
  }

  function updateNodeImageGallery(card, selectedItems = []) {
    const gallery = card?.querySelector('[data-viswiz-node-image-gallery]');
    if (!gallery) return;
    const mainId = card.querySelector('[data-viswiz-main-image-value]')?.value || '';
    const otherIds = (card.querySelector('[data-viswiz-other-images-value]')?.value || '').split(',').filter(Boolean);
    const selectedById = new Map(selectedItems.map((item) => [String(item.id), item]));
    const ids = Array.from(new Set([mainId, ...otherIds].filter(Boolean)));
    const title = gallery.querySelector('strong')?.outerHTML || '<strong>Node images</strong>';
    if (!ids.length) {
      gallery.innerHTML = `${title}<p class="description" data-viswiz-node-image-empty>No images attached to this node.</p>`;
      return;
    }
    gallery.innerHTML = `${title}<p class="description" data-viswiz-node-image-empty>Loading node images…</p>`;
    renderNodeImageGallery(gallery, ids, mainId, selectedById);
  }


  function getNodeImageIds(card) {
    const mainId = card.querySelector('[data-viswiz-main-image-value]')?.value || '';
    const otherIds = (card.querySelector('[data-viswiz-other-images-value]')?.value || '').split(',').filter(Boolean);
    return { mainId, otherIds };
  }

  function setNodeImageIds(card, mainId, otherIds, selectedItems = []) {
    const uniqueOtherIds = Array.from(new Set(otherIds.filter(Boolean).map(String))).filter((id) => id !== String(mainId));
    const mainInput = card.querySelector('[data-viswiz-main-image-value]');
    const otherInput = card.querySelector('[data-viswiz-other-images-value]');
    if (mainInput) mainInput.value = mainId || '';
    if (otherInput) otherInput.value = uniqueOtherIds.join(',');
    const mainLabel = mainInput?.closest('.viswiz-media-field')?.querySelector('[data-viswiz-media-label]');
    const otherLabel = otherInput?.closest('.viswiz-media-field')?.querySelector('[data-viswiz-media-label]');
    if (mainLabel) mainLabel.textContent = mainId ? `#${mainId}` : 'No image selected';
    if (otherLabel) otherLabel.textContent = uniqueOtherIds.length ? uniqueOtherIds.join(',') : 'No images selected';
    updateNodeMainImagePreview(card, mainId, selectedItems);
    updateNodeImageGallery(card, selectedItems);
  }

  function updateNodeMainImagePreview(card, mainId, selectedItems = []) {
    const media = card.querySelector('.viswiz-node-card-media');
    if (!media) return;
    if (!mainId) {
      media.innerHTML = '<span class="viswiz-node-card-image-placeholder" aria-hidden="true">No image</span>';
      return;
    }
    const selectedById = new Map(selectedItems.map((item) => [String(item.id), item]));
    getMediaAttachmentData(mainId, selectedById).then((item) => {
      const url = getMediaThumbUrl(item);
      media.innerHTML = url ? `<img class="viswiz-node-card-image" src="${escapeAttribute(url)}" alt="" />` : `<span class="viswiz-node-card-image-placeholder" aria-hidden="true">#${escapeAttribute(mainId)}</span>`;
    });
  }

  function removeNodeImage(card, imageId) {
    const { mainId, otherIds } = getNodeImageIds(card);
    const nextMainId = String(mainId) === String(imageId) ? '' : mainId;
    setNodeImageIds(card, nextMainId, otherIds.filter((id) => String(id) !== String(imageId)));
  }

  function replaceNodeImage(card, imageId) {
    if (!window.wp || !wp.media) return;
    const frame = wp.media({ title: 'Replace node image', multiple: false, library: { type: 'image' } });
    frame.on('select', function () {
      const selection = frame.state().get('selection').toJSON();
      const replacement = selection[0];
      if (!replacement) return;
      const { mainId, otherIds } = getNodeImageIds(card);
      const replacementId = String(replacement.id);
      const replacingMain = String(mainId) === String(imageId);
      const nextMainId = replacingMain ? replacementId : mainId;
      const nextOtherIds = replacingMain ? otherIds : otherIds.map((id) => String(id) === String(imageId) ? replacementId : id);
      setNodeImageIds(card, nextMainId, nextOtherIds, selection);
    });
    frame.open();
  }

  function editNodeImage(imageId) {
    if (!imageId) return;
    const adminUrl = window.ajaxurl ? window.ajaxurl.replace('admin-ajax.php', `post.php?post=${encodeURIComponent(imageId)}&action=edit`) : `post.php?post=${encodeURIComponent(imageId)}&action=edit`;
    window.open(adminUrl, '_blank', 'noopener');
  }

  function getNextGraphNodeId(container) {
    const ids = Array.from(container.querySelectorAll('[data-viswiz-node-id]')).map((input) => input.value);
    let index = ids.length + 1;
    while (ids.includes('node-' + index)) {
      index += 1;
    }
    return 'node-' + index;
  }function getNodeCardText(card) {
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
  }function updateNodeTypeFilterLabel() {
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

  function buildNodeOptions() {
    return getAllNodeCards().map((card) => {
      const id = card.querySelector('[data-viswiz-node-id]')?.value || '';
      const label = card.querySelector('[name$="[label][]"]')?.value || '';
      const title = card.querySelector('[data-viswiz-node-title]')?.value || label || id;
      return {
        value: title,
        label: id,
        dataset: {
          nodeId: id,
          nodeSearch: [title, label, id].join(' ').toLowerCase(),
          nodeTitle: title.toLowerCase(),
        },
      };
    }).filter((item) => item.dataset.nodeId);
  }

  function refreshNodeDatalist(query = '') {
    const datalist = document.querySelector('[data-viswiz-node-options]');
    if (!datalist) return;
    datalist.innerHTML = '';
    const raw = String(query || '').trim().toLowerCase();
    if (raw.length < 3) return;
    buildNodeOptions()
      .filter((item) => item.dataset.nodeTitle.includes(raw))
      .sort((a, b) => a.value.localeCompare(b.value))
      .forEach((item) => {
        const option = document.createElement('option');
        option.value = item.value;
        option.label = item.label;
        option.dataset.nodeId = item.dataset.nodeId;
        option.dataset.nodeSearch = item.dataset.nodeSearch;
        option.dataset.nodeTitle = item.dataset.nodeTitle;
        option.textContent = item.label;
        datalist.appendChild(option);
      });
  }

  function isRelationNodeInput(input) {
    return input && input.matches('[data-viswiz-relation-from], [data-viswiz-relation-to], [data-viswiz-node-relation-quick="from"], [data-viswiz-node-relation-quick="to"]');
  }

  function getRelationNodeMatches(query) {
    const raw = String(query || '').trim().toLowerCase();
    if (raw.length < 3) return [];
    return buildNodeOptions()
      .filter((item) => item.dataset.nodeTitle.includes(raw))
      .sort((a, b) => a.value.localeCompare(b.value));
  }

  function removeRelationNodeAutocomplete(input) {
    const list = input?.parentNode?.querySelector('[data-viswiz-node-autocomplete-list]');
    if (list) list.remove();
  }

  function formatNodeOptionLabel(item) {
    const title = item?.value || '';
    const id = item?.dataset?.nodeId || '';
    return id && id !== title ? `${title} (${id})` : title || id;
  }

  function buildRelationNodeSelectMarkup(selectedValue = '', quickField = '') {
    const selectedId = getNodeIdForDisplay(selectedValue);
    const quickAttr = quickField ? ` data-viswiz-node-relation-quick="${escapeAttribute(quickField)}"` : '';
    const dataAttr = quickField ? '' : ' data-viswiz-relation-node-select="1"';
    const options = getNodeOptions().sort((a, b) => a.value.localeCompare(b.value)).map((item) => {
      const id = item.dataset.nodeId;
      const selected = id === selectedId ? ' selected' : '';
      return `<option value="${escapeAttribute(id)}" data-node-title="${escapeAttribute(item.value)}"${selected}>${escapeAttribute(formatNodeOptionLabel(item))}</option>`;
    }).join('');
    return `<select class="regular-text"${dataAttr}${quickAttr} data-viswiz-smart-search="node" data-viswiz-smart-placeholder="Search existing node…" data-viswiz-smart-empty="No matching nodes in this dataset."><option value="">Select a node…</option>${options}</select>`;
  }

  function buildNamedRelationNodeSelectMarkup(name, side) {
    return buildRelationNodeSelectMarkup('').replace(
      '<select class="regular-text" data-viswiz-relation-node-select="1"',
      `<select name="${escapeAttribute(name)}" class="regular-text" data-viswiz-relation-${side}="1"`
    );
  }

  function refreshRelationNodeSelectors(scope = document) {
    scope.querySelectorAll('select[data-viswiz-relation-from], select[data-viswiz-relation-to], select[data-viswiz-node-relation-quick="from"], select[data-viswiz-node-relation-quick="to"]').forEach((select) => {
      const selected = select.value;
      const selectedText = select.selectedOptions && select.selectedOptions[0] ? select.selectedOptions[0].textContent : selected;
      const placeholder = select.querySelector('option[value=""]')?.outerHTML || '<option value="">Select a node…</option>';
      const options = getNodeOptions().sort((a, b) => a.value.localeCompare(b.value));
      let foundSelected = !selected;
      let html = placeholder + options.map((item) => {
        const id = item.dataset.nodeId;
        const isSelected = id === selected ? ' selected' : '';
        if (isSelected) foundSelected = true;
        return `<option value="${escapeAttribute(id)}" data-node-title="${escapeAttribute(item.value)}" data-node-search="${escapeAttribute(item.dataset.nodeSearch || '')}"${isSelected}>${escapeAttribute(formatNodeOptionLabel(item))}</option>`;
      }).join('');
      if (selected && !foundSelected) {
        html += `<option value="${escapeAttribute(selected)}" data-node-title="${escapeAttribute(selectedText || selected)}" selected>${escapeAttribute(selectedText || selected)}</option>`;
      }
      select.innerHTML = html;
      if (selected) select.value = selected;
    });
    if (typeof refreshSmartSelects === 'function') refreshSmartSelects(scope);
  }

  function renderRelationNodeAutocomplete(input, matches) {
    removeRelationNodeAutocomplete(input);
    if (!matches.length) return;
    const list = document.createElement('div');
    list.className = 'viswiz-node-autocomplete-list';
    list.dataset.viswizNodeAutocompleteList = '1';
    list.setAttribute('role', 'listbox');
    matches.slice(0, 20).forEach((item) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'viswiz-node-autocomplete-option';
      button.dataset.nodeTitle = item.value;
      button.setAttribute('role', 'option');
      button.innerHTML = `<span>${escapeAttribute(item.value)}</span><code>${escapeAttribute(item.dataset.nodeId)}</code>`;
      list.appendChild(button);
    });
    input.insertAdjacentElement('afterend', list);
  }

  function updateRelationNodeAutocomplete(input) {
    if (!isRelationNodeInput(input)) return;
    const selectedEventCount = parseInt(input.dataset.viswizAutocompleteSelected || '0', 10);
    if (selectedEventCount > 0) {
      input.dataset.viswizAutocompleteSelected = String(selectedEventCount - 1);
      if (selectedEventCount === 1) delete input.dataset.viswizAutocompleteSelected;
      removeRelationNodeAutocomplete(input);
      return;
    }
    const listId = input.dataset.viswizNodeList || input.getAttribute('list') || 'viswiz_visual_relation_nodes';
    input.dataset.viswizNodeList = listId;
    input.removeAttribute('list');
    const matches = getRelationNodeMatches(input.value);
    refreshNodeDatalist(input.value);
    renderRelationNodeAutocomplete(input, matches);
  }

  function initializeRelationNodeAutocomplete(scope = document) {
    scope.querySelectorAll('input[data-viswiz-relation-from], input[data-viswiz-relation-to], input[data-viswiz-node-relation-quick="from"], input[data-viswiz-node-relation-quick="to"]').forEach((input) => {
      if (!input.dataset.viswizNodeList && input.getAttribute('list')) {
        input.dataset.viswizNodeList = input.getAttribute('list');
      }
      updateRelationNodeAutocomplete(input);
    });
    enhanceSmartSelects(scope);
  }

  function getSmartSelectKind(select) {
    return select?.dataset?.viswizSmartSearch || '';
  }

  function getSmartSelectOptionDisplay(option, kind = '') {
    if (!option) return '';
    if (kind === 'node') return option.dataset.nodeTitle || option.textContent || option.value || '';
    return option.dataset.relationLabel || option.textContent || option.value || '';
  }

  function getSmartSelectOptionMeta(option, kind = '') {
    if (!option || !option.value) return '';
    if (kind === 'node') return `ID: ${option.value}`;
    if (kind === 'relation-type') {
      const parts = [];
      if (option.value) parts.push(option.value);
      if (option.dataset.relationDirection) parts.push(option.dataset.relationDirection);
      if (option.dataset.relationSource || option.dataset.relationTarget) {
        parts.push(`${option.dataset.relationSource || 'any'} → ${option.dataset.relationTarget || 'any'}`);
      }
      return parts.join(' · ');
    }
    return option.value || '';
  }

  function getSmartSelectOptionSearch(option, kind = '') {
    if (!option) return '';
    const values = [
      option.value,
      option.label,
      option.textContent,
      option.dataset.nodeTitle,
      option.dataset.nodeSearch,
      option.dataset.relationLabel,
      option.dataset.relationDescription,
      option.dataset.relationDirection,
      option.dataset.relationSource,
      option.dataset.relationSourceSubtype,
      option.dataset.relationTarget,
      option.dataset.relationTargetSubtype,
    ];
    return values.filter(Boolean).join(' ').toLowerCase();
  }

  function getSmartSelectOptions(select, query = '') {
    const kind = getSmartSelectKind(select);
    const raw = String(query || '').trim().toLowerCase();
    return Array.from(select.options || [])
      .filter((option) => option.value !== '')
      .filter((option) => !raw || getSmartSelectOptionSearch(option, kind).includes(raw))
      .sort((a, b) => getSmartSelectOptionDisplay(a, kind).localeCompare(getSmartSelectOptionDisplay(b, kind)));
  }

  function syncSmartSelectInput(select) {
    if (!select || !select.dataset.viswizSmartEnhanced) return;
    const wrapper = select.previousElementSibling?.matches?.('[data-viswiz-smart-select]') ? select.previousElementSibling : null;
    const input = wrapper?.querySelector('[data-viswiz-smart-input]');
    const option = select.selectedOptions && select.selectedOptions[0];
    if (input) input.value = option && option.value ? getSmartSelectOptionDisplay(option, getSmartSelectKind(select)) : '';
  }

  function getSmartSelectWrapper(select) {
    return select?.previousElementSibling?.matches?.('[data-viswiz-smart-select]') ? select.previousElementSibling : null;
  }

  function getSmartMenuOptions(menu) {
    return Array.from(menu?.querySelectorAll('[data-viswiz-smart-value]') || []).filter((option) => !option.disabled && !option.classList.contains('is-empty'));
  }

  function setSmartActiveOption(select, option) {
    const wrapper = getSmartSelectWrapper(select);
    const input = wrapper?.querySelector('[data-viswiz-smart-input]');
    const menu = wrapper?.querySelector('[data-viswiz-smart-menu]');
    if (!menu || !input) return;
    getSmartMenuOptions(menu).forEach((item) => {
      const active = item === option;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', active ? 'true' : 'false');
    });
    if (option && option.id) {
      input.setAttribute('aria-activedescendant', option.id);
      option.scrollIntoView({ block: 'nearest' });
    } else {
      input.removeAttribute('aria-activedescendant');
    }
  }

  function moveSmartActiveOption(select, direction) {
    const wrapper = getSmartSelectWrapper(select);
    const menu = wrapper?.querySelector('[data-viswiz-smart-menu]');
    const options = getSmartMenuOptions(menu);
    if (!options.length) return null;
    const current = options.findIndex((option) => option.classList.contains('is-active'));
    let nextIndex = 0;
    if (direction === 'last') nextIndex = options.length - 1;
    else if (direction === 'first') nextIndex = 0;
    else if (direction > 0) nextIndex = current < 0 ? 0 : Math.min(options.length - 1, current + 1);
    else if (direction < 0) nextIndex = current < 0 ? options.length - 1 : Math.max(0, current - 1);
    const next = options[nextIndex];
    setSmartActiveOption(select, next);
    return next;
  }

  function closeSmartSelectMenu(wrapper) {
    if (!wrapper) return;
    const menu = wrapper.querySelector('[data-viswiz-smart-menu]');
    const input = wrapper.querySelector('[data-viswiz-smart-input]');
    if (menu) {
      menu.hidden = true;
      getSmartMenuOptions(menu).forEach((option) => {
        option.classList.remove('is-active');
        option.setAttribute('aria-selected', 'false');
      });
    }
    if (input) {
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }
  }

  function closeAllSmartSelectMenus(except = null) {
    document.querySelectorAll('[data-viswiz-smart-select]').forEach((wrapper) => {
      if (wrapper !== except) closeSmartSelectMenu(wrapper);
    });
  }

  function renderSmartSelectMenu(select, query = '') {
    const wrapper = getSmartSelectWrapper(select);
    const input = wrapper?.querySelector('[data-viswiz-smart-input]');
    const menu = wrapper?.querySelector('[data-viswiz-smart-menu]');
    if (!wrapper || !input || !menu) return;
    const kind = getSmartSelectKind(select);
    const options = getSmartSelectOptions(select, query);
    closeAllSmartSelectMenus(wrapper);
    menu.innerHTML = '';
    if (!options.length) {
      const empty = document.createElement('button');
      empty.type = 'button';
      empty.className = 'viswiz-smart-option is-empty';
      empty.disabled = true;
      empty.textContent = select.dataset.viswizSmartEmpty || 'No matching values.';
      empty.setAttribute('role', 'option');
      empty.setAttribute('aria-selected', 'false');
      menu.appendChild(empty);
    } else {
      options.slice(0, 40).forEach((option, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'viswiz-smart-option';
        button.dataset.viswizSmartValue = option.value;
        button.id = `${menu.id || 'viswiz-smart-menu'}-option-${index}`;
        button.setAttribute('role', 'option');
        button.setAttribute('aria-selected', 'false');
        const label = getSmartSelectOptionDisplay(option, kind);
        const meta = getSmartSelectOptionMeta(option, kind);
        const description = kind === 'relation-type' ? (option.dataset.relationDescription || '') : '';
        button.innerHTML = `<span class="viswiz-smart-option-label">${escapeAttribute(label)}</span>${meta ? `<span class="viswiz-smart-option-meta">${escapeAttribute(meta)}</span>` : ''}${description ? `<span class="viswiz-smart-option-description">${escapeAttribute(description)}</span>` : ''}`;
        menu.appendChild(button);
      });
    }
    menu.hidden = false;
    input.setAttribute('aria-expanded', 'true');
    const selectedOption = getSmartMenuOptions(menu).find((button) => button.dataset.viswizSmartValue === select.value);
    setSmartActiveOption(select, selectedOption || null);
  }

  function chooseSmartSelectValue(select, value) {
    if (!select) return;
    select.value = value || '';
    syncSmartSelectInput(select);
    select.dispatchEvent(new Event('input', { bubbles: true }));
    select.dispatchEvent(new Event('change', { bubbles: true }));
    closeSmartSelectMenu(getSmartSelectWrapper(select));
  }

  function coerceSmartSelectInput(input, select) {
    const raw = String(input.value || '').trim().toLowerCase();
    if (!raw) {
      chooseSmartSelectValue(select, '');
      return;
    }
    const kind = getSmartSelectKind(select);
    const exact = Array.from(select.options || []).find((option) => {
      if (!option.value) return false;
      return String(option.value).toLowerCase() === raw || getSmartSelectOptionDisplay(option, kind).toLowerCase() === raw;
    });
    if (exact) {
      chooseSmartSelectValue(select, exact.value);
      return;
    }
    const matches = getSmartSelectOptions(select, raw);
    if (matches.length === 1) {
      chooseSmartSelectValue(select, matches[0].value);
      return;
    }
    syncSmartSelectInput(select);
  }

  function enhanceSmartSelect(select) {
    if (!select || select.dataset.viswizSmartEnhanced === '1') {
      if (select) syncSmartSelectInput(select);
      return;
    }
    const kind = getSmartSelectKind(select);
    if (!kind) return;
    const wrapper = document.createElement('span');
    wrapper.className = `viswiz-smart-select viswiz-smart-select-${kind}`;
    wrapper.dataset.viswizSmartSelect = kind;
    const input = document.createElement('input');
    input.type = 'text';
    input.className = 'regular-text viswiz-smart-input';
    input.dataset.viswizSmartInput = kind;
    input.placeholder = select.dataset.viswizSmartPlaceholder || 'Search existing value…';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-expanded', 'false');
    const menu = document.createElement('div');
    menu.className = 'viswiz-smart-menu';
    menu.dataset.viswizSmartMenu = kind;
    menu.hidden = true;
    menu.setAttribute('role', 'listbox');
    menu.id = `viswiz-smart-menu-${Math.random().toString(36).slice(2)}`;
    input.setAttribute('aria-controls', menu.id);
    wrapper.appendChild(input);
    wrapper.appendChild(menu);
    select.parentNode.insertBefore(wrapper, select);
    select.classList.add('viswiz-smart-select-native');
    select.tabIndex = -1;
    select.setAttribute('aria-hidden', 'true');
    select.dataset.viswizSmartEnhanced = '1';
    syncSmartSelectInput(select);
    input.addEventListener('focus', () => renderSmartSelectMenu(select, input.value));
    input.addEventListener('input', () => renderSmartSelectMenu(select, input.value));
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        closeSmartSelectMenu(wrapper);
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      if (event.key === 'ArrowDown' || (event.key === 'Down' || (event.altKey && event.key === 'ArrowDown'))) {
        if (menu.hidden) renderSmartSelectMenu(select, input.value);
        moveSmartActiveOption(select, 1);
        event.preventDefault();
        return;
      }
      if (event.key === 'ArrowUp' || event.key === 'Up') {
        if (menu.hidden) renderSmartSelectMenu(select, input.value);
        moveSmartActiveOption(select, -1);
        event.preventDefault();
        return;
      }
      if (event.key === 'Home' && !event.shiftKey && !event.ctrlKey && !event.metaKey) {
        if (!menu.hidden) {
          moveSmartActiveOption(select, 'first');
          event.preventDefault();
        }
        return;
      }
      if (event.key === 'End' && !event.shiftKey && !event.ctrlKey && !event.metaKey) {
        if (!menu.hidden) {
          moveSmartActiveOption(select, 'last');
          event.preventDefault();
        }
        return;
      }
      if (event.key === 'Enter') {
        const active = menu.querySelector('.viswiz-smart-option.is-active[data-viswiz-smart-value]');
        const first = menu.querySelector('[data-viswiz-smart-value]');
        const pick = active || (!menu.hidden ? first : null);
        if (pick) {
          event.preventDefault();
          chooseSmartSelectValue(select, pick.dataset.viswizSmartValue || '');
        }
      }
    });
    input.addEventListener('blur', () => {
      window.setTimeout(() => coerceSmartSelectInput(input, select), 140);
    });
    menu.addEventListener('mousedown', (event) => event.preventDefault());
    menu.addEventListener('click', (event) => {
      const option = event.target.closest('[data-viswiz-smart-value]');
      if (!option) return;
      chooseSmartSelectValue(select, option.dataset.viswizSmartValue || '');
    });
  }

  function enhanceSmartSelects(scope = document) {
    scope.querySelectorAll('select[data-viswiz-smart-search]').forEach(enhanceSmartSelect);
  }

  function refreshSmartSelects(scope = document) {
    enhanceSmartSelects(scope);
    scope.querySelectorAll('select[data-viswiz-smart-search]').forEach(syncSmartSelectInput);
  }

  function setNodeAutosaveStatus(card, message, state) {
    const status = card?.querySelector('[data-viswiz-node-autosave-status]');
    if (!status) return;
    status.textContent = message || '';
    status.dataset.viswizAutosaveState = state || '';
  }

  function getTextareaSelection(textarea) {
    return {
      start: typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length,
      end: typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : textarea.value.length,
      selected: textarea.value.slice(textarea.selectionStart || 0, textarea.selectionEnd || 0),
    };
  }

  function replaceTextareaSelection(textarea, replacement, selectOffset = null) {
    const selection = getTextareaSelection(textarea);
    const before = textarea.value.slice(0, selection.start);
    const after = textarea.value.slice(selection.end);
    textarea.value = before + replacement + after;
    textarea.focus();
    const cursorStart = selection.start + (selectOffset && typeof selectOffset.start === 'number' ? selectOffset.start : replacement.length);
    const cursorEnd = selection.start + (selectOffset && typeof selectOffset.end === 'number' ? selectOffset.end : cursorStart);
    if (textarea.setSelectionRange) textarea.setSelectionRange(cursorStart, cursorEnd);
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    textarea.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function applyDescriptionFormat(textarea, format) {
    if (!textarea) return;
    const selection = getTextareaSelection(textarea);
    const selected = selection.selected || '';
    let replacement = selected;
    let selectOffset = null;
    if (format === 'strong') {
      replacement = `<strong>${selected || 'bold text'}</strong>`;
      if (!selected) selectOffset = { start: 8, end: 17 };
    } else if (format === 'em') {
      replacement = `<em>${selected || 'italic text'}</em>`;
      if (!selected) selectOffset = { start: 4, end: 15 };
    } else if (format === 'link') {
      const url = window.prompt(t('descriptionLinkUrl', 'Enter URL'), 'https://');
      if (!url) return;
      const label = selected || url;
      replacement = `<a href="${escapeAttribute(url)}">${label}</a>`;
    } else if (format === 'ul') {
      const lines = (selected || 'First item\nSecond item').split(/\r?\n/).filter(Boolean);
      replacement = `<ul>\n${lines.map((line) => `  <li>${line}</li>`).join('\n')}\n</ul>`;
    } else if (format === 'blockquote') {
      replacement = `<blockquote>${selected || 'Quoted text'}</blockquote>`;
      if (!selected) selectOffset = { start: 12, end: 23 };
    } else {
      replacement = `<p>${selected || 'Paragraph text'}</p>`;
      if (!selected) selectOffset = { start: 3, end: 17 };
    }
    replaceTextareaSelection(textarea, replacement, selectOffset);
  }

  function makeEditorPlaceholderId(type) {
    return `${type}-${Date.now()}-${Math.random().toString(36).slice(2)}`;
  }

  function findEditorPlaceholder(card, fallbackSelector) {
    const placeholderId = card?.dataset?.viswizModalPlaceholderId || '';
    if (placeholderId) {
      const specific = document.querySelector(`[data-viswiz-placeholder-id="${cssEscape(placeholderId)}"]`);
      if (specific) return specific;
    }
    return document.querySelector(fallbackSelector);
  }

  function restoreEditorCard(card, placeholder, fallbackSelector) {
    if (!card) return false;
    if (placeholder && placeholder.parentNode) {
      placeholder.parentNode.insertBefore(card, placeholder);
      placeholder.remove();
      return true;
    }
    const fallback = fallbackSelector ? document.querySelector(fallbackSelector) : null;
    if (fallback) {
      fallback.appendChild(card);
      return true;
    }
    return false;
  }

  function syncEditorModalState() {
    document.querySelectorAll('[data-viswiz-editor-modal]').forEach((modal) => {
      const hasEditingCard = modal.querySelector('[data-viswiz-node-card].is-editing, [data-viswiz-relation-card].is-editing');
      if (!hasEditingCard) modal.remove();
    });
    if (!document.querySelector('[data-viswiz-editor-modal], [data-viswiz-node-card].is-editing, [data-viswiz-relation-card].is-editing')) {
      document.body.classList.remove('viswiz-node-modal-open');
    }
  }

  function createEditorModalShell(type, closeLabel = 'Close modal') {
    const modal = document.createElement('div');
    modal.className = 'viswiz-node-editor-modal';
    modal.dataset.viswizEditorModal = '1';
    if (type === 'node') modal.dataset.viswizNodeEditorModal = '1';
    if (type === 'relation') modal.dataset.viswizRelationEditorModal = '1';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-modal', 'true');

    const backdrop = document.createElement('div');
    backdrop.className = 'viswiz-node-editor-modal-backdrop';
    backdrop.dataset.viswizModalDismiss = '1';
    modal.appendChild(backdrop);

    const modalFrame = document.createElement('div');
    modalFrame.className = 'viswiz-node-editor-modal-frame';
    modal.appendChild(modalFrame);

    const modalContent = document.createElement('div');
    modalContent.className = 'viswiz-node-editor-modal-content';
    modalFrame.appendChild(modalContent);

    const hint = document.createElement('p');
    hint.className = 'viswiz-keyboard-hint screen-reader-text';
    hint.id = `viswiz-modal-keyboard-hint-${Math.random().toString(36).slice(2)}`;
    hint.textContent = 'Keyboard shortcuts: Tab moves between fields, Escape closes, Ctrl or Command plus Enter saves and closes. In searchable dropdowns, use arrow keys and Enter to select.';
    modalContent.appendChild(hint);
    modal.setAttribute('aria-describedby', hint.id);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button-link media-modal-close viswiz-modal-close-button';
    button.dataset.viswizModalDismiss = '1';
    button.setAttribute('aria-label', closeLabel || t('closeModal', 'Close modal'));
    button.title = closeLabel || t('closeModal', 'Close modal');
    button.innerHTML = '<span class="media-modal-icon" aria-hidden="true"></span>';
    modalContent.appendChild(button);

    return { modal, modalContent };
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
    const placeholderId = makeEditorPlaceholderId('node');
    placeholder.dataset.viswizPlaceholderId = placeholderId;
    card.dataset.viswizModalPlaceholderId = placeholderId;
    placeholder.setAttribute('aria-hidden', 'true');
    const summary = card.querySelector('summary');
    placeholder.innerHTML = summary ? summary.outerHTML : '<summary><strong>Editing node…</strong></summary>';
    card.parentNode.insertBefore(placeholder, card);

    const originalNodeId = card.querySelector('[data-viswiz-node-id]')?.value || '';
    card.dataset.viswizOriginalNodeId = originalNodeId;

    const { modal, modalContent } = createEditorModalShell('node', t('closeNodeEditor', 'Close node editor'));
    form.appendChild(modal);
    modalContent.appendChild(card);

    card.open = true;
    card.classList.add('is-editing');
    card.dataset.viswizModalPlaceholder = '1';
    document.body.classList.add('viswiz-node-modal-open');
    refreshNodeRelationTools();
    window.setTimeout(() => {
      initNodeDescriptionEditor(card);
      focusFirstModalControl(modal);
      delete card.dataset.viswizOpening;
    }, 0);
  }

  function closeNodeModal(card) {
    if (!card) return;
    card.dataset.viswizClosing = '1';
    const modal = card.closest('[data-viswiz-node-editor-modal]');
    const placeholder = findEditorPlaceholder(card, '[data-viswiz-node-placeholder]');
    destroyNodeDescriptionEditor(card);
    card.open = false;
    card.classList.remove('is-editing');
    delete card.dataset.viswizModalPlaceholder;
    delete card.dataset.viswizModalPlaceholderId;
    restoreEditorCard(card, placeholder, '[data-viswiz-node-list]');
    if (modal && modal.parentNode) modal.remove();
    syncEditorModalState();
    const restoreTarget = card.querySelector('summary, [data-viswiz-node-title], button');
    if (restoreTarget && typeof restoreTarget.focus === 'function') window.setTimeout(() => restoreTarget.focus(), 0);
    window.setTimeout(() => {
      delete card.dataset.viswizClosing;
    }, 0);
  }

  function autosaveGraphDataAndClose(card, closeCallback, options = {}) {
    if (!card) return;
    const form = card.closest('form');
    const postId = (window.VisWizAdmin && VisWizAdmin.postId) || document.getElementById('post_ID')?.value || 0;
    if (!form || !window.fetch || !window.VisWizAdmin || !VisWizAdmin.ajaxUrl || !postId) {
      if (form) {
        form.requestSubmit ? form.requestSubmit() : form.submit();
      }
      return;
    }
    if (options.context === 'node') {
      syncNodeDescriptionEditor(card);
    }
    if (window.tinyMCE && tinyMCE.triggerSave) {
      tinyMCE.triggerSave();
    }
    if (options.setStatus) options.setStatus(t('autosaving', 'Autosaving…'), 'saving');
    const formData = new FormData();
    formData.set('action', 'viswiz_autosave_graph_node');
    formData.set('nonce', VisWizAdmin.nonce || '');
    formData.set('post_id', postId);
    const autosaveContext = options.context || '';
    formData.set('viswiz_autosave_context', autosaveContext);
    if (autosaveContext === 'node') {
      formData.set('viswiz_node_original_id', card.dataset.viswizOriginalNodeId || card.querySelector('[data-viswiz-node-id]')?.value || '');
      card.querySelectorAll('[name^="viswiz_meta[graph_data][nodes]"]').forEach((field) => {
        if (field.disabled || ((field.type === 'checkbox' || field.type === 'radio') && !field.checked)) return;
        formData.append(field.name, field.value);
      });
    } else {
      form.querySelectorAll('[name^="viswiz_meta[graph_data]"]').forEach((field) => {
        if (field.disabled || ((field.type === 'checkbox' || field.type === 'radio') && !field.checked)) return;
        formData.append(field.name, field.value);
      });
    }
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
        if (options.setStatus) options.setStatus(t('autosaved', 'Autosaved.'), 'saved');
        if (autosaveContext === 'node') {
          card.dataset.viswizOriginalNodeId = card.querySelector('[data-viswiz-node-id]')?.value || '';
          destroyNodeDescriptionEditor(card);
        }
        closeCallback(card);
      })
      .catch(() => {
        if (options.setStatus) options.setStatus(t('autosaveFailed', 'Autosave failed. Use Save to retry.'), 'error');
      });
  }

  function autosaveNodeAndClose(card) {
    autosaveGraphDataAndClose(card, closeNodeModal, {
      context: 'node',
      setStatus: (message, state) => setNodeAutosaveStatus(card, message, state),
    });
  }

  function autosaveRelationAndClose(card) {
    autosaveGraphDataAndClose(card, closeRelationModal, { context: 'relation' });
  }

  function getRelationNodeIds(relationCard) {
    return {
      from: relationCard.querySelector('[data-viswiz-relation-from]')?.value || '',
      to: relationCard.querySelector('[data-viswiz-relation-to]')?.value || '',
    };
  }

  function getNodeOptions() {
    return buildNodeOptions();
  }

  function findNodeOptionByText(value) {
    const raw = String(value || '').trim().toLowerCase();
    if (!raw) return null;
    const options = getNodeOptions();
    return options.find((item) => (item.value || '').toLowerCase() === raw || (item.dataset.nodeId || '').toLowerCase() === raw) || null;
  }

  function findAutocompleteNodeOption(value) {
    const raw = String(value || '').trim().toLowerCase();
    if (!raw) return null;
    const exact = findNodeOptionByText(value);
    if (exact) return exact;
    const options = getNodeOptions();
    const startsWith = options.filter((item) => (item.value || '').toLowerCase().startsWith(raw));
    if (startsWith.length === 1) return startsWith[0];
    const contains = options.filter((item) => (item.dataset.nodeSearch || item.value || '').toLowerCase().includes(raw));
    return contains.length === 1 ? contains[0] : null;
  }

  function autocompleteRelationNodeInput(input) {
    if (!input || !isRelationNodeInput(input)) return;
    const option = findAutocompleteNodeOption(input.value);
    if (option) {
      input.value = option.value;
    }
  }

  function getNodeIdForDisplay(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const option = findNodeOptionByText(raw);
    return option?.dataset.nodeId || raw;
  }

  function getNodeDisplayForId(id) {
    const raw = String(id || '').trim();
    if (!raw) return '';
    const option = findNodeOptionByText(raw);
    return option?.value || raw;
  }function updateRelationCardDataset(relationCard) {
    const ids = getRelationNodeIds(relationCard);
    relationCard.dataset.relationFrom = getNodeIdForDisplay(ids.from);
    relationCard.dataset.relationTo = getNodeIdForDisplay(ids.to);
    updateRelationSummary(relationCard);
  }

  function openRelationModal(card) {
    if (!card || card.classList.contains('is-editing')) return;
    const form = card.closest('form');
    if (!form) return;
    document.querySelectorAll('[data-viswiz-relation-card].is-editing').forEach((openCard) => {
      if (openCard !== card) closeRelationModal(openCard);
    });
    const placeholder = document.createElement('div');
    placeholder.className = 'viswiz-relation-card viswiz-relation-card-placeholder';
    placeholder.dataset.viswizRelationPlaceholder = '1';
    const placeholderId = makeEditorPlaceholderId('relation');
    placeholder.dataset.viswizPlaceholderId = placeholderId;
    card.dataset.viswizModalPlaceholderId = placeholderId;
    placeholder.setAttribute('aria-hidden', 'true');
    const summary = card.querySelector('summary');
    placeholder.innerHTML = summary ? summary.outerHTML : '<summary><strong>Editing relation…</strong></summary>';
    card.parentNode.insertBefore(placeholder, card);
    const { modal, modalContent } = createEditorModalShell('relation', t('closeRelationEditor', 'Close relation editor'));
    form.appendChild(modal);
    modalContent.appendChild(card);
    card.open = true;
    card.classList.add('is-editing');
    document.body.classList.add('viswiz-node-modal-open');
  }

  function closeRelationModal(card) {
    if (!card) return;
    const modal = card.closest('[data-viswiz-relation-editor-modal]');
    const placeholder = findEditorPlaceholder(card, '[data-viswiz-relation-placeholder]');
    card.open = false;
    card.classList.remove('is-editing');
    delete card.dataset.viswizModalPlaceholderId;
    restoreEditorCard(card, placeholder, '[data-viswiz-relation-list]');
    if (modal && modal.parentNode) modal.remove();
    syncEditorModalState();
  }



  function cleanupAfterEditorDeletion(modal, placeholder) {
    if (placeholder && placeholder.parentNode) {
      placeholder.remove();
    }
    if (modal && modal.parentNode) {
      modal.remove();
    }
    syncEditorModalState();
    removeRelationNodeAutocomplete(document.activeElement);
    refreshNodeDatalist();
    initializeRelationNodeAutocomplete();
    refreshSmartSelects(document);
    refreshNodeRelationTools();
    buildNodeTypeFilterOptions();
    filterNodeList();
    refreshNodeTypeManager();
    validateGraph();
    markVisWizDirty();
  }

  function deleteEditingNodeCard(card) {
    if (!card) return false;
    const nodeId = card.querySelector('[data-viswiz-node-id]')?.value || '';
    const title = card.querySelector('[data-viswiz-node-title]')?.value || nodeId || 'this node';
    const related = getAllRelationCards().filter((relationCard) => {
      const ids = getRelationNodeIds(relationCard);
      return getNodeIdForDisplay(ids.from) === nodeId || getNodeIdForDisplay(ids.to) === nodeId;
    });
    const message = related.length
      ? `Delete “${title}” and its ${related.length} relation${related.length === 1 ? '' : 's'} from this dataset?`
      : `Delete “${title}” from this dataset?`;
    if (!window.confirm(message)) return true;
    const modal = card.closest('[data-viswiz-node-editor-modal]');
    const placeholder = findEditorPlaceholder(card, '[data-viswiz-node-placeholder]');
    destroyNodeDescriptionEditor(card);
    related.forEach((relationCard) => relationCard.remove());
    card.remove();
    cleanupAfterEditorDeletion(modal, placeholder);
    return true;
  }

  function deleteEditingRelationCard(card) {
    if (!card) return false;
    if (!window.confirm('Delete this relation from the dataset?')) return true;
    const modal = card.closest('[data-viswiz-relation-editor-modal]');
    const placeholder = findEditorPlaceholder(card, '[data-viswiz-relation-placeholder]');
    card.remove();
    cleanupAfterEditorDeletion(modal, placeholder);
    return true;
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

  function closeNodeRelationEditor(editor) {
    if (!editor) return;
    editor.hidden = true;
    editor.innerHTML = '';
    delete editor.dataset.relationIndex;
  }

  function getRelationFromNodeSide(relationCard, nodeId) {
    const ids = getRelationNodeIds(relationCard);
    if (getNodeIdForDisplay(ids.from) === nodeId) return 'from';
    if (getNodeIdForDisplay(ids.to) === nodeId) return 'to';
    return '';
  }

  function removeRelationFromNode(editor, nodeId) {
    const relationIndex = parseInt(editor?.dataset.relationIndex, 10);
    const relation = getAllRelationCards()[relationIndex];
    if (!relation || !nodeId) return;
    const side = getRelationFromNodeSide(relation, nodeId);
    const target = side ? relation.querySelector(side === 'from' ? '[data-viswiz-relation-from]' : '[data-viswiz-relation-to]') : null;
    if (target) {
      target.value = '';
      updateRelationCardDataset(relation);
    }
    closeNodeRelationEditor(editor);
    refreshNodeRelationTools();
  }

  function deleteNodeRelation(editor) {
    const relationIndex = parseInt(editor?.dataset.relationIndex, 10);
    const relation = getAllRelationCards()[relationIndex];
    if (relation) relation.remove();
    closeNodeRelationEditor(editor);
    refreshNodeRelationTools();
  }

  function slugifyNodeTypeLabel(label) {
    return String(label || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'custom_subtype';
  }

  function getLinkedNodeCount(type, subtype = null) {
    return getAllNodeCards().filter((card) => {
      const cardType = card.querySelector('[data-viswiz-node-type]')?.value || '';
      const cardSubtype = card.querySelector('[data-viswiz-node-subtype]')?.value || '';
      return cardType === type && (subtype === null || cardSubtype === subtype);
    }).length;
  }

  function warnLinkedNodes(action, type, subtype = null) {
    const count = getLinkedNodeCount(type, subtype);
    if (!count) return true;
    const label = subtype ? `${type} / ${subtype}` : type;
    return window.confirm(`${count} linked node${count === 1 ? '' : 's'} use ${label}. ${action} will update those node assignments. Continue?`);
  }

  function setNodeSubtypeOption(type, subtype, label) {
    if (!type || !subtype || !label) return;
    if (!graphNodeSubtypes[type]) graphNodeSubtypes[type] = [];
    const entries = getSubtypeEntries(type);
    const existing = entries.find((item) => item.value === subtype);
    if (existing) existing.label = label;
    else entries.push({ value: subtype, label });
    graphNodeSubtypes[type] = entries;
    document.querySelectorAll('[data-viswiz-node-card]').forEach(updateNodeSubtypeOptions);
  }

  function deleteNodeTypeAssignment(type) {
    if (!warnLinkedNodes('Deleting this type', type, null)) return;
    getAllNodeCards().forEach((card) => {
      const typeSelect = card.querySelector('[data-viswiz-node-type]');
      const subtypeSelect = card.querySelector('[data-viswiz-node-subtype]');
      if (typeSelect && typeSelect.value === type) {
        typeSelect.value = '';
        if (subtypeSelect) subtypeSelect.value = '';
        updateNodeSummary(card);
      }
    });
    refreshNodeTypeManager();
    buildNodeTypeFilterOptions();
    filterNodeList();
  }

  function deleteNodeSubtypeAssignment(type, subtype) {
    if (!warnLinkedNodes('Deleting this subtype', type, subtype)) return;
    getAllNodeCards().forEach((card) => {
      const typeSelect = card.querySelector('[data-viswiz-node-type]');
      const subtypeSelect = card.querySelector('[data-viswiz-node-subtype]');
      if (typeSelect?.value === type && subtypeSelect?.value === subtype) {
        subtypeSelect.value = '';
        updateNodeSummary(card);
      }
    });
    refreshNodeTypeManager();
    buildNodeTypeFilterOptions();
    filterNodeList();
  }function appendCountText(parent, count, singular) {
    const span = document.createElement('span');
    span.className = 'description';
    span.textContent = `${count} ${singular}${count === 1 ? '' : 's'}`;
    parent.appendChild(span);
  }

  function createTypeActionButton(label, datasetKey, datasetValue, extraDataset = {}) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = label.startsWith('Delete') ? 'button button-small button-link-delete' : 'button button-small';
    button.textContent = label;
    button.dataset[datasetKey] = datasetValue;
    Object.entries(extraDataset).forEach(([key, value]) => {
      button.dataset[key] = value;
    });
    return button;
  }function getRelationTypeMeta(slug) {
    const source = relationTypes.length ? relationTypes : [
      { slug: 'member_of', label: 'Member of', direction: 'directed', inverse_label: 'Has member', default_intensity: 1, source_type: 'person', target_type: 'organization' },
      { slug: 'leader_of', label: 'Leader of', direction: 'directed', inverse_label: 'Led by', default_intensity: 1, source_type: 'person', target_type: 'organization' },
      { slug: 'connected_to', label: 'Connected to', direction: 'undirected', inverse_label: 'Connected to', default_intensity: 1 },
    ];
    return source.find((item) => (item.slug || item.value || '') === slug) || null;
  }

  function getRelationTypeLabel(slug) {
    const meta = getRelationTypeMeta(slug);
    return meta ? (meta.label || slug) : (slug || 'Relation');
  }

  function getGraphContext(kind) {
    const isVisual = document.getElementById(kind === 'nodes' ? 'viswiz-visual-graph-nodes' : 'viswiz-visual-graph-links');
    if (kind === 'nodes') {
      return isVisual ? { id: 'viswiz-visual-graph-nodes', prefix: 'viswiz_meta[graph_data][nodes]' } : { id: 'viswiz-graph-nodes', prefix: 'viswiz_graph_data[nodes]' };
    }
    return isVisual ? { id: 'viswiz-visual-graph-links', prefix: 'viswiz_meta[graph_data][links]' } : { id: 'viswiz-graph-links', prefix: 'viswiz_graph_data[links]' };
  }

  function greeklish(value) {
    const map = {
      'α':'a','ά':'a','β':'v','γ':'g','δ':'d','ε':'e','έ':'e','ζ':'z','η':'i','ή':'i','θ':'th','ι':'i','ί':'i','ϊ':'i','ΐ':'i','κ':'k','λ':'l','μ':'m','ν':'n','ξ':'x','ο':'o','ό':'o','π':'p','ρ':'r','σ':'s','ς':'s','τ':'t','υ':'y','ύ':'y','ϋ':'y','ΰ':'y','φ':'f','χ':'ch','ψ':'ps','ω':'o','ώ':'o'
    };
    return String(value || '').toLowerCase().split('').map((ch) => map[ch] || ch).join('');
  }

  function slugifyNodeTitle(title) {
    const ascii = greeklish(title).normalize ? greeklish(title).normalize('NFD').replace(/[\u0300-\u036f]/g, '') : greeklish(title);
    return ascii.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'node';
  }

  function getAllNodeCards() {
    return Array.from(document.querySelectorAll('[data-viswiz-node-card]')).filter((card) => {
      if (!card || card.dataset.viswizNodePlaceholder === '1') return false;
      if (card.classList.contains('viswiz-node-card-placeholder')) return false;
      return !!card.querySelector('[data-viswiz-node-id]');
    });
  }

  function getAllRelationCards() {
    return Array.from(document.querySelectorAll('[data-viswiz-relation-card]')).filter((card) => {
      return !!card && !!card.querySelector('[data-viswiz-relation-from], [data-viswiz-relation-to]');
    });
  }

  function uniqueNodeId(base, ownCard = null) {
    const taken = new Set(getAllNodeCards().filter((card) => card !== ownCard).map((card) => (card.querySelector('[data-viswiz-node-id]')?.value || '').toLowerCase()).filter(Boolean));
    let candidate = slugifyNodeTitle(base);
    let suffix = 2;
    while (taken.has(candidate.toLowerCase())) {
      candidate = `${slugifyNodeTitle(base)}-${suffix}`;
      suffix += 1;
    }
    return candidate;
  }

  function renameNodeId(card, nextId) {
    const input = card?.querySelector('[data-viswiz-node-id]');
    if (!input) return '';
    const oldId = input.value || '';
    const clean = uniqueNodeId(nextId, card);
    if (!clean || clean === oldId) return clean;
    input.value = clean;
    const display = card.querySelector('[data-viswiz-node-id-display]');
    if (display) display.textContent = clean;
    document.querySelectorAll('[data-viswiz-relation-from], [data-viswiz-relation-to], [data-viswiz-node-relation-quick="from"], [data-viswiz-node-relation-quick="to"]').forEach((field) => {
      if (field.value === oldId) field.value = clean;
    });
    refreshNodeDatalist();
    refreshRelationNodeSelectors();
    refreshNodeRelationTools();
    validateGraph();
    return clean;
  }

  function maybeAutoAssignNodeId(card) {
    const input = card?.querySelector('[data-viswiz-node-id]');
    const title = card?.querySelector('[data-viswiz-node-title]')?.value || '';
    if (!input || !title.trim()) return;
    /*
     * Keep node IDs stable after creation. Earlier builds regenerated IDs from
     * titles while the node editor was open; that made existing relations point
     * to stale endpoint IDs after a simple title/description update. Editors can
     * still intentionally rename an ID with the explicit “Edit ID” control, which
     * remaps relations in the DOM before save.
     */
    const current = input.value || '';
    if (current) return;
    input.dataset.viswizAutoId = '1';
    renameNodeId(card, title);
  }

  function getNodeTypeForId(id) {
    const card = getAllNodeCards().find((nodeCard) => (nodeCard.querySelector('[data-viswiz-node-id]')?.value || '') === id);
    return card?.querySelector('[data-viswiz-node-type]')?.value || '';
  }

  function getRelationDirectionGlyph(direction) {
    if (direction === 'undirected') return '—';
    if (direction === 'bidirectional') return '↔';
    return '→';
  }

  function getRelationDirection(relationCard) {
    return relationCard?.querySelector('select[name$="[direction][]"]')?.value || 'directed';
  }

  function setRelationTypeDefaults(relationCard, force = false) {
    if (!relationCard) return;
    const typeSelect = relationCard.querySelector('[data-viswiz-relation-type-select], [data-viswiz-node-relation-quick="relation_type"]');
    const meta = getRelationTypeMeta(typeSelect?.value || '');
    if (!meta) return;
    const direction = relationCard.querySelector('select[name$="[direction][]"]');
    const intensity = relationCard.querySelector('input[name$="[intensity][]"]');
    const label = relationCard.querySelector('input[name$="[label][]"]');
    if (direction && (force || !direction.value || direction.dataset.viswizTouched !== '1')) direction.value = meta.direction || 'directed';
    if (intensity && (force || !intensity.value || intensity.dataset.viswizTouched !== '1')) intensity.value = meta.default_intensity != null ? meta.default_intensity : 1;
    if (label && (force || !label.value)) label.value = meta.label || '';
    updateRelationCardDataset(relationCard);
    refreshNodeRelationTools();
    validateGraph();
  }function updateRelationSummary(relationCard) {
    if (!relationCard) return;
    const relationType = relationCard.querySelector('[data-viswiz-relation-type-select]')?.value || '';
    const label = relationCard.querySelector('input[name$="[label][]"]')?.value || getRelationTypeLabel(relationType) || 'Relation';
    const from = relationCard.querySelector('[data-viswiz-relation-from]')?.value || '';
    const to = relationCard.querySelector('[data-viswiz-relation-to]')?.value || '';
    const direction = getRelationDirection(relationCard);
    const title = relationCard.querySelector('summary strong');
    const meta = relationCard.querySelector('.viswiz-relation-card-summary-meta');
    if (title) title.textContent = label;
    if (meta) meta.textContent = `${getNodeDisplayForId(from) || '…'} ${getRelationDirectionGlyph(direction)} ${getNodeDisplayForId(to) || '…'}`;
    const warning = relationCard.querySelector('[data-viswiz-relation-warning]');
    if (warning) warning.textContent = getRelationWarning(relationCard);
  }function getNodeDuplicateCandidates(card) {
    const title = (card.querySelector('[data-viswiz-node-title]')?.value || '').trim().toLowerCase();
    if (title.length < 3) return [];
    return getAllNodeCards().filter((other) => {
      if (other === card) return false;
      const otherTitle = (other.querySelector('[data-viswiz-node-title]')?.value || '').trim().toLowerCase();
      return otherTitle && (otherTitle === title || otherTitle.includes(title) || title.includes(otherTitle));
    });
  }

  function renderNodeDuplicateHints(card) {
    const box = card.querySelector('[data-viswiz-node-duplicates]');
    if (!box) return;
    const dupes = getNodeDuplicateCandidates(card);
    box.innerHTML = '';
    box.hidden = !dupes.length;
    if (!dupes.length) return;
    const title = document.createElement('strong');
    title.textContent = 'Possible duplicate nodes';
    box.appendChild(title);
    dupes.slice(0, 5).forEach((dupe) => {
      const row = document.createElement('p');
      const dupeId = dupe.querySelector('[data-viswiz-node-id]')?.value || '';
      const dupeTitle = dupe.querySelector('[data-viswiz-node-title]')?.value || dupeId;
      row.innerHTML = `<span>${escapeAttribute(dupeTitle)}</span> <code>${escapeAttribute(dupeId)}</code> `;
      const use = document.createElement('button');
      use.type = 'button';
      use.className = 'button button-small';
      use.textContent = 'Use existing';
      use.dataset.viswizUseExistingNode = dupeId;
      row.appendChild(use);
      box.appendChild(row);
    });
  }

  function renderNodeValidation(card) {
    const box = card.querySelector('[data-viswiz-node-validation]');
    if (!box) return;
    const id = card.querySelector('[data-viswiz-node-id]')?.value || '';
    const title = card.querySelector('[data-viswiz-node-title]')?.value || '';
    const type = card.querySelector('[data-viswiz-node-type]')?.value || '';
    const relCount = Array.from(getAllRelationCards()).filter((rel) => {
      const ids = getRelationNodeIds(rel);
      return ids.from === id || ids.to === id;
    }).length;
    const issues = [];
    if (!title.trim()) issues.push('Missing title');
    if (!type) issues.push('No node type');
    if (!relCount) issues.push('No relations');
    const dupes = getNodeDuplicateCandidates(card);
    if (dupes.length) issues.push(`${dupes.length} possible duplicate${dupes.length === 1 ? '' : 's'}`);
    box.className = `viswiz-node-validation ${issues.length ? 'has-warnings' : 'is-clean'}`;
    box.textContent = issues.length ? `Validation: ${issues.join(' · ')}` : 'Validation: title, type and relation checks look OK.';
  }

  function validateGraph() {
    const panel = document.querySelector('[data-viswiz-graph-validation-summary]');
    const nodes = getAllNodeCards();
    const nodeIds = nodes.map((card) => card.querySelector('[data-viswiz-node-id]')?.value || '').filter(Boolean);
    const nodeSet = new Set(nodeIds);
    const issues = [];
    const duplicateIds = nodeIds.filter((id, i) => nodeIds.indexOf(id) !== i);
    if (duplicateIds.length) issues.push(`${new Set(duplicateIds).size} duplicate node ID${duplicateIds.length === 1 ? '' : 's'}`);
    const titleMap = new Map();
    nodes.forEach((card) => {
      const title = (card.querySelector('[data-viswiz-node-title]')?.value || '').trim().toLowerCase();
      if (!title) issues.push('A node is missing a title');
      else titleMap.set(title, (titleMap.get(title) || 0) + 1);
      if (!card.querySelector('[data-viswiz-node-type]')?.value) issues.push(`“${card.querySelector('[data-viswiz-node-title]')?.value || 'Untitled node'}” has no type`);
      renderNodeDuplicateHints(card);
      renderNodeValidation(card);
    });
    Array.from(titleMap.entries()).filter(([, count]) => count > 1).forEach(([title]) => issues.push(`Possible duplicate title: ${title}`));
    const relationKeys = new Set();
    getAllRelationCards().forEach((rel) => {
      const ids = getRelationNodeIds(rel);
      const relType = rel.querySelector('[data-viswiz-relation-type-select]')?.value || '';
      const key = `${ids.from}|${relType}|${ids.to}`;
      if (!ids.from || !nodeSet.has(ids.from)) issues.push(`Relation has missing source: ${ids.from || 'empty'}`);
      if (!ids.to || !nodeSet.has(ids.to)) issues.push(`Relation has missing target: ${ids.to || 'empty'}`);
      if (relationKeys.has(key)) issues.push(`Duplicate relation: ${ids.from} → ${ids.to}`);
      relationKeys.add(key);
      const warning = getRelationWarning(rel);
      if (warning) issues.push(`${relType || 'Relation'}: ${warning}`);
    });
    if (panel) {
      const uniqueIssues = Array.from(new Set(issues));
      panel.className = uniqueIssues.length ? 'viswiz-validation-summary has-warnings' : 'viswiz-validation-summary is-clean';
      panel.innerHTML = uniqueIssues.length ? `<strong>${uniqueIssues.length} warning${uniqueIssues.length === 1 ? '' : 's'}:</strong><ul>${uniqueIssues.slice(0, 12).map((msg) => `<li>${escapeAttribute(msg)}</li>`).join('')}</ul>` : 'No obvious node/relation issues found.';
    }
  }

  function enhanceNodeCard(card) {
    if (!card || card.dataset.viswizEnhancedNode === '1') return;
    card.dataset.viswizEnhancedNode = '1';
    const summary = card.querySelector('summary');
    if (summary && !summary.querySelector('[data-viswiz-edit-node-id]')) {
      const editId = document.createElement('button');
      editId.type = 'button';
      editId.className = 'button button-small viswiz-edit-node-id';
      editId.dataset.viswizEditNodeId = '1';
      editId.textContent = 'Edit ID';
      summary.appendChild(editId);
    }
    if (!card.querySelector('[data-viswiz-node-duplicates]')) {
      const dupes = document.createElement('div');
      dupes.className = 'viswiz-node-duplicates';
      dupes.dataset.viswizNodeDuplicates = '1';
      dupes.hidden = true;
      const grid = card.querySelector('.viswiz-node-grid');
      if (grid) grid.insertAdjacentElement('afterend', dupes);
    }
    if (!card.querySelector('[data-viswiz-node-validation]')) {
      const validation = document.createElement('div');
      validation.dataset.viswizNodeValidation = '1';
      validation.className = 'viswiz-node-validation';
      const tools = card.querySelector('[data-viswiz-node-relation-tools]');
      if (tools) tools.insertAdjacentElement('beforebegin', validation);
    }
    const tools = card.querySelector('[data-viswiz-node-relation-tools]');
    if (tools && !tools.querySelector('[data-viswiz-node-add-connected]')) {
      const toolbar = document.createElement('div');
      toolbar.className = 'viswiz-node-relation-toolbar';
      toolbar.innerHTML = `
        <button type="button" class="button" data-viswiz-node-add-relation="outgoing">Add outgoing relation</button>
        <button type="button" class="button" data-viswiz-node-add-relation="incoming">Add incoming relation</button>
        <button type="button" class="button button-secondary" data-viswiz-node-add-connected>Create connected node</button>
      `;
      const old = tools.querySelector('[data-viswiz-node-add-relation]');
      if (old) old.remove();
      tools.appendChild(toolbar);
    }
    updateNodeSummary(card);
  }

  function enhanceAllNodeCards() {
    getAllNodeCards().forEach(enhanceNodeCard);
    validateGraph();
  }

  function addGraphNode(containerId, namePrefix, defaults = {}) {
    const container = document.getElementById(containerId);
    if (!container) return null;
    const index = container.querySelectorAll('[data-viswiz-node-card]').length;
    const id = uniqueNodeId(defaults.id || defaults.title || `node-${index + 1}`);
    const editorId = 'viswiz_node_desc_dynamic_' + Date.now();
    const row = document.createElement('details');
    row.className = 'viswiz-node-card viswiz-sortable-card';
    row.open = false;
    row.dataset.viswizNodeCard = '1';
    row.dataset.nodeIndex = index;
    row.dataset.viswizNodeSearchText = (defaults.title || 'new node').toLowerCase();
    row.dataset.viswizNodeTypeValue = defaults.node_type || '';
    row.dataset.viswizNodeSubtypeValue = defaults.node_subtype || '';
    row.innerHTML = `
      <summary><strong>${escapeAttribute(defaults.title || 'New node')}</strong> <span class="viswiz-node-card-summary-meta">No type</span> <code data-viswiz-node-id-display>${escapeAttribute(id)}</code></summary>
      <div class="viswiz-node-card-media"><span class="viswiz-node-card-image-placeholder" aria-hidden="true">No image</span></div>
      <input type="hidden" name="${namePrefix}[id][]" value="${escapeAttribute(id)}" data-viswiz-node-id data-viswiz-auto-id="1" />
      <div class="viswiz-node-grid">
        <label>Title <input type="text" name="${namePrefix}[title][]" placeholder="Node title" class="regular-text" data-viswiz-node-title value="${escapeAttribute(defaults.title || '')}" /></label>
        <label>Short label <input type="text" name="${namePrefix}[label][]" placeholder="Optional short label" class="regular-text" value="${escapeAttribute(defaults.label || '')}" /></label>
        <label>Node type <select name="${namePrefix}[node_type][]" data-viswiz-node-type>${getNodeTypeOptionsHtml(defaults.node_type || '')}</select></label>
        <label>Node subtype <select name="${namePrefix}[node_subtype][]" data-viswiz-node-subtype><option value="">No subtype</option><option value="proposed">Other / proposed subtype</option></select></label>
        <label>Main image <span class="viswiz-media-field"><input type="hidden" name="${namePrefix}[main_image][]" value="" data-viswiz-media-value data-viswiz-main-image-value /><button type="button" class="button" data-viswiz-media-select="single">Select/upload</button><span data-viswiz-media-label>No image selected</span></span></label>
        <label>Other images <span class="viswiz-media-field"><input type="hidden" name="${namePrefix}[other_images][]" value="" data-viswiz-media-value data-viswiz-other-images-value /><button type="button" class="button" data-viswiz-media-select="multiple">Select/upload</button><span data-viswiz-media-label>No images selected</span></span></label>
      </div>
      <div class="viswiz-node-image-gallery" data-viswiz-node-image-gallery aria-live="polite"><strong>Node images</strong><p class="description" data-viswiz-node-image-empty>No images attached to this node.</p></div>
      <div class="viswiz-proposed-subtype" data-viswiz-proposed-subtype hidden>
        <strong>Proposed subtype workflow</strong>
        <p class="description">Editors should propose new subtypes instead of adding top-level node types for routine work.</p>
        <label>Proposed label <input type="text" name="${namePrefix}[proposed_subtype_label][]" class="regular-text" /></label>
        <label>Reason <textarea name="${namePrefix}[proposed_subtype_reason][]" rows="2"></textarea></label>
        <label>Example entity <input type="text" name="${namePrefix}[proposed_subtype_example][]" class="regular-text" /></label>
        <label>Why existing types do not fit <textarea name="${namePrefix}[proposed_subtype_gap][]" rows="2"></textarea></label>
        <label>Review status <select name="${namePrefix}[proposed_subtype_status][]"><option value="proposed">Proposed</option><option value="approved">Approved</option><option value="merged">Merged</option><option value="renamed">Renamed</option><option value="rejected">Rejected</option></select></label>
      </div>
      <div class="viswiz-full-field viswiz-rich-description-field" data-viswiz-rich-description-editor>
        <label>Formatted description</label>
        <div class="viswiz-rich-description-toolbar" aria-label="Description formatting tools">
          <button type="button" class="button button-small" data-viswiz-description-format="strong">Bold</button>
          <button type="button" class="button button-small" data-viswiz-description-format="em">Italic</button>
          <button type="button" class="button button-small" data-viswiz-description-format="link">Link</button>
          <button type="button" class="button button-small" data-viswiz-description-format="ul">Bullets</button>
          <button type="button" class="button button-small" data-viswiz-description-format="blockquote">Quote</button>
          <button type="button" class="button button-small" data-viswiz-description-format="p">Paragraph</button>
        </div>
        <textarea id="${editorId}" name="${namePrefix}[description][]" rows="8" class="large-text viswiz-rich-description-textarea" data-viswiz-node-description>${escapeAttribute(defaults.description || '')}</textarea>
        <p class="description">Uses a modal-safe WYSIWYG editor when available. Formatting is preserved in the public node detail modal.</p>
      </div>
      <div class="viswiz-custom-labels"><strong>Custom labels</strong><button type="button" class="button viswiz-add-custom-label">Add custom label</button></div>
      <div class="viswiz-node-relation-tools" data-viswiz-node-relation-tools>
        <strong>Relations for this node</strong>
        <div class="viswiz-node-relation-list" data-viswiz-node-relation-list></div>
        <div class="viswiz-node-relation-editor" data-viswiz-node-relation-editor hidden></div>
      </div>
      <p class="viswiz-node-actions"><button type="button" class="button button-primary" data-viswiz-save-node>Save node</button> <button type="button" class="button" data-viswiz-close-node>Save & close</button> <button type="button" class="button" data-viswiz-save-node-add-relation>Add relation</button> <button type="button" class="button" data-viswiz-save-node-add-connected>Create connected node</button> <span class="description" data-viswiz-node-autosave-status></span> <button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button viswiz-remove-row">Remove node</button></p>`;
    container.appendChild(row);
    enhanceNodeCard(row);
    updateNodeSubtypeOptions(row);
    if (defaults.node_subtype) {
      const subtypeSelect = row.querySelector('[data-viswiz-node-subtype]');
      if (subtypeSelect && Array.from(subtypeSelect.options).some((option) => option.value === defaults.node_subtype)) {
        subtypeSelect.value = defaults.node_subtype;
      }
    }
    refreshNodeDatalist();
    initializeRelationNodeAutocomplete();
    refreshSmartSelects(document);
    refreshNodeRelationTools();
    buildNodeTypeFilterOptions();
    filterNodeList();
    validateGraph();
    if (defaults.__open !== false) openNodeModal(row);
    return row;
  }

  function addGraphLink(containerId, namePrefix, defaults = {}) {
    const container = document.getElementById(containerId);
    if (!container) return null;
    const meta = getRelationTypeMeta(defaults.relation_type || '');
    const direction = defaults.direction || meta?.direction || 'directed';
    const intensity = defaults.intensity != null ? defaults.intensity : (meta?.default_intensity != null ? meta.default_intensity : 1);
    const label = defaults.label || meta?.label || '';
    const row = document.createElement('details');
    row.className = 'viswiz-relation-card viswiz-sortable-card';
    row.dataset.viswizRelationCard = '1';
    row.dataset.relationIndex = container.querySelectorAll('[data-viswiz-relation-card]').length;
    row.innerHTML = `<summary><span class="viswiz-drag-handle" aria-hidden="true">↕</span><strong>${escapeAttribute(label || 'Relation')}</strong><span class="viswiz-relation-card-summary-meta">No endpoints</span></summary>
      <div class="viswiz-relation-grid">
        <label>From ${buildNamedRelationNodeSelectMarkup(`${namePrefix}[from][]`, 'from')}</label>
        <label>To ${buildNamedRelationNodeSelectMarkup(`${namePrefix}[to][]`, 'to')}</label>
        <label>Label <input type="text" name="${namePrefix}[label][]" placeholder="Relation label" value="${escapeAttribute(label)}" class="regular-text" /></label>
        <label>Direction <select name="${namePrefix}[direction][]"><option value="directed"${direction === 'directed' ? ' selected' : ''}>Directed</option><option value="undirected"${direction === 'undirected' ? ' selected' : ''}>Undirected</option><option value="bidirectional"${direction === 'bidirectional' ? ' selected' : ''}>Bidirectional</option></select></label>
        <label>Intensity <input type="number" name="${namePrefix}[intensity][]" placeholder="Intensity" value="${escapeAttribute(intensity)}" min="0" step="0.01" /></label>
        <label>Relation type <select name="${namePrefix}[relation_type][]" class="regular-text" data-viswiz-relation-type-select data-viswiz-smart-search="relation-type" data-viswiz-smart-placeholder="Search relation type…" data-viswiz-smart-empty="No matching relation types.">${getRelationTypeOptionsHtml(defaults.relation_type || '')}</select></label>
      </div>
      <div class="viswiz-relation-warning" data-viswiz-relation-warning></div>
      <p><button type="button" class="button" data-viswiz-reverse-relation>Reverse relation</button> <button type="button" class="button viswiz-move-up">Move up</button> <button type="button" class="button viswiz-move-down">Move down</button> <button type="button" class="button viswiz-remove-row">Remove relation</button> <button type="button" class="button" data-viswiz-close-relation>Save & close</button></p>`;
    container.appendChild(row);
    const from = row.querySelector('[data-viswiz-relation-from]');
    const to = row.querySelector('[data-viswiz-relation-to]');
    if (from) from.value = defaults.from || '';
    if (to) to.value = defaults.to || '';
    initializeRelationNodeAutocomplete(row);
    refreshSmartSelects(row);
    updateRelationCardDataset(row);
    refreshNodeRelationTools();
    validateGraph();
    return row;
  }

  function updateNodeSummary(card) {
    if (!card) return;
    maybeAutoAssignNodeId(card);
    const title = card.querySelector('[data-viswiz-node-title]')?.value || 'New node';
    const titleEl = card.querySelector('summary strong');
    if (titleEl) titleEl.textContent = title;
    const meta = card.querySelector('.viswiz-node-card-summary-meta');
    if (meta) meta.textContent = getNodeTypeLabel(card);
    const id = card.querySelector('[data-viswiz-node-id]')?.value || '';
    const idDisplay = card.querySelector('[data-viswiz-node-id-display]');
    if (idDisplay) idDisplay.textContent = id;
    renderNodeDuplicateHints(card);
    renderNodeValidation(card);
  }

  function openQuickRelationCreator(nodeCard, mode = 'outgoing') {
    const editor = nodeCard?.querySelector('[data-viswiz-node-relation-editor]');
    if (!editor) return;
    const nodeId = nodeCard.querySelector('[data-viswiz-node-id]')?.value || '';
    const connected = mode === 'connected';
    const incoming = mode === 'incoming';
    editor.hidden = false;
    editor.dataset.viswizQuickRelationMode = mode;
    editor.innerHTML = `
      <strong>${connected ? 'Create connected node' : (incoming ? 'Add incoming relation' : 'Add outgoing relation')}</strong>
      ${connected ? `
        <label>New node title <input type="text" class="regular-text" data-viswiz-connected-node-title placeholder="New node title" /></label>
        <label>New node type <select data-viswiz-connected-node-type>${getNodeTypeOptionsHtml()}</select></label>
        <label>New node subtype <select data-viswiz-connected-node-subtype><option value="">No subtype</option><option value="proposed">Other / proposed subtype</option></select></label>
      ` : `<label>${incoming ? 'From existing node' : 'To existing node'} ${buildRelationNodeSelectMarkup('', incoming ? 'from' : 'to')}</label>`}
      <label>Relation type <select data-viswiz-quick-new-relation-type data-viswiz-smart-search="relation-type" data-viswiz-smart-placeholder="Search relation type…" data-viswiz-smart-empty="No matching relation types.">${getRelationTypeOptionsHtml('')}</select></label>
      <label>Label <input type="text" class="regular-text" data-viswiz-quick-new-relation-label placeholder="Uses relation type label by default" /></label>
      <label>Direction <select data-viswiz-quick-new-relation-direction><option value="directed">Directed</option><option value="undirected">Undirected</option><option value="bidirectional">Bidirectional</option></select></label>
      <label>Intensity <input type="number" min="0" step="0.01" value="1" data-viswiz-quick-new-relation-intensity /></label>
      <p class="viswiz-node-relation-editor-actions">
        <button type="button" class="button button-primary" data-viswiz-create-node-relation>${connected ? 'Create node & relation' : 'Create relation'}</button>
        <button type="button" class="button" data-viswiz-close-node-relation-editor>Cancel</button>
      </p>
      <p class="description" data-viswiz-relation-template-help>Selecting a relation type will apply its default direction and intensity.</p>
    `;
    const typeSelect = editor.querySelector('[data-viswiz-connected-node-type]');
    const subtypeSelect = editor.querySelector('[data-viswiz-connected-node-subtype]');
    if (typeSelect && subtypeSelect) {
      typeSelect.addEventListener('change', () => {
        const options = getSubtypeEntries(typeSelect.value);
        subtypeSelect.innerHTML = '<option value="">No subtype</option>' + options.map((item) => `<option value="${escapeAttribute(item.value)}">${escapeAttribute(item.label)}</option>`).join('') + '<option value="proposed">Other / proposed subtype</option>';
      });
    }
    const relType = editor.querySelector('[data-viswiz-quick-new-relation-type]');
    relType?.addEventListener('change', () => {
      const meta = getRelationTypeMeta(relType.value);
      if (!meta) return;
      const label = editor.querySelector('[data-viswiz-quick-new-relation-label]');
      const direction = editor.querySelector('[data-viswiz-quick-new-relation-direction]');
      const intensity = editor.querySelector('[data-viswiz-quick-new-relation-intensity]');
      if (label && !label.value) label.value = meta.label || '';
      if (direction) direction.value = meta.direction || 'directed';
      if (intensity) intensity.value = meta.default_intensity != null ? meta.default_intensity : 1;
    });
    initializeRelationNodeAutocomplete(editor);
    refreshSmartSelects(editor);
  }

  function createNodeRelationFromQuickEditor(editor) {
    const nodeCard = editor.closest('[data-viswiz-node-card]');
    if (!nodeCard) return;
    const mode = editor.dataset.viswizQuickRelationMode || 'outgoing';
    const nodeId = nodeCard.querySelector('[data-viswiz-node-id]')?.value || '';
    let otherId = '';
    if (mode === 'connected') {
      const nodesContext = getGraphContext('nodes');
      const newTitle = editor.querySelector('[data-viswiz-connected-node-title]')?.value || '';
      if (!newTitle.trim()) { window.alert('Add a title for the connected node.'); return; }
      const newNode = addGraphNode(nodesContext.id, nodesContext.prefix, {
        title: newTitle,
        node_type: editor.querySelector('[data-viswiz-connected-node-type]')?.value || '',
        node_subtype: editor.querySelector('[data-viswiz-connected-node-subtype]')?.value || '',
        __open: false,
      });
      otherId = newNode?.querySelector('[data-viswiz-node-id]')?.value || '';
    } else {
      otherId = editor.querySelector('[data-viswiz-node-relation-quick="from"], [data-viswiz-node-relation-quick="to"]')?.value || '';
    }
    otherId = getNodeIdForDisplay(otherId);
    if (!otherId) { window.alert('Select the other node.'); return; }
    const relType = editor.querySelector('[data-viswiz-quick-new-relation-type]')?.value || '';
    const linksContext = getGraphContext('links');
    const relation = addGraphLink(linksContext.id, linksContext.prefix, {
      from: mode === 'incoming' ? otherId : nodeId,
      to: mode === 'incoming' ? nodeId : otherId,
      relation_type: relType,
      label: editor.querySelector('[data-viswiz-quick-new-relation-label]')?.value || '',
      direction: editor.querySelector('[data-viswiz-quick-new-relation-direction]')?.value || '',
      intensity: editor.querySelector('[data-viswiz-quick-new-relation-intensity]')?.value || 1,
    });
    if (relation) setRelationTypeDefaults(relation, false);
    closeNodeRelationEditor(editor);
    refreshNodeRelationTools();
    validateGraph();
  }

  function openNodeRelationEditor(nodeCard, relationCard) {
    const editor = nodeCard?.querySelector('[data-viswiz-node-relation-editor]');
    if (!editor || !relationCard) return;
    const index = Array.from(getAllRelationCards()).indexOf(relationCard);
    const nodeId = nodeCard?.querySelector('[data-viswiz-node-id]')?.value || '';
    const ids = getRelationNodeIds(relationCard);
    const label = relationCard.querySelector('input[name$="[label][]"]')?.value || '';
    const relationType = relationCard.querySelector('[name$="[relation_type][]"]')?.value || '';
    const direction = relationCard.querySelector('select[name$="[direction][]"]')?.value || 'directed';
    const intensity = relationCard.querySelector('input[name$="[intensity][]"]')?.value || '1';
    editor.hidden = false;
    editor.dataset.relationIndex = index;
    delete editor.dataset.viswizQuickRelationMode;
    editor.innerHTML = `
      <strong>Edit relation</strong>
      <label>From ${buildRelationNodeSelectMarkup(ids.from, 'from')}</label>
      <label>To ${buildRelationNodeSelectMarkup(ids.to, 'to')}</label>
      <label>Label <input type="text" value="${escapeAttribute(label)}" data-viswiz-node-relation-quick="label" /></label>
      <label>Relation type <select data-viswiz-node-relation-quick="relation_type" data-viswiz-smart-search="relation-type" data-viswiz-smart-placeholder="Search relation type…" data-viswiz-smart-empty="No matching relation types.">${getRelationTypeOptionsHtml(relationType)}</select></label>
      <label>Direction <select data-viswiz-node-relation-quick="direction"><option value="directed"${direction === 'directed' ? ' selected' : ''}>Directed</option><option value="undirected"${direction === 'undirected' ? ' selected' : ''}>Undirected</option><option value="bidirectional"${direction === 'bidirectional' ? ' selected' : ''}>Bidirectional</option></select></label>
      <label>Intensity <input type="number" min="0" step="0.01" value="${escapeAttribute(intensity)}" data-viswiz-node-relation-quick="intensity" /></label>
      <p class="viswiz-node-relation-editor-actions">
        <button type="button" class="button" data-viswiz-reverse-node-relation>Reverse</button>
        <button type="button" class="button" data-viswiz-close-node-relation-editor>Done editing relation</button>
        <button type="button" class="button" data-viswiz-remove-relation-from-node="${escapeAttribute(nodeId)}">Remove from this node</button>
        <button type="button" class="button button-link-delete" data-viswiz-delete-node-relation>Delete relation from dataset</button>
      </p>
    `;
    initializeRelationNodeAutocomplete(editor);
    refreshSmartSelects(editor);
  }

  function syncQuickRelationEditor(input) {
    const editor = input.closest('[data-viswiz-node-relation-editor]');
    const relationIndex = parseInt(editor?.dataset.relationIndex, 10);
    const relation = getAllRelationCards()[relationIndex];
    if (!relation) return;
    const selectors = {
      from: '[data-viswiz-relation-from]',
      to: '[data-viswiz-relation-to]',
      label: 'input[name$="[label][]"]',
      relation_type: '[name$="[relation_type][]"]',
      direction: 'select[name$="[direction][]"]',
      intensity: 'input[name$="[intensity][]"]',
    };
    const target = relation.querySelector(selectors[input.dataset.viswizNodeRelationQuick]);
    if (target) {
      target.value = input.value;
      if (input.dataset.viswizNodeRelationQuick === 'relation_type') setRelationTypeDefaults(relation, false);
      updateRelationCardDataset(relation);
      refreshNodeRelationTools();
      validateGraph();
    }
  }

  function refreshNodeRelationTools() {
    refreshRelationNodeSelectors();
    getAllRelationCards().forEach(updateRelationCardDataset);
    getAllNodeCards().forEach((nodeCard) => {
      const nodeId = nodeCard.querySelector('[data-viswiz-node-id]')?.value || '';
      const list = nodeCard.querySelector('[data-viswiz-node-relation-list]');
      if (!list) return;
      list.innerHTML = '';
      const groups = { outgoing: [], incoming: [] };
      getAllRelationCards().forEach((relationCard, index) => {
        const ids = getRelationNodeIds(relationCard);
        if (ids.from === nodeId) groups.outgoing.push([relationCard, index]);
        if (ids.to === nodeId) groups.incoming.push([relationCard, index]);
      });
      ['outgoing', 'incoming'].forEach((group) => {
        if (!groups[group].length) return;
        const section = document.createElement('div');
        section.className = 'viswiz-node-relation-group';
        section.innerHTML = `<h5>${group === 'outgoing' ? 'Outgoing' : 'Incoming'}</h5>`;
        groups[group].forEach(([relationCard, index]) => {
          const ids = getRelationNodeIds(relationCard);
          const label = relationCard.querySelector('input[name$="[label][]"]')?.value || getRelationTypeLabel(relationCard.querySelector('[data-viswiz-relation-type-select]')?.value || '') || 'Untitled relation';
          const direction = getRelationDirection(relationCard);
          const item = document.createElement('div');
          item.className = 'viswiz-node-relation-card';
          item.innerHTML = `<strong>${escapeAttribute(getNodeDisplayForId(ids.from) || '…')} ${escapeAttribute(getRelationDirectionGlyph(direction))} ${escapeAttribute(getNodeDisplayForId(ids.to) || '…')}</strong><span>${escapeAttribute(label)}</span>`;
          const actions = document.createElement('div');
          actions.className = 'viswiz-node-relation-card-actions';
          const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'button button-small'; edit.dataset.viswizEditRelation = index; edit.textContent = 'Edit';
          const reverse = document.createElement('button'); reverse.type = 'button'; reverse.className = 'button button-small'; reverse.dataset.viswizReverseRelationIndex = index; reverse.textContent = 'Reverse';
          const del = document.createElement('button'); del.type = 'button'; del.className = 'button button-small button-link-delete'; del.dataset.viswizDeleteRelationIndex = index; del.textContent = 'Delete';
          actions.append(edit, reverse, del);
          item.appendChild(actions);
          section.appendChild(item);
        });
        list.appendChild(section);
      });
      if (!list.children.length) {
        const empty = document.createElement('div');
        empty.className = 'viswiz-node-relation-empty';
        empty.innerHTML = '<span>This node has no relations yet.</span>';
        list.appendChild(empty);
      }
    });
    validateGraph();
  }

  function normalizeLooseKey(value) {
    return greeklish(String(value || '')).toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  }

  function getNodeTypeLabelBySlug(slug) {
    const item = graphNodeTypes.find((entry) => (entry.slug || entry.value || '') === slug);
    return item ? (item.label || slug) : slug;
  }

  function resolveNodeTypeSlug(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const rawKey = normalizeLooseKey(raw);
    const hit = graphNodeTypes.find((entry) => {
      const slug = entry.slug || entry.value || '';
      return slug === raw || normalizeLooseKey(slug) === rawKey || normalizeLooseKey(entry.label || '') === rawKey;
    });
    return hit ? (hit.slug || hit.value || '') : rawKey;
  }

  function resolveSubtypeSlug(nodeType, value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const rawKey = normalizeLooseKey(raw);
    const entries = getSubtypeEntries(nodeType);
    const hit = entries.find((entry) => entry.value === raw || normalizeLooseKey(entry.value) === rawKey || normalizeLooseKey(entry.label || '') === rawKey);
    return hit ? hit.value : rawKey;
  }

  function resolveRelationTypeSlug(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const rawKey = normalizeLooseKey(raw);
    const hit = relationTypes.find((entry) => {
      const slug = entry.slug || entry.value || '';
      return slug === raw || normalizeLooseKey(slug) === rawKey || normalizeLooseKey(entry.label || '') === rawKey;
    });
    return hit ? (hit.slug || hit.value || '') : rawKey;
  }

  function getNodeSubtypeForId(id) {
    const card = getAllNodeCards().find((nodeCard) => (nodeCard.querySelector('[data-viswiz-node-id]')?.value || '') === id);
    return card?.querySelector('[data-viswiz-node-subtype]')?.value || '';
  }

  function getNodeTitleForId(id) {
    const card = getAllNodeCards().find((nodeCard) => (nodeCard.querySelector('[data-viswiz-node-id]')?.value || '') === id);
    return card?.querySelector('[data-viswiz-node-title]')?.value || id;
  }

  function getSubtypeLabel(nodeType, subtype) {
    const hit = getSubtypeEntries(nodeType).find((entry) => entry.value === subtype);
    return hit ? (hit.label || subtype) : subtype;
  }

  function updateSubtypeRegistry(nodeType, subtype, label) {
    if (!nodeType || !subtype || !label) return;
    if (!graphNodeSubtypes[nodeType]) graphNodeSubtypes[nodeType] = [];
    const entries = getSubtypeEntries(nodeType);
    const existing = entries.find((entry) => entry.value === subtype);
    if (existing) existing.label = label;
    else entries.push({ value: subtype, label });
    graphNodeSubtypes[nodeType] = entries;
    const typeMeta = graphNodeTypes.find((entry) => (entry.slug || entry.value || '') === nodeType);
    if (typeMeta) {
      typeMeta.subtypes = Object.fromEntries(entries.map((entry) => [entry.value, entry.label]));
    }
    document.querySelectorAll('[data-viswiz-node-card]').forEach((card) => {
      const before = card.querySelector('[data-viswiz-node-subtype]')?.value || '';
      updateNodeSubtypeOptions(card);
      const select = card.querySelector('[data-viswiz-node-subtype]');
      if (select && before && Array.from(select.options).some((option) => option.value === before)) select.value = before;
    });
  }

  function registerApprovedSubtype(nodeType, label, callback) {
    const slug = slugifyNodeTypeLabel(label);
    updateSubtypeRegistry(nodeType, slug, label);
    if (!window.VisWizAdmin || !VisWizAdmin.ajaxUrl || !VisWizAdmin.nonce || !window.fetch) {
      callback(slug, label);
      return;
    }
    const data = new FormData();
    data.append('action', 'viswiz_register_node_subtype');
    data.append('nonce', VisWizAdmin.nonce || '');
    data.append('node_type', nodeType);
    data.append('label', label);
    data.append('slug', slug);
    window.fetch(VisWizAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data })
      .then((response) => response.json())
      .then((response) => {
        if (response && response.success && response.data) {
          if (response.data.all_subtypes) {
            Object.keys(response.data.all_subtypes).forEach((type) => { graphNodeSubtypes[type] = response.data.all_subtypes[type]; });
          } else {
            updateSubtypeRegistry(response.data.node_type || nodeType, response.data.subtype || slug, response.data.label || label);
          }
          callback(response.data.subtype || slug, response.data.label || label);
        } else {
          callback(slug, label);
        }
      })
      .catch(() => callback(slug, label));
  }

  function reviewProposedSubtype(button, status) {
    const card = getAllNodeCards().find((nodeCard) => nodeCard.dataset.nodeIndex === button.dataset.nodeIndex);
    if (!card) return;
    const type = card.querySelector('[data-viswiz-node-type]')?.value || '';
    const labelInput = card.querySelector('[name$="[proposed_subtype_label][]"]');
    const statusSelect = card.querySelector('[name$="[proposed_subtype_status][]"]');
    const subtypeSelect = card.querySelector('[data-viswiz-node-subtype]');
    const label = (labelInput?.value || '').trim() || 'Approved subtype';
    const apply = (slug) => {
      if (statusSelect) statusSelect.value = status;
      if ((status === 'approved' || status === 'renamed') && subtypeSelect) {
        updateSubtypeRegistry(type, slug, label);
        updateNodeSubtypeOptions(card);
        subtypeSelect.value = slug;
      } else if (subtypeSelect && subtypeSelect.value === 'proposed') {
        subtypeSelect.value = '';
      }
      updateProposedSubtype(card);
      updateNodeSummary(card);
      refreshNodeTypeManager();
      buildNodeTypeFilterOptions();
      filterNodeList();
      validateGraph();
      markVisWizDirty();
    };
    if ((status === 'approved' || status === 'renamed') && type) {
      registerApprovedSubtype(type, label, apply);
    } else {
      apply('');
    }
  }

  function getRelationWarning(relationCard) {
    const type = relationCard.querySelector('[data-viswiz-relation-type-select]')?.value || '';
    const meta = getRelationTypeMeta(type);
    if (!meta) return '';
    const ids = getRelationNodeIds(relationCard);
    const fromType = getNodeTypeForId(ids.from);
    const toType = getNodeTypeForId(ids.to);
    const fromSubtype = getNodeSubtypeForId(ids.from);
    const toSubtype = getNodeSubtypeForId(ids.to);
    const warnings = [];
    if (meta.source_type && fromType && meta.source_type !== fromType) warnings.push(`Usually source is ${getNodeTypeLabelBySlug(meta.source_type)}.`);
    if (meta.target_type && toType && meta.target_type !== toType) warnings.push(`Usually target is ${getNodeTypeLabelBySlug(meta.target_type)}.`);
    if (meta.source_subtype && fromSubtype && meta.source_subtype !== fromSubtype) warnings.push(`Usually source subtype is ${getSubtypeLabel(meta.source_type || fromType, meta.source_subtype)}.`);
    if (meta.target_subtype && toSubtype && meta.target_subtype !== toSubtype) warnings.push(`Usually target subtype is ${getSubtypeLabel(meta.target_type || toType, meta.target_subtype)}.`);
    return warnings.join(' ');
  }

  function reverseRelation(relationCard) {
    if (!relationCard) return;
    const from = relationCard.querySelector('[data-viswiz-relation-from]');
    const to = relationCard.querySelector('[data-viswiz-relation-to]');
    if (!from || !to) return;
    const oldFrom = from.value;
    from.value = to.value;
    to.value = oldFrom;
    const type = relationCard.querySelector('[data-viswiz-relation-type-select]')?.value || '';
    const meta = getRelationTypeMeta(type);
    const label = relationCard.querySelector('input[name$="[label][]"]');
    if (label && meta?.inverse_label) label.value = meta.inverse_label;
    updateRelationCardDataset(relationCard);
    refreshNodeRelationTools();
    validateGraph();
    markVisWizDirty();
  }

  function buildNodeTypeFilterOptions() {
    const optionsWrap = document.querySelector('[data-viswiz-node-type-filter-options]');
    if (!optionsWrap) return;
    const selected = new Set(getSelectedNodeTypeFilters());
    const counts = new Map();
    getAllNodeCards().forEach((card) => {
      const type = card.querySelector('[data-viswiz-node-type]')?.value || '';
      const subtype = card.querySelector('[data-viswiz-node-subtype]')?.value || '';
      if (type) counts.set(type, (counts.get(type) || 0) + 1);
      if (type && subtype) counts.set(`${type}:${subtype}`, (counts.get(`${type}:${subtype}`) || 0) + 1);
    });
    optionsWrap.innerHTML = '';
    const source = graphNodeTypes.length ? graphNodeTypes : [];
    source.forEach((type) => {
      const typeSlug = type.slug || type.value || '';
      const typeLabel = type.label || typeSlug;
      const typeRow = document.createElement('label');
      typeRow.className = 'viswiz-node-type-filter-row viswiz-node-type-filter-parent';
      const typeInput = document.createElement('input');
      typeInput.type = 'checkbox';
      typeInput.value = typeSlug;
      typeInput.checked = selected.has(typeSlug);
      typeRow.appendChild(typeInput);
      const typeText = document.createElement('span');
      typeText.textContent = `${typeLabel} (${counts.get(typeSlug) || 0})`;
      typeRow.appendChild(typeText);
      optionsWrap.appendChild(typeRow);
      getSubtypeEntries(typeSlug).forEach((subtype) => {
        const value = `${typeSlug}:${subtype.value}`;
        const row = document.createElement('label');
        row.className = 'viswiz-node-type-filter-row viswiz-node-type-filter-child';
        if (!counts.get(value)) row.classList.add('is-empty');
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.value = value;
        input.checked = selected.has(value);
        row.appendChild(input);
        const text = document.createElement('span');
        text.textContent = `${subtype.label || subtype.value} (${counts.get(value) || 0})`;
        row.appendChild(text);
        optionsWrap.appendChild(row);
      });
    });
  }

  function refreshNodeTypeManager() {
    const wrap = document.querySelector('[data-viswiz-node-type-manager]');
    if (!wrap) return;
    const usage = new Map();
    const proposals = [];
    getAllNodeCards().forEach((card) => {
      const type = card.querySelector('[data-viswiz-node-type]')?.value || '';
      const subtype = card.querySelector('[data-viswiz-node-subtype]')?.value || '';
      if (type) usage.set(type, (usage.get(type) || 0) + 1);
      if (type && subtype) usage.set(`${type}:${subtype}`, (usage.get(`${type}:${subtype}`) || 0) + 1);
      if (subtype === 'proposed') {
        proposals.push({
          index: card.dataset.nodeIndex,
          type,
          title: card.querySelector('[data-viswiz-node-title]')?.value || 'Untitled node',
          label: card.querySelector('[name$="[proposed_subtype_label][]"]')?.value || 'Untitled proposal',
          status: card.querySelector('[name$="[proposed_subtype_status][]"]')?.value || 'proposed',
        });
      }
    });
    wrap.replaceChildren();
    const intro = document.createElement('p');
    intro.className = 'description';
    intro.textContent = 'This panel reviews usage in the current visualization. Edit the canonical schema in VisWiz → Node Types.';
    wrap.appendChild(intro);
    (graphNodeTypes.length ? graphNodeTypes : []).forEach((type) => {
      const typeSlug = type.slug || type.value || '';
      const section = document.createElement('details');
      section.open = (usage.get(typeSlug) || 0) > 0;
      section.className = 'viswiz-node-type-card';
      const summary = document.createElement('summary');
      summary.innerHTML = `<strong>${escapeAttribute(type.label || typeSlug)}</strong> <span class="description">${usage.get(typeSlug) || 0} linked node${(usage.get(typeSlug) || 0) === 1 ? '' : 's'}</span>`;
      const del = createTypeActionButton('Clear assignments', 'viswizDeleteNodeType', typeSlug);
      summary.appendChild(document.createTextNode(' '));
      summary.appendChild(del);
      section.appendChild(summary);
      const list = document.createElement('div');
      list.className = 'viswiz-node-type-subtype-list';
      getSubtypeEntries(typeSlug).forEach((subtype) => {
        const row = document.createElement('p');
        const count = usage.get(`${typeSlug}:${subtype.value}`) || 0;
        row.innerHTML = `<strong>${escapeAttribute(subtype.label || subtype.value)}</strong> <span class="description">${count} linked node${count === 1 ? '' : 's'}</span> `;
        row.appendChild(createTypeActionButton('Clear subtype assignments', 'viswizDeleteNodeSubtype', subtype.value, { nodeType: typeSlug }));
        list.appendChild(row);
      });
      const typeProposals = proposals.filter((proposal) => proposal.type === typeSlug);
      if (typeProposals.length) {
        const h = document.createElement('h5');
        h.textContent = 'Author-proposed subtypes';
        list.appendChild(h);
        typeProposals.forEach((proposal) => {
          const row = document.createElement('p');
          row.innerHTML = `<strong>${escapeAttribute(proposal.label)}</strong> <span class="description">${escapeAttribute(proposal.title)} · Status: ${escapeAttribute(proposal.status)}</span> `;
          row.appendChild(createTypeActionButton('Approve into schema', 'viswizReviewProposal', 'approved', { nodeIndex: proposal.index }));
          row.appendChild(document.createTextNode(' '));
          row.appendChild(createTypeActionButton('Reject', 'viswizReviewProposal', 'rejected', { nodeIndex: proposal.index }));
          list.appendChild(row);
        });
      }
      section.appendChild(list);
      wrap.appendChild(section);
    });
  }

  function splitQuickLine(line) {
    return line.split(/\t|,/).map((part) => part.trim());
  }

  function importQuickNodes() {
    const textarea = document.querySelector('[data-viswiz-quick-node-text]');
    const context = getGraphContext('nodes');
    if (!textarea || !textarea.value.trim()) return;
    const rows = textarea.value.split(/\n+/).map((line) => line.trim()).filter(Boolean).map((line, lineIndex) => {
      const parts = splitQuickLine(line);
      const nodeType = resolveNodeTypeSlug(parts[1] || '');
      const nodeSubtype = resolveSubtypeSlug(nodeType, parts[2] || '');
      return { lineIndex: lineIndex + 1, title: parts[0] || '', node_type: nodeType, node_subtype: nodeSubtype, rawType: parts[1] || '', rawSubtype: parts[2] || '' };
    });
    const warnings = [];
    rows.forEach((row) => {
      if (!row.title) warnings.push(`Line ${row.lineIndex}: missing title`);
      if (row.rawType && !graphNodeTypes.some((type) => (type.slug || type.value || '') === row.node_type)) warnings.push(`Line ${row.lineIndex}: unknown node type “${row.rawType}”`);
      if (row.rawSubtype && !getSubtypeEntries(row.node_type).some((sub) => sub.value === row.node_subtype)) warnings.push(`Line ${row.lineIndex}: unknown subtype “${row.rawSubtype}” for ${row.node_type || 'empty type'}`);
    });
    const message = `${rows.length} node${rows.length === 1 ? '' : 's'} ready.${warnings.length ? '\n\nWarnings:\n- ' + warnings.slice(0, 8).join('\n- ') : ''}\n\nImport valid rows?`;
    if (!window.confirm(message)) return;
    rows.filter((row) => row.title).forEach((row) => addGraphNode(context.id, context.prefix, { title: row.title, node_type: row.node_type, node_subtype: row.node_subtype, __open: false }));
    textarea.value = '';
    refreshNodeRelationTools();
    refreshNodeTypeManager();
    buildNodeTypeFilterOptions();
    validateGraph();
  }

  function importQuickRelations() {
    const textarea = document.querySelector('[data-viswiz-quick-relation-text]');
    const context = getGraphContext('links');
    if (!textarea || !textarea.value.trim()) return;
    const nodeIds = new Set(getAllNodeCards().map((card) => card.querySelector('[data-viswiz-node-id]')?.value || '').filter(Boolean));
    const rows = textarea.value.split(/\n+/).map((line) => line.trim()).filter(Boolean).map((line, lineIndex) => {
      const parts = splitQuickLine(line);
      const from = getNodeIdForDisplay(parts[0] || '');
      const type = resolveRelationTypeSlug(parts[1] || '');
      const to = getNodeIdForDisplay(parts[2] || '');
      const meta = getRelationTypeMeta(type);
      return { lineIndex: lineIndex + 1, from, relation_type: type, to, direction: parts[3] || meta?.direction || '', intensity: parts[4] || meta?.default_intensity || undefined, rawFrom: parts[0] || '', rawTo: parts[2] || '', rawType: parts[1] || '' };
    });
    const warnings = [];
    rows.forEach((row) => {
      if (!row.from || !nodeIds.has(row.from)) warnings.push(`Line ${row.lineIndex}: missing source node “${row.rawFrom || 'empty'}”`);
      if (!row.to || !nodeIds.has(row.to)) warnings.push(`Line ${row.lineIndex}: missing target node “${row.rawTo || 'empty'}”`);
      if (row.rawType && !getRelationTypeMeta(row.relation_type)) warnings.push(`Line ${row.lineIndex}: unknown relation type “${row.rawType}”`);
    });
    const message = `${rows.length} relation${rows.length === 1 ? '' : 's'} ready.${warnings.length ? '\n\nWarnings:\n- ' + warnings.slice(0, 8).join('\n- ') : ''}\n\nImport valid rows only?`;
    if (!window.confirm(message)) return;
    rows.filter((row) => row.from && row.to && nodeIds.has(row.from) && nodeIds.has(row.to)).forEach((row) => addGraphLink(context.id, context.prefix, row));
    textarea.value = '';
    refreshNodeRelationTools();
    validateGraph();
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


  $(document).on('click', '[data-viswiz-node-autocomplete-list] [data-node-title]', function () {
    const input = this.closest('label')?.querySelector('[data-viswiz-relation-from], [data-viswiz-relation-to], [data-viswiz-node-relation-quick="from"], [data-viswiz-node-relation-quick="to"]');
    if (!input) return;
    input.value = this.dataset.nodeTitle || '';
    input.dataset.viswizAutocompleteSelected = '2';
    removeRelationNodeAutocomplete(input);
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  });

  $(document).on('click', function (event) {
    if (event.target.closest('[data-viswiz-node-autocomplete-list]') || event.target.closest('[data-viswiz-relation-from], [data-viswiz-relation-to], [data-viswiz-node-relation-quick="from"], [data-viswiz-node-relation-quick="to"]')) return;
    document.querySelectorAll('[data-viswiz-node-autocomplete-list]').forEach((list) => list.remove());
  });

  $(document).on('click', '[data-viswiz-delete-node-type]', function (event) {
    event.preventDefault();
    deleteNodeTypeAssignment(this.dataset.viswizDeleteNodeType);
  });

  $(document).on('click', '[data-viswiz-delete-node-subtype]', function (event) {
    event.preventDefault();
    deleteNodeSubtypeAssignment(this.dataset.nodeType, this.dataset.viswizDeleteNodeSubtype);
  });

  $(document).on('click', '[data-viswiz-review-proposal]', function (event) {
    event.preventDefault();
    reviewProposedSubtype(this, this.dataset.viswizReviewProposal);
  });

  $(document).on('click', '[data-viswiz-copy-target]', function () {
    const target = document.getElementById(this.dataset.viswizCopyTarget || '');
    if (!target) return;
    target.focus();
    target.select();
    const button = this;
    const done = () => {
      const original = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(() => { button.textContent = original; }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(target.value).then(done).catch(() => {
        document.execCommand('copy');
        done();
      });
    } else {
      document.execCommand('copy');
      done();
    }
  });

  $(document).on('input change', '#viswiz_visualization_meta input, #viswiz_visualization_meta textarea, #viswiz_visualization_meta select', function () {
    markVisWizDirty();
    updateManualDataLabels();
  });

  window.addEventListener('beforeunload', function (event) {
    if (!viswizIsDirty) return;
    event.preventDefault();
    event.returnValue = '';
  });

  $(document).on('click', '[data-viswiz-add]', function () {
    const key = $(this).data('viswiz-add');
    if (addHandlers[key]) {
      addHandlers[key]();
    }
  });

  $(document).on('click', '.viswiz-remove-row', function (event) {
    const editingNodeCard = this.closest('[data-viswiz-node-card].is-editing');
    if (editingNodeCard && editingNodeCard.contains(this)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      deleteEditingNodeCard(editingNodeCard);
      return;
    }

    const editingRelationCard = this.closest('[data-viswiz-relation-card].is-editing');
    if (editingRelationCard && editingRelationCard.contains(this)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      deleteEditingRelationCard(editingRelationCard);
      return;
    }

    const container = $(this).closest('.viswiz-repeatable');
    const card = $(this).closest('.viswiz-sortable-card');
    if (card.length) {
      if (card.is('[data-viswiz-node-card]')) {
        const nodeId = card.find('[data-viswiz-node-id]').val() || '';
        const related = getAllRelationCards().filter((relationCard) => {
          const ids = getRelationNodeIds(relationCard);
          return getNodeIdForDisplay(ids.from) === nodeId || getNodeIdForDisplay(ids.to) === nodeId;
        });
        const label = card.find('[data-viswiz-node-title]').val() || nodeId || 'this node';
        const message = related.length
          ? `Delete “${label}” and its ${related.length} relation${related.length === 1 ? '' : 's'} from this dataset?`
          : `Delete “${label}” from this dataset?`;
        if (!window.confirm(message)) return;
        related.forEach((relationCard) => relationCard.remove());
      } else if (card.is('[data-viswiz-relation-card]') && !window.confirm('Delete this relation from the dataset?')) {
        return;
      }
      card.remove();
    } else {
      $(this).closest('.viswiz-row').remove();
    }
    if (container.length) {
      reindexProgressRows(container.get(0));
    }
    refreshNodeDatalist();
    initializeRelationNodeAutocomplete();
    refreshSmartSelects(document);
    refreshNodeRelationTools();
    buildNodeTypeFilterOptions();
    filterNodeList();
    refreshNodeTypeManager();
    validateGraph();
    markVisWizDirty();
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
    $('[data-viswiz-active-tab-input]').val(tab);
    $('.viswiz-tab-button').removeClass('is-active').attr({ 'aria-selected': 'false', tabindex: '-1' });
    $(this).addClass('is-active').attr({ 'aria-selected': 'true', tabindex: '0' });
    $('.viswiz-tab-panel').removeClass('is-active');
    $(`[data-viswiz-panel="${tab}"]`).addClass('is-active');
    updateBuilderSteps(tab);
    updateVisualizationFields();
    refreshNodeTypeManager();
  });


  $(document).on('submit', 'form', function () {
    document.querySelectorAll('[data-viswiz-node-card]').forEach(syncNodeDescriptionEditor);
    viswizIsDirty = false;
    const activeTab = $('.viswiz-tab-button.is-active').data('viswiz-tab');
    if (activeTab) $('[data-viswiz-active-tab-input]', this).val(activeTab);
    this.querySelectorAll('[data-viswiz-relation-from], [data-viswiz-relation-to]').forEach((input) => {
      input.value = getNodeIdForDisplay(input.value);
    });
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

  $(document).on('input', '[data-viswiz-node-title], [data-viswiz-node-id], [name$="[label][]"]', function () {
    const card = $(this).closest('[data-viswiz-node-card]');
    if (card.length) updateNodeSummary(card.get(0));
    refreshNodeDatalist();
    refreshRelationNodeSelectors();
    filterNodeList();
    refreshNodeTypeManager();
  });


  $(document).on('click', '[data-viswiz-description-format]', function (event) {
    event.preventDefault();
    const field = this.closest('[data-viswiz-rich-description-editor]');
    const textarea = field?.querySelector('textarea[data-viswiz-node-description], textarea.viswiz-rich-description-textarea');
    const activeEditor = getActiveTinyMceEditor(textarea);
    const format = this.dataset.viswizDescriptionFormat || 'p';
    if (activeEditor && !activeEditor.isHidden()) {
      if (format === 'strong') activeEditor.execCommand('Bold');
      else if (format === 'em') activeEditor.execCommand('Italic');
      else if (format === 'ul') activeEditor.execCommand('InsertUnorderedList');
      else if (format === 'blockquote') activeEditor.execCommand('mceBlockQuote');
      else if (format === 'link') activeEditor.execCommand('mceLink');
      else activeEditor.execCommand('FormatBlock', false, 'p');
      activeEditor.save();
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      activeEditor.focus();
      return;
    }
    applyDescriptionFormat(textarea, format);
  });

  $(document).on('click', '[data-viswiz-modal-dismiss]', function (event) {
    event.preventDefault();
    const modal = this.closest('[data-viswiz-node-editor-modal], [data-viswiz-relation-editor-modal]');
    const nodeCard = modal?.querySelector('[data-viswiz-node-card].is-editing');
    const relationCard = modal?.querySelector('[data-viswiz-relation-card].is-editing');
    if (nodeCard) closeNodeModal(nodeCard);
    else if (relationCard) closeRelationModal(relationCard);
  });

  $(document).on('keydown', function (event) {
    if (event.key !== 'Escape') return;
    const relationCard = document.querySelector('[data-viswiz-relation-card].is-editing');
    const nodeCard = document.querySelector('[data-viswiz-node-card].is-editing');
    if (!relationCard && !nodeCard) return;
    event.preventDefault();
    event.stopPropagation();
    if (relationCard) closeRelationModal(relationCard);
    else closeNodeModal(nodeCard);
  });

  document.addEventListener('click', function (event) {
    const card = event.target.closest && event.target.closest('[data-viswiz-relation-card]');
    if (!card || card.classList.contains('is-editing')) return;
    if (event.target.closest('button, a, input, select, textarea, label')) return;
    event.preventDefault();
    event.stopPropagation();
    openRelationModal(card);
  }, true);

  $(document).on('toggle', '[data-viswiz-relation-card]', function () {
    if (this.open && !this.classList.contains('is-editing')) {
      openRelationModal(this);
    } else if (!this.open) {
      closeRelationModal(this);
    }
  });

  $(document).on('click', '[data-viswiz-close-relation]', function () {
    const card = this.closest('[data-viswiz-relation-card]');
    if (card) {
      autosaveRelationAndClose(card);
    }
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


  $(document).on('click', '[data-viswiz-save-node], [data-viswiz-close-node]', function (event) {
    event.preventDefault();
    event.stopImmediatePropagation();
    const card = this.closest('[data-viswiz-node-card]');
    if (card) {
      autosaveNodeAndClose(card);
    }
  });

  $(document).on('click', '[data-viswiz-node-add-relation]', function (event) {
    event.preventDefault();
    event.stopImmediatePropagation();
    const card = this.closest('[data-viswiz-node-card]');
    openQuickRelationCreator(card, this.dataset.viswizNodeAddRelation || this.dataset.relationMode || 'outgoing');
  });

  $(document).on('click', '[data-viswiz-node-add-connected], [data-viswiz-create-connected-node], [data-viswiz-save-node-add-connected]', function (event) {
    event.preventDefault();
    event.stopImmediatePropagation();
    const card = this.closest('[data-viswiz-node-card]');
    openQuickRelationCreator(card, 'connected');
  });

  $(document).on('click', '[data-viswiz-save-node-add-relation]', function (event) {
    event.preventDefault();
    event.stopImmediatePropagation();
    const card = this.closest('[data-viswiz-node-card]');
    openQuickRelationCreator(card, 'outgoing');
  });

  $(document).on('click', '[data-viswiz-create-node-relation]', function (event) {
    event.preventDefault();
    createNodeRelationFromQuickEditor(this.closest('[data-viswiz-node-relation-editor]'));
  });

  $(document).on('click', '[data-viswiz-edit-relation]', function () {
    const index = parseInt(this.dataset.viswizEditRelation, 10);
    const relation = getAllRelationCards()[index];
    if (!relation) return;
    openNodeRelationEditor(this.closest('[data-viswiz-node-card]'), relation);
  });

  $(document).on('change blur', '[data-viswiz-node-relation-quick]', function () {
    if (this.matches('input')) {
      updateRelationNodeAutocomplete(this);
      autocompleteRelationNodeInput(this);
    }
    syncQuickRelationEditor(this);
  });

  $(document).on('input', 'input[data-viswiz-node-relation-quick]', function () {
    updateRelationNodeAutocomplete(this);
    syncQuickRelationEditor(this);
  });

  $(document).on('click', '[data-viswiz-remove-relation-from-node]', function () {
    removeRelationFromNode(this.closest('[data-viswiz-node-relation-editor]'), this.dataset.viswizRemoveRelationFromNode || '');
  });

  $(document).on('click', '[data-viswiz-delete-node-relation]', function () {
    deleteNodeRelation(this.closest('[data-viswiz-node-relation-editor]'));
  });

  $(document).on('click', '[data-viswiz-close-node-relation-editor]', function () {
    closeNodeRelationEditor(this.closest('[data-viswiz-node-relation-editor]'));
  });

  $(document).on('click', '[data-viswiz-reverse-node-relation]', function () {
    const editor = this.closest('[data-viswiz-node-relation-editor]');
    const relationIndex = parseInt(editor?.dataset.relationIndex, 10);
    const relation = getAllRelationCards()[relationIndex];
    reverseRelation(relation);
    if (relation) openNodeRelationEditor(editor.closest('[data-viswiz-node-card]'), relation);
  });

  $(document).on('click', '[data-viswiz-reverse-relation]', function () {
    reverseRelation(this.closest('[data-viswiz-relation-card]'));
  });

  $(document).on('click', '[data-viswiz-reverse-relation-index]', function () {
    const relation = getAllRelationCards()[parseInt(this.dataset.viswizReverseRelationIndex, 10)];
    reverseRelation(relation);
  });

  $(document).on('click', '[data-viswiz-delete-relation-index]', function () {
    const relation = getAllRelationCards()[parseInt(this.dataset.viswizDeleteRelationIndex, 10)];
    if (relation && window.confirm('Delete this relation from the dataset?')) {
      relation.remove();
      refreshNodeRelationTools();
    }
  });

  $(document).on('change', '[data-viswiz-relation-type-select]', function () {
    setRelationTypeDefaults(this.closest('[data-viswiz-relation-card]'), false);
  });

  $(document).on('change', 'select[name$="[direction][]"], input[name$="[intensity][]"]', function () {
    this.dataset.viswizTouched = '1';
    const relation = this.closest('[data-viswiz-relation-card]');
    if (relation) updateRelationCardDataset(relation);
    validateGraph();
    refreshMiniGraphPreview();
    updateSchemaSubtypeSelects();
  });

  $(document).on('click', '[data-viswiz-edit-node-id]', function (event) {
    event.preventDefault();
    event.stopPropagation();
    const card = this.closest('[data-viswiz-node-card]');
    const current = card?.querySelector('[data-viswiz-node-id]')?.value || '';
    const next = window.prompt('Edit node ID. Relations pointing to this node will be updated.', current);
    if (next !== null && next.trim()) renameNodeId(card, next);
  });

  $(document).on('click', '[data-viswiz-use-existing-node]', function (event) {
    event.preventDefault();
    const existing = this.dataset.viswizUseExistingNode;
    const card = this.closest('[data-viswiz-node-card]');
    if (!card || !existing) return;
    const oldId = card.querySelector('[data-viswiz-node-id]')?.value || '';
    document.querySelectorAll('[data-viswiz-relation-from], [data-viswiz-relation-to]').forEach((field) => {
      if (field.value === oldId) field.value = existing;
    });
    card.remove();
    refreshNodeDatalist();
    refreshRelationNodeSelectors();
    refreshNodeRelationTools();
  });

  $(document).on('click', '[data-viswiz-toggle-quick-nodes]', function () {
    const panel = document.querySelector('[data-viswiz-quick-nodes]');
    if (panel) panel.hidden = !panel.hidden;
  });

  $(document).on('click', '[data-viswiz-toggle-quick-relations]', function () {
    const panel = document.querySelector('[data-viswiz-quick-relations]');
    if (panel) panel.hidden = !panel.hidden;
  });

  $(document).on('click', '[data-viswiz-import-quick-nodes]', function () { importQuickNodes(); });
  $(document).on('click', '[data-viswiz-import-quick-relations]', function () { importQuickRelations(); });

  $(document).on('change blur', '[data-viswiz-relation-from], [data-viswiz-relation-to]', function () {
    if (this.matches('input')) {
      updateRelationNodeAutocomplete(this);
      autocompleteRelationNodeInput(this);
    }
    const relation = this.closest('[data-viswiz-relation-card]');
    if (relation) updateRelationCardDataset(relation);
    refreshNodeRelationTools();
  });

  $(document).on('input change', '[data-viswiz-relation-from], [data-viswiz-relation-to], .viswiz-relation-card input[name$="[label][]"]', function () {
    if (this.matches('input')) updateRelationNodeAutocomplete(this);
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
    document.querySelectorAll('[data-viswiz-node-card]').forEach(syncNodeDescriptionEditor);
    viswizIsDirty = false;
    const activeTab = $('.viswiz-tab-button.is-active').data('viswiz-tab');
    if (activeTab) $('[data-viswiz-active-tab-input]', this).val(activeTab);
    reindexGraphCustomLabels();
  });


  $(document).on('click', '[data-viswiz-node-image-remove]', function () {
    const card = this.closest('[data-viswiz-node-card]');
    if (card) removeNodeImage(card, this.dataset.viswizNodeImageRemove);
  });

  $(document).on('click', '[data-viswiz-node-image-replace]', function () {
    const card = this.closest('[data-viswiz-node-card]');
    if (card) replaceNodeImage(card, this.dataset.viswizNodeImageReplace);
  });

  $(document).on('click', '[data-viswiz-node-image-edit]', function () {
    editNodeImage(this.dataset.viswizNodeImageEdit);
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
      button.siblings('[data-viswiz-media-label]').text(ids ? (multiple ? ids : '#' + ids) : (multiple ? 'No images selected' : 'No image selected'));
      const card = button.closest('[data-viswiz-node-card]')[0];
      if (card) {
        const { mainId, otherIds } = getNodeImageIds(card);
        setNodeImageIds(card, mainId, otherIds, selection);
      }
    });
    frame.open();
  });


  function updateSchemaSubtypeSelects(scope = document) {
    scope.querySelectorAll('[data-viswiz-schema-subtype-select]').forEach((subtypeSelect) => {
      const row = subtypeSelect.closest('tr');
      const typeSelects = row ? row.querySelectorAll('select[name$="_type][]"]') : [];
      const isSource = subtypeSelect.name && subtypeSelect.name.includes('source_subtype');
      const typeSelect = isSource ? typeSelects[0] : typeSelects[typeSelects.length - 1];
      const selectedType = typeSelect ? typeSelect.value : '';
      Array.from(subtypeSelect.options).forEach((option) => {
        const optionType = option.dataset.nodeType || '';
        option.hidden = !!(selectedType && optionType && optionType !== selectedType);
      });
      const selectedOption = subtypeSelect.selectedOptions && subtypeSelect.selectedOptions[0];
      if (selectedOption && selectedType && selectedOption.dataset.nodeType && selectedOption.dataset.nodeType !== selectedType) {
        subtypeSelect.value = '';
      }
    });
  }

  $(document).on('change', 'select[name="relation_schema[source_type][]"], select[name="relation_schema[target_type][]"]', function () {
    updateSchemaSubtypeSelects(this.closest('tr') || document);
  });


  function ensureNodeDescriptionEditorId(textarea) {
    if (!textarea) return '';
    if (!textarea.id) textarea.id = `viswiz-node-description-${Math.random().toString(36).slice(2)}`;
    return textarea.id;
  }

  function getActiveTinyMceEditor(textarea) {
    const id = ensureNodeDescriptionEditorId(textarea);
    return id && window.tinyMCE && typeof tinyMCE.get === 'function' ? tinyMCE.get(id) : null;
  }

  function syncNodeDescriptionEditor(card) {
    if (!card) return;
    const textarea = card.querySelector('textarea[data-viswiz-node-description], textarea.viswiz-rich-description-textarea');
    if (!textarea) return;
    const editor = getActiveTinyMceEditor(textarea);
    if (editor && typeof editor.save === 'function') editor.save();
  }

  function destroyNodeDescriptionEditor(card) {
    if (!card) return;
    const textarea = card.querySelector('textarea[data-viswiz-node-description], textarea.viswiz-rich-description-textarea');
    if (!textarea) return;
    syncNodeDescriptionEditor(card);
    const id = ensureNodeDescriptionEditorId(textarea);
    if (window.wp && wp.editor && typeof wp.editor.remove === 'function') {
      try { wp.editor.remove(id); } catch (error) {}
    } else if (window.tinyMCE && tinyMCE.get && tinyMCE.get(id)) {
      try { tinyMCE.get(id).remove(); } catch (error) {}
    }
    textarea.dataset.viswizWysiwygActive = '0';
    const field = textarea.closest('[data-viswiz-rich-description-editor]');
    if (field) field.classList.remove('is-wysiwyg-active');
  }

  function saveModalFromKeyboard(modal) {
    if (!modal) return false;
    const relationCard = modal.querySelector('[data-viswiz-relation-card].is-editing');
    const nodeCard = modal.querySelector('[data-viswiz-node-card].is-editing');
    if (relationCard) {
      autosaveRelationAndClose(relationCard);
      return true;
    }
    if (nodeCard) {
      autosaveNodeAndClose(nodeCard);
      return true;
    }
    return false;
  }

  function initNodeDescriptionEditor(card) {
    if (!card) return;
    const textarea = card.querySelector('textarea[data-viswiz-node-description], textarea.viswiz-rich-description-textarea');
    if (!textarea || textarea.dataset.viswizWysiwygActive === '1') return;
    const id = ensureNodeDescriptionEditorId(textarea);
    const field = textarea.closest('[data-viswiz-rich-description-editor]');
    if (!window.wp || !wp.editor || typeof wp.editor.initialize !== 'function') {
      if (field) field.classList.add('is-wysiwyg-fallback');
      return;
    }
    try {
      wp.editor.initialize(id, {
        tinymce: {
          wpautop: true,
          menubar: false,
          branding: false,
          height: 260,
          toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
          toolbar2: '',
          setup(editor) {
            editor.on('keydown', (event) => {
              const original = event.originalEvent || event;
              if ((original.ctrlKey || original.metaKey) && original.key === 'Enter') {
                original.preventDefault();
                syncNodeDescriptionEditor(card);
                saveModalFromKeyboard(card.closest('[data-viswiz-node-editor-modal]'));
              }
            });
            editor.on('change keyup paste', () => {
              editor.save();
              textarea.dispatchEvent(new Event('input', { bubbles: true }));
            });
          },
        },
        quicktags: true,
        mediaButtons: false,
      });
      textarea.dataset.viswizWysiwygActive = '1';
      if (field) field.classList.add('is-wysiwyg-active');
    } catch (error) {
      if (field) field.classList.add('is-wysiwyg-fallback');
    }
  }

  function getFocusableElements(container) {
    if (!container) return [];
    return Array.from(container.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"]), [contenteditable="true"]')).filter((el) => {
      if (el.closest('[hidden], [aria-hidden="true"]')) return false;
      const style = window.getComputedStyle ? window.getComputedStyle(el) : null;
      return !style || (style.visibility !== 'hidden' && style.display !== 'none');
    });
  }

  function focusFirstModalControl(modal) {
    const focusables = getFocusableElements(modal);
    const preferred = focusables.find((el) => el.matches('[data-viswiz-node-title], [data-viswiz-relation-type-select] + .viswiz-smart-select input, input, select, textarea')) || focusables[0];
    if (preferred && typeof preferred.focus === 'function') preferred.focus();
  }

  function setupModalKeyboardAccessibility() {
    document.addEventListener('keydown', (event) => {
      const modal = document.querySelector('[data-viswiz-relation-editor-modal], [data-viswiz-node-editor-modal]');
      if (!modal) return;
      if (event.key === 'Tab') {
        const focusables = getFocusableElements(modal);
        if (!focusables.length) return;
        const first = focusables[0];
        const last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
        return;
      }
      if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
        event.preventDefault();
        event.stopPropagation();
        saveModalFromKeyboard(modal);
        return;
      }
      if (event.key !== 'Enter' || event.defaultPrevented) return;
      const target = event.target;
      if (!target || target.closest('textarea, [contenteditable="true"], .wp-editor-wrap, .mce-container, button, a')) return;
      if (target.matches('select')) return;
      if (target.matches('input, [role="combobox"]')) {
        const smartWrapper = target.closest('[data-viswiz-smart-select]');
        if (smartWrapper) {
          const menu = smartWrapper.querySelector('[data-viswiz-smart-menu]');
          if (menu && !menu.hidden && menu.querySelector('[data-viswiz-smart-value]')) return;
        }
        event.preventDefault();
        event.stopPropagation();
        saveModalFromKeyboard(modal);
      }
    }, true);
  }

  function setupSemanticEditingRoles() {
    document.querySelectorAll('.viswiz-meta-tabs').forEach((tablist) => tablist.setAttribute('role', 'tablist'));
    document.querySelectorAll('.viswiz-tab-button').forEach((button) => {
      const tab = button.dataset.viswizTab || '';
      button.setAttribute('role', 'tab');
      button.setAttribute('tabindex', button.classList.contains('is-active') ? '0' : '-1');
      if (tab) {
        const panel = document.querySelector(`[data-viswiz-panel="${tab}"]`);
        if (panel) {
          if (!panel.id) panel.id = `viswiz-panel-${tab}`;
          button.setAttribute('aria-controls', panel.id);
          panel.setAttribute('role', 'tabpanel');
        }
      }
    });
  }

  function setupTabKeyboardAccessibility() {
    document.addEventListener('keydown', (event) => {
      const button = event.target.closest && event.target.closest('.viswiz-tab-button');
      if (!button) return;
      const keys = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
      if (!keys.includes(event.key)) return;
      const tabs = Array.from(button.closest('.viswiz-meta-tabs, [role="tablist"]')?.querySelectorAll('.viswiz-tab-button') || document.querySelectorAll('.viswiz-tab-button'));
      const current = tabs.indexOf(button);
      if (current < 0 || !tabs.length) return;
      let next = current;
      if (event.key === 'Home') next = 0;
      else if (event.key === 'End') next = tabs.length - 1;
      else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (current - 1 + tabs.length) % tabs.length;
      else if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (current + 1) % tabs.length;
      event.preventDefault();
      tabs[next].focus();
      tabs[next].click();
    });
  }

  $(document).ready(function () {
    setupSemanticEditingRoles();
    setupModalKeyboardAccessibility();
    setupTabKeyboardAccessibility();
    enhanceAllNodeCards();
    document.querySelectorAll('[data-viswiz-node-card]').forEach((card) => updateProposedSubtype(card));
    document.querySelectorAll('[data-viswiz-node-card]').forEach((card) => updateNodeSummary(card));
    refreshNodeDatalist();
    initializeRelationNodeAutocomplete();
    refreshSmartSelects(document);
    refreshNodeRelationTools();
    buildNodeTypeFilterOptions();
    filterNodeList();
    updateVisualizationFields();
    updateBuilderSteps($('.viswiz-tab-button.is-active').data('viswiz-tab') || 'data');
    updateSchemaSubtypeSelects(document);
    updateSalesPeriodVisibility();
    $('.viswiz-tab-button.is-active').trigger('click');
    validateGraph();
    refreshMiniGraphPreview();
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
    const values = {
      primary: $('#viswiz_color_primary').val() || '#4caf50',
      secondary: $('#viswiz_color_secondary').val() || '#2196f3',
      accent: $('#viswiz_color_accent').val() || '#ffc107',
      background: $('#viswiz_color_background').val() || '#ffffff',
      text: $('#viswiz_color_text').val() || '#333333',
    };
    $('[data-viswiz-node-modal-label]').each(function () {
      const key = this.dataset.viswizNodeModalLabel;
      if (!key) return;
      values[key] = $(this).val() || this.dataset.viswizNodeModalDefault || '';
    });
    return values;
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
      const node_type = $(this).find('[data-viswiz-node-type]').val() || '';
      const node_subtype = $(this).find('[data-viswiz-node-subtype]').val() || '';
      const main_image = $(this).find('[data-viswiz-main-image-value]').val() || '';
      const other_images = ($(this).find('[data-viswiz-other-images-value]').val() || '').split(',').filter(Boolean);
      if (id || label || title) {
        nodes.push({ id, label, title, node_type, node_subtype, main_image, other_images });
      }
    });
    $('#viswiz-visual-graph-links .viswiz-relation-card').each(function () {
      const from = $(this).find('[name$="[from][]"]').val() || '';
      const to = $(this).find('[name$="[to][]"]').val() || '';
      const label = $(this).find('input[name$="[label][]"]').val() || '';
      if (from || to) {
        const direction = $(this).find('select[name$="[direction][]"], input[name$="[direction][]"]').val() || 'directed';
        const intensity = parseFloat($(this).find('input[name$="[intensity][]"]').val()) || 1;
        const relation_type = $(this).find('[name$="[relation_type][]"]').val() || '';
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
      nodeStyle: $('#viswiz_graph_node_style').val() || 'card',
      labelStyle: $('#viswiz_graph_node_label_style').val() || 'rounded',
      nodeCardWidth: parseInt($('#viswiz_graph_node_card_width').val(), 10) || 150,
      scaleNodesByRelations: $('#viswiz_graph_scale_nodes_by_relations').is(':checked'),
      relationSizeStep: parseInt($('#viswiz_graph_relation_size_step').val(), 10) || 3,
      maxRelationSizeBoost: parseInt($('#viswiz_graph_max_relation_size_boost').val(), 10) || 30,
    };
  }


  function applyGraphDatasetOptions(container, colors, animation) {
    const options = getGraphOptions();
    container.dataset.nodeRadius = String(options.nodeRadius);
    container.dataset.linkDistance = String(options.linkDistance);
    container.dataset.chargeStrength = String(options.chargeStrength);
    container.dataset.nodeStyle = options.nodeStyle;
    container.dataset.nodeLabelStyle = options.labelStyle;
    container.dataset.nodeCardWidth = String(options.nodeCardWidth);
    container.dataset.scaleNodesByRelations = options.scaleNodesByRelations ? '1' : '0';
    container.dataset.relationSizeStep = String(options.relationSizeStep);
    container.dataset.maxRelationSizeBoost = String(options.maxRelationSizeBoost);
    container.dataset.showNodeImages = $('#viswiz_graph_show_node_images').is(':checked') ? '1' : '0';
    container.dataset.showTypeBadges = $('#viswiz_graph_show_type_badges').is(':checked') ? '1' : '0';
    container.dataset.showFullscreenToggle = $('#viswiz_show_fullscreen_toggle').is(':checked') ? '1' : '0';
    container.dataset.animation = animation || 'none';
    container.dataset.colors = JSON.stringify(colors || getFormattingColors());
  }

  function renderPreviewGraph(container, data, options) {
    if (window.VisWiz && typeof window.VisWiz.renderGraph === 'function') {
      window.VisWiz.applyFormatting(container);
      window.VisWiz.renderGraph(container, data);
      return;
    }

    container.innerHTML = '';

    const graphOptions = options || getGraphOptions();
    const nodes = (data.nodes || []).map(function (n) {
      return { id: n.id, label: n.label || n.id };
    });
    const nodeIds = new Set(nodes.map(function (node) { return String(node.id); }));
    const links = (data.links || []).map(function (l) {
      return { source: l.from, target: l.to, label: l.label || '', direction: l.direction || 'directed', intensity: parseFloat(l.intensity || 1), relation_type: l.relation_type || '' };
    }).filter(function (link) {
      return nodeIds.has(String(link.source)) && nodeIds.has(String(link.target));
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
    const nodeStyle = graphOptions.nodeStyle || 'card';
    const labelStyle = graphOptions.labelStyle || 'rounded';
    const nodeCardWidth = graphOptions.nodeCardWidth || 150;
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
      .force('collision', d3.forceCollide().radius(nodeStyle === 'card' ? Math.hypot(nodeCardWidth, 80) / 2 + 10 : nodeRadius + 10));

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

    node.append(nodeStyle === 'round' ? 'circle' : 'rect')
      .attr('r', nodeStyle === 'round' ? nodeRadius : null)
      .attr('x', nodeStyle === 'round' ? null : -nodeCardWidth / 2)
      .attr('y', nodeStyle === 'round' ? null : -23)
      .attr('width', nodeStyle === 'round' ? null : nodeCardWidth)
      .attr('height', nodeStyle === 'round' ? null : 46)
      .attr('rx', nodeStyle === 'round' ? null : (labelStyle === 'pill' ? 23 : (labelStyle === 'plain' ? 0 : 10)))
      .attr('fill', nodeStyle === 'round' ? colors.primary : (labelStyle === 'plain' ? 'transparent' : '#fff'))
      .attr('stroke', colors.primary)
      .attr('stroke-width', 2);

    node.append('text')
      .attr('dy', 4)
      .attr('text-anchor', 'middle')
      .attr('font-size', 11)
      .attr('fill', nodeStyle === 'round' ? '#fff' : colors.text)
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
    } else if (isChartLikeType(type)) {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-pie';
      applyPreviewFormatting(vizContainer, colors, animation);
      const typeLabel = getVisualizationTypeLabel(type);

      if (source === 'manual') {
        const items = gatherManualPie();
        if (items.length) {
          renderPreviewPie(vizContainer, { title: label || `${typeLabel} preview`, values: items });
        } else {
          vizContainer.textContent = `No manual ${typeLabel.toLowerCase()} data entered.`;
        }
      } else {
        renderPreviewPie(vizContainer, {
          title: label || `${typeLabel} sales preview`,
          values: [
            { label: 'Completed', value: 45, color: '#4caf50' },
            { label: 'Processing', value: 30, color: '#2196f3' },
            { label: 'Pending', value: 25, color: '#ffc107' },
          ],
        });
      }
      container.appendChild(vizContainer);
    } else if (isDiagramLikeType(type)) {
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
    } else if (isGraphLikeType(type)) {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-graph';
      applyGraphDatasetOptions(vizContainer, colors, animation);
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
