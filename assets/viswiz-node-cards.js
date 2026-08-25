(() => {
  'use strict';

  const svgNS = 'http://www.w3.org/2000/svg';
  const specCache = new WeakMap();
  const queued = new WeakSet();
  const facetState = new WeakMap();
  const $ = (selector, root = document) => root.querySelector(selector);
  const i18n = window.VisWizFrontendV2?.i18n || {};
  const greek = (document.documentElement.lang || '').toLowerCase().startsWith('el');
  const fallbacks = greek ? {
    propertyFilterMode: 'Τρόπος φιλτραρίσματος ιδιότητας',
    fadeOthers: 'Αχνά τα υπόλοιπα',
    hideOthers: 'Απόκρυψη υπολοίπων',
    clearPropertyFilter: 'Καθαρισμός φίλτρου ιδιότητας',
    nodeType: 'Τύπος node',
    nodeSubtype: 'Ιδιότητα node',
    nodesWithProperty: 'Nodes με αυτή την ιδιότητα',
    viewProperty: 'Προβολή ιδιότητας',
    selectInGraph: 'Επισήμανση στο γράφημα',
    close: 'Κλείσιμο',
    nodes: 'nodes',
  } : {
    propertyFilterMode: 'Property filter mode',
    fadeOthers: 'Fade others',
    hideOthers: 'Hide others',
    clearPropertyFilter: 'Clear property filter',
    nodeType: 'Node type',
    nodeSubtype: 'Node property',
    nodesWithProperty: 'Nodes with this property',
    viewProperty: 'View property',
    selectInGraph: 'Highlight in graph',
    close: 'Close',
    nodes: 'nodes',
  };
  const tr = (key, fallback = '') => i18n[key] || fallbacks[key] || fallback || key;

  function datasetPreviewSpec() {
    const payloadNode = $('#viswiz-dataset-payload');
    const editor = $('#viswiz-dataset-editor');
    if (!payloadNode || !editor || editor.dataset.schema !== 'graph') return null;
    try {
      return {
        id: `dataset-${Number(editor.dataset.datasetId || 0)}`,
        schema: 'graph',
        settings: { show_node_images: true, show_type_badges: true },
        data: JSON.parse(payloadNode.textContent || '{}'),
      };
    } catch (_) {
      return null;
    }
  }

  function getSpec(container) {
    if (specCache.has(container)) return specCache.get(container);
    let promise;
    if (container.matches('[data-viswiz-inline-spec]')) {
      promise = Promise.resolve(datasetPreviewSpec());
    } else if (container.dataset.viswizEndpoint) {
      promise = fetch(container.dataset.viswizEndpoint, { credentials: 'same-origin' })
        .then(async (response) => {
          const body = await response.json();
          return response.ok && !body?.code ? body : null;
        })
        .catch(() => null);
    } else {
      promise = Promise.resolve(null);
    }
    specCache.set(container, promise);
    return promise;
  }

  function nativeToolbarSelects(container) {
    const toolbar = $('.viswiz-graph-toolbar', container);
    return toolbar ? [...toolbar.querySelectorAll('select:not(.viswiz-property-filter-mode)')] : [];
  }

  function visibleNodes(container, spec) {
    const allNodes = Array.isArray(spec?.data?.nodes) ? spec.data.nodes : [];
    const toolbar = $('.viswiz-graph-toolbar', container);
    const query = (toolbar?.querySelector('input[type="search"]')?.value || '').trim().toLowerCase();
    const selects = nativeToolbarSelects(container);
    const nodeType = selects[0]?.value || '';
    return allNodes.filter((node) => {
      if (nodeType && node.node_type !== nodeType) return false;
      if (!query) return true;
      return `${node.title || ''} ${node.label || ''} ${node.slug || ''} ${node.node_type || ''} ${node.node_subtype || ''}`.toLowerCase().includes(query);
    });
  }

  function visibleRelations(container, spec, nodes) {
    const relations = Array.isArray(spec?.data?.relations) ? spec.data.relations : [];
    const ids = new Set(nodes.map((node) => String(node.uuid)));
    const selects = nativeToolbarSelects(container);
    const relationType = selects[1]?.value || '';
    return relations.filter((relation) => ids.has(String(relation.from_node_uuid)) && ids.has(String(relation.to_node_uuid)) && (!relationType || relation.relation_type === relationType));
  }

  function primaryImage(node) {
    const gallery = Array.isArray(node?.image_gallery) ? node.image_gallery.filter((image) => image?.url) : [];
    return gallery.find((image) => image.featured) || gallery[0] || null;
  }

  function svgEl(tag, attrs = {}) {
    const element = document.createElementNS(svgNS, tag);
    Object.entries(attrs).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') element.setAttribute(key, String(value));
    });
    return element;
  }

  function ensureDefs(svg) {
    if (!svg) return null;
    let defs = svg.querySelector(':scope > defs');
    if (!defs) {
      defs = svgEl('defs');
      svg.insertBefore(defs, svg.firstChild);
    }
    return defs;
  }

  function safeId(value) {
    return String(value || '').replace(/[^a-zA-Z0-9_-]/g, '') || Math.random().toString(36).slice(2);
  }

  function labelize(value) {
    return String(value || '')
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/(^|\s)\S/g, (letter) => letter.toUpperCase());
  }

  function truncateTag(value, max = 18) {
    const text = labelize(value);
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
  }

  function tagWidth(label) {
    return Math.max(36, Math.min(92, 15 + label.length * 5.15));
  }

  function wrapTitle(value, maxChars = 28) {
    const words = String(value || '').trim().split(/\s+/).filter(Boolean);
    if (!words.length) return [''];
    const lines = [];
    let current = '';
    words.forEach((word) => {
      if (word.length > maxChars) {
        if (current) {
          lines.push(current);
          current = '';
        }
        for (let offset = 0; offset < word.length; offset += maxChars) lines.push(word.slice(offset, offset + maxChars));
        return;
      }
      const next = current ? `${current} ${word}` : word;
      if (next.length <= maxChars) current = next;
      else {
        if (current) lines.push(current);
        current = word;
      }
    });
    if (current) lines.push(current);
    return lines.length ? lines : [''];
  }

  function propertyKindLabel(kind) {
    return kind === 'node_subtype' ? tr('nodeSubtype', 'Node property') : tr('nodeType', 'Node type');
  }

  function stateFor(container) {
    if (!facetState.has(container)) facetState.set(container, { kind: '', value: '', mode: 'fade' });
    return facetState.get(container);
  }

  function ensureFacetControls(container, spec) {
    const toolbar = $('.viswiz-graph-toolbar', container);
    if (!toolbar || toolbar.querySelector('.viswiz-property-filter-mode')) return;

    const state = stateFor(container);
    const mode = document.createElement('select');
    mode.className = 'viswiz-property-filter-mode';
    mode.setAttribute('aria-label', tr('propertyFilterMode', 'Property filter mode'));
    mode.append(new Option(tr('fadeOthers', 'Fade others'), 'fade'), new Option(tr('hideOthers', 'Hide others'), 'hide'));
    mode.value = state.mode;

    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'viswiz-property-filter-clear';
    clear.hidden = true;
    clear.setAttribute('aria-label', tr('clearPropertyFilter', 'Clear property filter'));

    const status = toolbar.querySelector('.viswiz-graph-status');
    toolbar.insertBefore(mode, status || null);
    toolbar.insertBefore(clear, status || null);

    mode.addEventListener('change', () => {
      state.mode = mode.value === 'hide' ? 'hide' : 'fade';
      applyFacet(container, spec);
    });
    clear.addEventListener('click', () => clearFacet(container, spec));
    container.addEventListener('viswiz:clear-property-filter', () => clearFacet(container, spec));
  }

  function selectFacet(container, spec, kind, value) {
    const state = stateFor(container);
    if (state.kind === kind && state.value === value) {
      state.kind = '';
      state.value = '';
    } else {
      state.kind = kind;
      state.value = value;
    }
    applyFacet(container, spec);
  }

  function clearFacet(container, spec) {
    const state = stateFor(container);
    state.kind = '';
    state.value = '';
    applyFacet(container, spec);
  }

  function applyFacet(container, spec) {
    const state = stateFor(container);
    const active = Boolean(state.kind && state.value);
    const allNodes = Array.isArray(spec?.data?.nodes) ? spec.data.nodes : [];
    const matched = new Set();

    allNodes.forEach((node) => {
      if (!active || String(node[state.kind] || '') === String(state.value)) matched.add(String(node.uuid));
    });

    container.classList.toggle('has-viswiz-property-filter', active);
    container.querySelectorAll('.viswiz-graph-node').forEach((group) => {
      const uuid = String(group.getAttribute('data-viswiz-node-uuid') || '');
      const match = !active || matched.has(uuid);
      group.classList.toggle('is-viswiz-property-muted', active && !match && state.mode === 'fade');
      group.classList.toggle('is-viswiz-property-hidden', active && !match && state.mode === 'hide');
      group.style.display = active && !match && state.mode === 'hide' ? 'none' : '';
      group.style.opacity = active && !match && state.mode === 'fade' ? '0.18' : '';
      group.style.filter = active && !match && state.mode === 'fade' ? 'grayscale(1)' : '';

      group.querySelectorAll('.viswiz-node-card-tag').forEach((tag) => {
        const isActiveTag = active && tag.dataset.viswizPropertyKind === state.kind && tag.dataset.viswizPropertyValue === state.value;
        tag.classList.toggle('is-active', isActiveTag);
        tag.setAttribute('aria-pressed', isActiveTag ? 'true' : 'false');
      });
    });

    const nodes = visibleNodes(container, spec);
    const relations = visibleRelations(container, spec, nodes);
    const edges = [...container.querySelectorAll('.viswiz-graph-edge')];
    const labels = [...container.querySelectorAll('.viswiz-graph-edge-label')];
    relations.forEach((relation, index) => {
      const match = !active || (matched.has(String(relation.from_node_uuid)) && matched.has(String(relation.to_node_uuid)));
      const edge = edges[index];
      const label = labels[index];
      if (edge) {
        edge.style.display = active && !match && state.mode === 'hide' ? 'none' : '';
        edge.style.opacity = active && !match && state.mode === 'fade' ? '0.12' : '';
      }
      if (label) {
        label.style.display = active && !match && state.mode === 'hide' ? 'none' : '';
        label.style.opacity = active && !match && state.mode === 'fade' ? '0.18' : '';
      }
    });

    const clear = $('.viswiz-property-filter-clear', container);
    const mode = $('.viswiz-property-filter-mode', container);
    if (mode) mode.value = state.mode;
    if (clear) {
      clear.hidden = !active;
      if (active) {
        const count = allNodes.filter((node) => String(node[state.kind] || '') === String(state.value)).length;
        clear.textContent = `× ${labelize(state.value)} (${count})`;
        clear.title = tr('clearPropertyFilter', 'Clear property filter');
      }
    }
  }

  function addTag(group, rawLabel, x, y, maxChars, kind, container, spec) {
    if (!rawLabel) return 0;
    const textValue = truncateTag(rawLabel, maxChars);
    const width = tagWidth(textValue);
    const tag = svgEl('g', {
      class: 'viswiz-node-card-tag',
      role: 'button',
      tabindex: '0',
      'aria-label': `${propertyKindLabel(kind)}: ${labelize(rawLabel)}`,
      'aria-pressed': 'false',
    });
    tag.dataset.viswizPropertyKind = kind;
    tag.dataset.viswizPropertyValue = String(rawLabel);
    tag.appendChild(svgEl('rect', {
      x,
      y,
      width,
      height: 18,
      rx: 9,
      fill: 'rgba(15,23,42,.84)',
      stroke: 'rgba(255,255,255,.62)',
      'stroke-width': '.65',
      class: 'viswiz-node-card-tag-bg',
    }));
    const text = svgEl('text', {
      x: x + width / 2,
      y: y + 12.2,
      'text-anchor': 'middle',
      fill: '#ffffff',
      'font-size': '8.7',
      'font-weight': '600',
      'font-family': 'inherit',
      class: 'viswiz-node-card-tag-text',
    });
    text.textContent = textValue;
    const tooltip = svgEl('title');
    tooltip.textContent = labelize(rawLabel);
    tag.append(text, tooltip);

    const activate = (event) => {
      event.preventDefault();
      event.stopPropagation();
      selectFacet(container, spec, kind, String(rawLabel));
    };
    tag.addEventListener('click', activate);
    tag.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') activate(event);
    });
    group.appendChild(tag);
    return width;
  }

  function enforcePresentation(group) {
    group.querySelectorAll('.viswiz-node-card-cover,.viswiz-node-card-shade,.viswiz-node-card-title-panel').forEach((element) => {
      element.setAttribute('pointer-events', 'none');
    });
    const cover = group.querySelector('.viswiz-node-card-cover');
    if (cover) cover.setAttribute('preserveAspectRatio', 'xMidYMid slice');
    const shade = group.querySelector('.viswiz-node-card-shade');
    if (shade) shade.setAttribute('fill', 'rgba(0,0,0,.13)');
    const panel = group.querySelector('.viswiz-node-card-title-panel');
    if (panel) panel.setAttribute('fill', 'rgba(0,0,0,.72)');
    group.querySelectorAll('.viswiz-node-card-tag-bg').forEach((tag) => {
      tag.setAttribute('fill', 'rgba(15,23,42,.84)');
      tag.setAttribute('stroke', 'rgba(255,255,255,.62)');
      tag.setAttribute('stroke-width', '.65');
    });
    group.querySelectorAll('.viswiz-node-card-tag-text').forEach((text) => {
      text.setAttribute('fill', '#ffffff');
      text.setAttribute('font-size', '8.7');
      text.setAttribute('font-weight', '600');
    });
  }

  function styleNode(group, node, spec, index, container) {
    if (!group || !node) return;

    const alreadyStyled = group.dataset.viswizNodeCardStyled === '1';
    group.classList.add('viswiz-node-card-styled');
    group.setAttribute('data-viswiz-node-uuid', String(node.uuid || ''));
    group.querySelectorAll('.viswiz-graph-node-image-frame,.viswiz-graph-node-image').forEach((element) => element.remove());

    const background = [...group.children].find((child) => child.tagName?.toLowerCase() === 'rect' && !child.classList.contains('viswiz-node-card-title-panel') && !child.classList.contains('viswiz-node-card-shade'));
    if (!background) return;
    if (alreadyStyled) {
      enforcePresentation(group);
      applyFacet(container, spec);
      return;
    }

    const width = 200;
    const titleValue = node.title || node.label || node.slug || '';
    const titleLines = wrapTitle(titleValue, 28);
    const titlePanelHeight = Math.max(34, 13 + titleLines.length * 13);
    const typeText = node.node_type ? truncateTag(node.node_type, 18) : '';
    const subtypeText = node.node_subtype ? truncateTag(node.node_subtype, 18) : '';
    const typeWidth = typeText ? tagWidth(typeText) : 0;
    const subtypeWidth = subtypeText ? tagWidth(subtypeText) : 0;
    const stackedTags = Boolean(typeWidth && subtypeWidth && typeWidth + subtypeWidth + 6 > width - 14);
    const tagAreaHeight = node.node_type && spec.settings?.show_type_badges !== false ? (stackedTags ? 52 : 31) : 10;
    const height = Math.max(90, tagAreaHeight + titlePanelHeight + 8);
    const x = -width / 2;
    const y = -height / 2;
    const rx = 14;
    const titlePanelY = y + height - titlePanelHeight;

    background.setAttribute('x', String(x));
    background.setAttribute('y', String(y));
    background.setAttribute('width', String(width));
    background.setAttribute('height', String(height));
    background.setAttribute('rx', String(rx));

    const title = group.querySelector('.viswiz-graph-node-title');
    if (title) {
      title.replaceChildren();
      title.setAttribute('x', '0');
      title.setAttribute('text-anchor', 'middle');
      title.setAttribute('fill', '#ffffff');
      title.setAttribute('font-weight', '700');
      title.setAttribute('font-size', '10.8');
      title.setAttribute('font-family', 'inherit');
      title.setAttribute('pointer-events', 'none');
      const startY = titlePanelY + Math.max(16, (titlePanelHeight - (titleLines.length - 1) * 13) / 2 + 4);
      titleLines.forEach((line, lineIndex) => {
        const tspan = svgEl('tspan', { x: 0, y: startY + lineIndex * 13 });
        tspan.textContent = line;
        title.appendChild(tspan);
      });
    }

    const oldType = group.querySelector('.viswiz-graph-node-type-label');
    if (oldType) oldType.setAttribute('display', 'none');
    group.dataset.viswizNodeCardStyled = '1';

    const svg = group.ownerSVGElement;
    const defs = ensureDefs(svg);
    const image = spec.settings?.show_node_images === false ? null : primaryImage(node);
    const suffix = `${safeId(spec.id)}-${safeId(node.uuid || index)}`;
    const clipId = `viswiz-node-clip-${suffix}`;

    if (image && defs) {
      defs.querySelector(`#${CSS.escape(clipId)}`)?.remove();
      const clip = svgEl('clipPath', { id: clipId });
      clip.appendChild(svgEl('rect', { x, y, width, height, rx }));
      defs.appendChild(clip);

      const picture = svgEl('image', {
        x,
        y,
        width,
        height,
        preserveAspectRatio: 'xMidYMid slice',
        href: image.url,
        'clip-path': `url(#${clipId})`,
        'pointer-events': 'none',
        class: 'viswiz-node-card-cover',
      });
      picture.setAttributeNS('http://www.w3.org/1999/xlink', 'href', image.url);
      background.after(picture);
    }

    const clipPath = image ? `url(#${clipId})` : null;
    const shade = svgEl('rect', {
      x,
      y,
      width,
      height,
      rx,
      fill: image ? 'rgba(0,0,0,.13)' : 'rgba(0,0,0,.03)',
      'clip-path': clipPath,
      'pointer-events': 'none',
      class: 'viswiz-node-card-shade',
    });
    const titlePanel = svgEl('rect', {
      x,
      y: titlePanelY,
      width,
      height: titlePanelHeight,
      fill: 'rgba(0,0,0,.72)',
      'clip-path': clipPath,
      'pointer-events': 'none',
      class: 'viswiz-node-card-title-panel',
    });
    const insertionPoint = group.querySelector('.viswiz-node-card-cover') || background;
    insertionPoint.after(shade, titlePanel);

    if (spec.settings?.show_type_badges !== false && node.node_type) {
      const firstWidth = addTag(group, node.node_type, x + 7, y + 7, 18, 'node_type', container, spec);
      if (node.node_subtype) {
        const subtypeX = stackedTags ? x + 7 : x + 13 + firstWidth;
        const subtypeY = stackedTags ? y + 28 : y + 7;
        addTag(group, node.node_subtype, subtypeX, subtypeY, 18, 'node_subtype', container, spec);
      }
    }
    enforcePresentation(group);
    applyFacet(container, spec);
  }

  function ensureNodeVisible(container, spec, uuid) {
    let target = container.querySelector(`[data-viswiz-node-uuid="${String(uuid)}"]`);
    if (target) return target;
    clearFacet(container, spec);
    const toolbar = $('.viswiz-graph-toolbar', container);
    const search = toolbar?.querySelector('input[type="search"]');
    const selects = nativeToolbarSelects(container);
    const nodeType = selects[0];
    if (search?.value) {
      search.value = '';
      search.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (nodeType?.value) {
      nodeType.value = '';
      nodeType.dispatchEvent(new Event('change', { bubbles: true }));
    }
    target = container.querySelector(`[data-viswiz-node-uuid="${String(uuid)}"]`);
    return target;
  }

  function propertyOverlayTarget(container) {
    const fullscreen = document.fullscreenElement;
    return fullscreen && (fullscreen === container || fullscreen.contains(container)) ? fullscreen : document.body;
  }

  function showPropertyView(container, spec, kind, value, opener = null) {
    const nodes = (Array.isArray(spec?.data?.nodes) ? spec.data.nodes : []).filter((node) => String(node[kind] || '') === String(value));
    if (!nodes.length) return;
    const position = { x: window.scrollX, y: window.scrollY };
    const overlay = document.createElement('div');
    overlay.className = 'viswiz-modal-overlay viswiz-property-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', `${propertyKindLabel(kind)}: ${labelize(value)}`);
    const modal = document.createElement('div');
    modal.className = 'viswiz-node-modal viswiz-property-modal';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'viswiz-modal-close';
    close.setAttribute('aria-label', tr('close', 'Close'));
    close.textContent = '×';
    const heading = document.createElement('h3');
    heading.textContent = labelize(value);
    const meta = document.createElement('p');
    meta.className = 'viswiz-property-kind';
    meta.textContent = `${propertyKindLabel(kind)} · ${nodes.length} ${tr('nodes', 'nodes')}`;
    const selectInGraph = document.createElement('button');
    selectInGraph.type = 'button';
    selectInGraph.className = 'viswiz-property-select-in-graph';
    selectInGraph.textContent = tr('selectInGraph', 'Highlight in graph');
    const listHeading = document.createElement('h4');
    listHeading.textContent = tr('nodesWithProperty', 'Nodes with this property');
    const list = document.createElement('ul');
    list.className = 'viswiz-property-node-list';

    nodes
      .slice()
      .sort((a, b) => String(a.title || a.label || a.slug || '').localeCompare(String(b.title || b.label || b.slug || ''), document.documentElement.lang || undefined))
      .forEach((node) => {
        const item = document.createElement('li');
        const link = document.createElement('button');
        link.type = 'button';
        link.className = 'viswiz-property-node-link';
        link.textContent = node.title || node.label || node.slug || 'Node';
        link.addEventListener('click', () => {
          dismiss(false);
          clearFacet(container, spec);
          const target = ensureNodeVisible(container, spec, node.uuid);
          Promise.resolve().then(() => {
            window.scrollTo(position.x, position.y);
            target?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
          });
        });
        item.appendChild(link);
        list.appendChild(item);
      });

    modal.append(close, heading, meta, selectInGraph, listHeading, list);
    overlay.appendChild(modal);
    propertyOverlayTarget(container).appendChild(overlay);

    const dismiss = (restoreFocus = true) => {
      document.removeEventListener('keydown', onKeydown);
      overlay.remove();
      window.scrollTo(position.x, position.y);
      if (restoreFocus && opener?.isConnected) {
        try { opener.focus({ preventScroll: true }); } catch (_) { opener.focus(); }
      }
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        dismiss();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...modal.querySelectorAll('button,[href],[tabindex]:not([tabindex="-1"])')].filter((item) => !item.disabled && !item.hidden);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    close.addEventListener('click', () => dismiss());
    overlay.addEventListener('click', (event) => { if (event.target === overlay) dismiss(); });
    selectInGraph.addEventListener('click', () => {
      dismiss(false);
      selectFacet(container, spec, kind, String(value));
    });
    document.addEventListener('keydown', onKeydown);
    try { close.focus({ preventScroll: true }); } catch (_) { close.focus(); }
    if (overlay.parentNode === document.body) window.scrollTo(position.x, position.y);
  }

  async function enhanceNodeModal(overlay) {
    if (!overlay || overlay.classList.contains('viswiz-property-overlay') || overlay.dataset.viswizPropertiesEnhanced === '1') return;
    const container = overlay.__viswizOwner || overlay.closest('.viswiz-visualization');
    if (!container) return;
    const spec = await getSpec(container);
    if (!spec || spec.schema !== 'graph') return;
    const uuid = overlay.dataset.viswizNodeUuid || container.__viswizOpeningNodeUuid || '';
    let node = (spec.data?.nodes || []).find((candidate) => String(candidate.uuid) === String(uuid));
    if (!node) {
      const title = $('.viswiz-node-modal h3', overlay)?.textContent?.trim() || '';
      node = (spec.data?.nodes || []).find((candidate) => [candidate.title, candidate.label, candidate.slug].includes(title));
    }
    if (!node) return;
    const typeLine = $('.viswiz-node-type', overlay);
    if (!typeLine) return;
    overlay.dataset.viswizPropertiesEnhanced = '1';
    typeLine.replaceChildren();
    const addPropertyLink = (kind, value) => {
      if (!value) return;
      if (typeLine.childNodes.length) typeLine.appendChild(document.createTextNode(' · '));
      const link = document.createElement('button');
      link.type = 'button';
      link.className = 'viswiz-node-property-link';
      link.textContent = labelize(value);
      link.title = `${tr('viewProperty', 'View property')}: ${labelize(value)}`;
      link.addEventListener('click', () => {
        const close = $('.viswiz-modal-close', overlay);
        close?.click();
        Promise.resolve().then(() => showPropertyView(container, spec, kind, String(value), null));
      });
      typeLine.appendChild(link);
    };
    addPropertyLink('node_type', node.node_type);
    addPropertyLink('node_subtype', node.node_subtype);
  }

  async function enhance(container) {
    const spec = await getSpec(container);
    if (!spec || spec.schema !== 'graph') return;
    ensureFacetControls(container, spec);
    const groups = [...container.querySelectorAll('.viswiz-graph-node')];
    if (!groups.length) return;
    const nodes = visibleNodes(container, spec);
    groups.forEach((group, index) => styleNode(group, nodes[index], spec, index, container));
    applyFacet(container, spec);
  }

  function queue(container) {
    if (!container || queued.has(container)) return;
    queued.add(container);
    Promise.resolve().then(() => {
      queued.delete(container);
      enhance(container);
    });
  }

  function scan(root = document) {
    const containers = [];
    if (root instanceof Element && root.matches('.viswiz-visualization')) containers.push(root);
    root.querySelectorAll?.('.viswiz-visualization').forEach((container) => containers.push(container));
    containers.forEach(queue);
  }

  function processAddedNode(node) {
    if (!(node instanceof Element)) return;
    scan(node);
    if (node.matches('.viswiz-modal-overlay')) Promise.resolve().then(() => enhanceNodeModal(node));
    node.querySelectorAll?.('.viswiz-modal-overlay').forEach((overlay) => Promise.resolve().then(() => enhanceNodeModal(overlay)));
  }

  function start() {
    scan(document);
    document.querySelectorAll('.viswiz-modal-overlay').forEach((overlay) => enhanceNodeModal(overlay));
    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        const owner = mutation.target instanceof Element ? mutation.target.closest('.viswiz-visualization') : null;
        if (owner) queue(owner);
        mutation.addedNodes.forEach(processAddedNode);
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
