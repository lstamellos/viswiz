(() => {
  'use strict';
  const { __ } = window.wp.i18n;

  const svgNS = 'http://www.w3.org/2000/svg';
  const stateMap = new WeakMap();
  const queued = new WeakSet();
  const loadQueued = new WeakSet();
  const enhancedModals = new WeakSet();
  const $ = (selector, root = document) => root.querySelector(selector);

  function stateFor(container) {
    if (!stateMap.has(container)) {
      stateMap.set(container, {
        spec: null,
        specPromise: null,
        selectedFacets: new Map(),
        focusUuid: '',
        focusHops: 1,
        pendingNodeUuid: '',
        pendingScroll: null,
        toolbar: null,
        interactionsBound: false,
      });
    }
    return stateMap.get(container);
  }

  function isGraphSpec(spec) {
    return Boolean(spec && (spec.schema === 'graph' || ['graph', 'flow_diagram', 'org_chart'].includes(spec.renderer)));
  }

  function setSpec(container, spec) {
    if (!container || !isGraphSpec(spec)) return;
    const state = stateFor(container);
    state.spec = spec;
    state.specPromise = Promise.resolve(spec);
    queue(container);
  }

  function inlinePreviewSpec(container) {
    if (!container?.matches?.('[data-viswiz-inline-spec]')) return null;
    const payload = $('#viswiz-dataset-payload');
    const editor = $('#viswiz-dataset-editor');
    if (!payload || !editor || editor.dataset.schema !== 'graph') return null;
    try {
      return {
        id: `dataset-${Number(editor.dataset.datasetId || 0)}`,
        schema: 'graph',
        renderer: 'graph',
        settings: {
          show_node_images: true,
          show_type_badges: true,
          show_graph_toolbar: true,
          show_graph_search: true,
          show_graph_filters: true,
          show_graph_zoom: true,
          show_relation_labels: true,
          full_screen: false,
        },
        data: JSON.parse(payload.textContent || '{}'),
      };
    } catch (_) {
      return null;
    }
  }

  function getSpec(container) {
    if (!container) return Promise.resolve(null);
    const state = stateFor(container);
    if (state.spec) return Promise.resolve(state.spec);
    if (state.specPromise) return state.specPromise;

    const inline = inlinePreviewSpec(container);
    if (inline) {
      state.spec = inline;
      state.specPromise = Promise.resolve(inline);
      return state.specPromise;
    }

    if (container.dataset.viswizEndpoint) {
      state.specPromise = fetch(container.dataset.viswizEndpoint, { credentials: 'same-origin' })
        .then(async (response) => {
          const body = await response.json();
          if (!response.ok || body?.code || !isGraphSpec(body)) return null;
          state.spec = body;
          return body;
        })
        .catch(() => null);
      return state.specPromise;
    }
    return Promise.resolve(null);
  }

  function installRenderBridge() {
    const api = window.VisWiz;
    if (!api || api.__graphRuntimeBridged || typeof api.render !== 'function') return;
    const originalRender = api.render.bind(api);
    api.render = (container, spec) => {
      if (isGraphSpec(spec)) setSpec(container, spec);
      const result = originalRender(container, spec);
      if (isGraphSpec(spec)) queue(container);
      return result;
    };
    api.__graphRuntimeBridged = true;
  }

  function loadLateVisualization(container) {
    if (!container || loadQueued.has(container) || !container.dataset.viswizEndpoint || !window.VisWiz?.load) return;
    if (!container.querySelector('.viswiz-loading')) return;
    loadQueued.add(container);
    Promise.resolve().then(() => {
      if (container.isConnected && container.querySelector('.viswiz-loading')) window.VisWiz.load(container);
    });
  }

  function nativeFilterSelects(containerOrToolbar) {
    const toolbar = containerOrToolbar?.classList?.contains('viswiz-graph-toolbar')
      ? containerOrToolbar
      : containerOrToolbar?.querySelector?.('.viswiz-graph-toolbar');
    return toolbar ? [...toolbar.querySelectorAll('select')] : [];
  }

  function filterSnapshot(container) {
    const toolbar = $('.viswiz-graph-toolbar', container);
    const selects = nativeFilterSelects(toolbar);
    return {
      toolbar,
      search: toolbar?.querySelector('input[type="search"]') || null,
      query: (toolbar?.querySelector('input[type="search"]')?.value || '').trim().toLowerCase(),
      nodeType: selects[0]?.value || '',
      relationType: selects[1]?.value || '',
    };
  }

  function visibleNodes(container, spec) {
    const filter = filterSnapshot(container);
    return (Array.isArray(spec?.data?.nodes) ? spec.data.nodes : []).filter((node) => {
      if (filter.nodeType && node.node_type !== filter.nodeType) return false;
      if (!filter.query) return true;
      return `${node.title || ''} ${node.label || ''} ${node.slug || ''} ${node.node_type || ''} ${node.node_subtype || ''}`
        .toLowerCase().includes(filter.query);
    });
  }

  function visibleRelations(container, spec) {
    const filter = filterSnapshot(container);
    const nodes = visibleNodes(container, spec);
    const ids = new Set(nodes.map((node) => String(node.uuid)));
    return (Array.isArray(spec?.data?.relations) ? spec.data.relations : []).filter((relation) => (
      ids.has(String(relation.from_node_uuid))
      && ids.has(String(relation.to_node_uuid))
      && (!filter.relationType || relation.relation_type === filter.relationType)
    ));
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

  function primaryImage(node) {
    const gallery = Array.isArray(node?.image_gallery) ? node.image_gallery.filter((image) => image?.url) : [];
    return gallery.find((image) => image.featured) || gallery[0] || null;
  }

  function facetKey(kind, value) {
    return `${kind}\u0000${value}`;
  }

  function toggleFacet(container, kind, value) {
    const state = stateFor(container);
    const key = facetKey(kind, value);
    if (state.selectedFacets.has(key)) state.selectedFacets.delete(key);
    else state.selectedFacets.set(key, { kind, value });
    applyState(container);
  }

  function addTag(group, rawLabel, x, y, kind, container) {
    if (!rawLabel) return 0;
    const textValue = truncateTag(rawLabel, 18);
    const width = tagWidth(textValue);
    const tag = svgEl('g', {
      class: 'viswiz-node-card-tag',
      role: 'button',
      tabindex: '0',
      'aria-label': `${kind === 'node_subtype' ? __('Property', 'viswiz') : __('Type', 'viswiz')}: ${labelize(rawLabel)}`,
      'aria-pressed': 'false',
    });
    tag.dataset.viswizPropertyKind = kind;
    tag.dataset.viswizPropertyValue = String(rawLabel);
    tag.appendChild(svgEl('rect', {
      x, y, width, height: 18, rx: 9,
      fill: 'rgba(15,23,42,.84)',
      stroke: 'rgba(255,255,255,.62)',
      'stroke-width': '.65',
      class: 'viswiz-node-card-tag-bg',
    }));
    const text = svgEl('text', {
      x: x + width / 2, y: y + 12.2, 'text-anchor': 'middle',
      fill: '#fff', 'font-size': '8.7', 'font-weight': '600',
      'font-family': 'inherit', class: 'viswiz-node-card-tag-text',
    });
    text.textContent = textValue;
    const tooltip = svgEl('title');
    tooltip.textContent = labelize(rawLabel);
    tag.append(text, tooltip);

    const activate = (event) => {
      event.preventDefault();
      event.stopPropagation();
      toggleFacet(container, kind, String(rawLabel));
    };
    tag.addEventListener('click', activate);
    tag.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') activate(event);
    });
    group.appendChild(tag);
    return width;
  }

  function styleNode(group, node, spec, index, container) {
    if (!group || !node) return;
    group.classList.add('viswiz-node-card-styled');
    group.setAttribute('data-viswiz-node-uuid', String(node.uuid || ''));

    const background = [...group.children].find((child) => (
      child.tagName?.toLowerCase() === 'rect'
      && !child.classList.contains('viswiz-node-card-title-panel')
      && !child.classList.contains('viswiz-node-card-shade')
    ));
    if (!background) return;
    if (group.dataset.viswizNodeCardStyled === '1') return;
    group.dataset.viswizNodeCardStyled = '1';

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

    const svg = group.ownerSVGElement;
    const defs = ensureDefs(svg);
    const clipId = `viswiz-node-clip-${safeId(spec.id || 'graph')}-${safeId(node.uuid || index)}-${Math.random().toString(36).slice(2, 7)}`;
    let clipValue = null;
    if (defs) {
      const clip = svgEl('clipPath', { id: clipId });
      clip.appendChild(svgEl('rect', { x, y, width, height, rx }));
      defs.appendChild(clip);
      clipValue = `url(#${clipId})`;
    }

    const image = spec.settings?.show_node_images === false ? null : primaryImage(node);
    if (image) {
      const picture = svgEl('image', {
        x, y, width, height,
        preserveAspectRatio: 'xMidYMid slice',
        href: image.url,
        'clip-path': clipValue,
        'pointer-events': 'none',
        class: 'viswiz-node-card-cover',
      });
      picture.setAttributeNS('http://www.w3.org/1999/xlink', 'href', image.url);
      background.after(picture);
    }

    const shade = svgEl('rect', {
      x, y, width, height, rx,
      fill: 'rgba(0,0,0,.13)',
      'clip-path': clipValue,
      'pointer-events': 'none',
      class: 'viswiz-node-card-shade',
    });
    const titlePanel = svgEl('rect', {
      x, y: titlePanelY, width, height: titlePanelHeight,
      fill: 'rgba(0,0,0,.72)',
      'clip-path': clipValue,
      'pointer-events': 'none',
      class: 'viswiz-node-card-title-panel',
    });
    const insertionPoint = group.querySelector('.viswiz-node-card-cover') || background;
    insertionPoint.after(shade, titlePanel);

    const title = group.querySelector('.viswiz-graph-node-title');
    if (title) {
      title.replaceChildren();
      title.setAttribute('x', '0');
      title.setAttribute('text-anchor', 'middle');
      title.setAttribute('fill', '#fff');
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
      group.appendChild(title);
    }

    const oldType = group.querySelector('.viswiz-graph-node-type-label');
    if (oldType) oldType.setAttribute('display', 'none');

    if (spec.settings?.show_type_badges !== false && node.node_type) {
      const firstWidth = addTag(group, node.node_type, x + 7, y + 7, 'node_type', container);
      if (node.node_subtype) {
        const subtypeX = stackedTags ? x + 7 : x + 13 + firstWidth;
        const subtypeY = stackedTags ? y + 28 : y + 7;
        addTag(group, node.node_subtype, subtypeX, subtypeY, 'node_subtype', container);
      }
    }
  }

  function styleNodes(container, spec) {
    const groups = [...container.querySelectorAll('.viswiz-graph-node')];
    if (!groups.length) return;
    const nodes = visibleNodes(container, spec);
    groups.forEach((group, index) => styleNode(group, nodes[index], spec, index, container));
  }

  function ensureSearchControl(container, toolbar) {
    const search = toolbar.querySelector('input[type="search"]');
    if (!search) return;
    let group = search.closest('.viswiz-search-group');
    if (!group) {
      group = document.createElement('div');
      group.className = 'viswiz-search-group';
      search.parentNode.insertBefore(group, search);
      group.appendChild(search);
    }
    let clear = group.querySelector('.viswiz-clear-search');
    if (!clear) {
      clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'viswiz-graph-tool viswiz-clear-search';
      clear.textContent = '×';
      clear.setAttribute('aria-label', __('Clear search', 'viswiz'));
      clear.title = __('Clear search', 'viswiz');
      group.appendChild(clear);
      clear.addEventListener('click', () => {
        if (!search.value) return;
        search.value = '';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        try { search.focus({ preventScroll: true }); } catch (_) { search.focus(); }
      });
      search.addEventListener('input', () => { clear.disabled = !search.value; });
    }
    clear.disabled = !search.value;
  }

  function clearSelectedFacets(container) {
    const state = stateFor(container);
    if (!state.selectedFacets.size) return;
    state.selectedFacets.clear();
    applyState(container);
  }

  function ensureClearFiltersControl(container, toolbar) {
    const selects = nativeFilterSelects(toolbar);
    const relationSelect = selects[1] || null;
    if (!relationSelect) return;
    let clear = toolbar.querySelector('.viswiz-clear-all-filters');
    if (!clear) {
      clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'viswiz-graph-tool viswiz-clear-all-filters';
      clear.textContent = __('Clear all filters', 'viswiz');
      clear.setAttribute('aria-label', __('Clear all filters', 'viswiz'));
      clear.title = __('Clear all filters', 'viswiz');
      relationSelect.after(clear);
      clear.addEventListener('click', () => {
        nativeFilterSelects(toolbar).forEach((select) => {
          if (!select.value) return;
          select.value = '';
          select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        clearSelectedFacets(container);
      });
    }
  }

  function ensureFilterGroup(toolbar) {
    let group = toolbar.querySelector(':scope > .viswiz-filter-group');
    if (!group) {
      group = document.createElement('div');
      group.className = 'viswiz-filter-group';
      const searchGroup = toolbar.querySelector(':scope > .viswiz-search-group');
      if (searchGroup) searchGroup.after(group);
      else toolbar.prepend(group);
    }
    nativeFilterSelects(toolbar).forEach((select) => group.appendChild(select));
    const clear = toolbar.querySelector('.viswiz-clear-all-filters');
    if (clear) group.appendChild(clear);
  }

  function ensureSelectedFacetHost(toolbar) {
    let host = toolbar.querySelector(':scope > .viswiz-selected-facets');
    if (!host) {
      host = document.createElement('div');
      host.className = 'viswiz-selected-facets';
      host.setAttribute('aria-label', __('Selected filters', 'viswiz'));
      const filterGroup = toolbar.querySelector(':scope > .viswiz-filter-group');
      if (filterGroup) filterGroup.after(host);
      else toolbar.appendChild(host);
    }
    return host;
  }

  function renderSelectedFacets(container) {
    const toolbar = $('.viswiz-graph-toolbar', container);
    if (!toolbar) return;
    const host = ensureSelectedFacetHost(toolbar);
    const state = stateFor(container);
    host.replaceChildren();
    host.hidden = !state.selectedFacets.size;
    state.selectedFacets.forEach((facet, key) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'viswiz-selected-facet';
      const kindLabel = facet.kind === 'node_subtype' ? __('Property', 'viswiz') : __('Type', 'viswiz');
      button.textContent = `× ${kindLabel}: ${labelize(facet.value)}`;
      button.setAttribute('aria-label', `${__('Clear filter', 'viswiz')}: ${labelize(facet.value)}`);
      button.addEventListener('click', () => {
        state.selectedFacets.delete(key);
        applyState(container);
      });
      host.appendChild(button);
    });
  }

  function ensureGraphHeader(container, toolbar) {
    let header = container.querySelector(':scope > .viswiz-graph-header');
    if (!header) {
      header = document.createElement('div');
      header.className = 'viswiz-graph-header';
      container.insertBefore(header, container.firstChild);
    }
    const title = container.querySelector(':scope > .viswiz-title');
    if (title) header.insertBefore(title, header.firstChild);
    let controls = header.querySelector(':scope > .viswiz-view-controls');
    if (!controls) {
      controls = document.createElement('div');
      controls.className = 'viswiz-view-controls';
      header.appendChild(controls);
    }
    const zoomButtons = [
      ...toolbar.querySelectorAll('button.viswiz-graph-tool'),
      ...controls.querySelectorAll('button.viswiz-graph-tool'),
    ].filter((button, index, list) => list.indexOf(button) === index && ['−', '+', '100%'].includes(button.textContent.trim()));
    zoomButtons.forEach((button) => controls.appendChild(button));
    const fullscreen = container.querySelector('.viswiz-fullscreen');
    if (fullscreen && fullscreen.parentElement !== controls) controls.appendChild(fullscreen);
  }

  function bindContainerInteractions(container) {
    const state = stateFor(container);
    if (state.interactionsBound) return;
    state.interactionsBound = true;
    container.addEventListener('click', (event) => {
      const group = event.target.closest?.('.viswiz-graph-node');
      if (!group || !container.contains(group) || event.target.closest?.('.viswiz-node-card-tag')) return;
      state.pendingNodeUuid = group.getAttribute('data-viswiz-node-uuid') || '';
      state.pendingScroll = { x: window.scrollX, y: window.scrollY };
    }, true);
    container.addEventListener('keydown', (event) => {
      if (!['Enter', ' '].includes(event.key)) return;
      const group = event.target.closest?.('.viswiz-graph-node');
      if (!group || !container.contains(group) || event.target.closest?.('.viswiz-node-card-tag')) return;
      state.pendingNodeUuid = group.getAttribute('data-viswiz-node-uuid') || '';
      state.pendingScroll = { x: window.scrollX, y: window.scrollY };
    }, true);
    container.addEventListener('viswiz:focus-connections', (event) => {
      focusConnections(container, event.detail?.uuid || '', Number(event.detail?.hops || 1));
    });
    container.addEventListener('viswiz:clear-connection-focus', () => clearFocus(container));
  }

  function nodeMatchesSelected(group, selected) {
    if (!selected.size) return true;
    return [...group.querySelectorAll('.viswiz-node-card-tag')].some((tag) => (
      selected.has(facetKey(tag.dataset.viswizPropertyKind || '', tag.dataset.viswizPropertyValue || ''))
    ));
  }

  function applyFacets(container, spec) {
    const state = stateFor(container);
    const selected = state.selectedFacets;
    const matched = new Set();
    container.querySelectorAll('.viswiz-graph-node').forEach((group) => {
      const match = nodeMatchesSelected(group, selected);
      const uuid = String(group.getAttribute('data-viswiz-node-uuid') || '');
      if (match && uuid) matched.add(uuid);
      group.style.display = '';
      group.style.opacity = selected.size && !match ? '0.18' : '';
      group.style.filter = selected.size && !match ? 'grayscale(1)' : '';
      group.classList.toggle('is-viswiz-property-muted', Boolean(selected.size && !match));
      group.querySelectorAll('.viswiz-node-card-tag').forEach((tag) => {
        const active = selected.has(facetKey(tag.dataset.viswizPropertyKind || '', tag.dataset.viswizPropertyValue || ''));
        tag.classList.toggle('is-active', active);
        tag.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    });

    const relations = visibleRelations(container, spec);
    const edges = [...container.querySelectorAll('.viswiz-graph-edge')];
    const edgeLabels = [...container.querySelectorAll('.viswiz-graph-edge-label')];
    relations.forEach((relation, index) => {
      const match = !selected.size || (matched.has(String(relation.from_node_uuid)) && matched.has(String(relation.to_node_uuid)));
      if (edges[index]) {
        edges[index].style.display = '';
        edges[index].style.opacity = selected.size && !match ? '0.12' : '';
      }
      if (edgeLabels[index]) {
        edgeLabels[index].style.display = '';
        edgeLabels[index].style.opacity = selected.size && !match ? '0.18' : '';
      }
    });
    container.classList.toggle('has-viswiz-property-filter', Boolean(selected.size));
    renderSelectedFacets(container);
  }

  function findNode(spec, uuid, title = '') {
    const nodes = Array.isArray(spec?.data?.nodes) ? spec.data.nodes : [];
    if (uuid) {
      const byUuid = nodes.find((node) => String(node.uuid) === String(uuid));
      if (byUuid) return byUuid;
    }
    if (!title) return null;
    return nodes.find((node) => [node.title, node.label, node.slug].some((value) => String(value || '') === title)) || null;
  }

  function nodeLabel(node) {
    return node?.title || node?.label || node?.slug || __('Node', 'viswiz');
  }

  function connectionNeighborhood(spec, rootUuid, hops, relationType = '') {
    const nodes = Array.isArray(spec?.data?.nodes) ? spec.data.nodes : [];
    const validIds = new Set(nodes.map((node) => String(node.uuid)));
    const root = String(rootUuid || '');
    if (!root || !validIds.has(root)) return new Set();
    const adjacency = new Map([...validIds].map((uuid) => [uuid, new Set()]));
    (Array.isArray(spec?.data?.relations) ? spec.data.relations : []).forEach((relation) => {
      if (relationType && relation.relation_type !== relationType) return;
      const from = String(relation.from_node_uuid || '');
      const to = String(relation.to_node_uuid || '');
      if (!adjacency.has(from) || !adjacency.has(to)) return;
      adjacency.get(from).add(to);
      adjacency.get(to).add(from);
    });
    const included = new Set([root]);
    let frontier = new Set([root]);
    const depth = hops === 2 ? 2 : 1;
    for (let level = 0; level < depth; level += 1) {
      const next = new Set();
      frontier.forEach((uuid) => {
        (adjacency.get(uuid) || []).forEach((neighbor) => {
          if (!included.has(neighbor)) next.add(neighbor);
          included.add(neighbor);
        });
      });
      frontier = next;
      if (!frontier.size) break;
    }
    return included;
  }

  function ensureFocusBar(container) {
    const frame = $('.viswiz-graph-frame', container);
    const toolbar = $('.viswiz-graph-toolbar', frame || container);
    if (!frame || !toolbar) return null;
    let bar = frame.querySelector(':scope > .viswiz-connection-focus-bar');
    if (bar) return bar;
    bar = document.createElement('div');
    bar.className = 'viswiz-connection-focus-bar';
    bar.hidden = true;
    bar.setAttribute('aria-live', 'polite');
    const text = document.createElement('div');
    text.className = 'viswiz-connection-focus-label';
    text.append(document.createTextNode(`${__('Connections', 'viswiz')}: `));
    const name = document.createElement('strong');
    name.className = 'viswiz-connection-focus-name';
    text.appendChild(name);
    const controls = document.createElement('div');
    controls.className = 'viswiz-connection-focus-controls';
    const one = document.createElement('button');
    one.type = 'button';
    one.className = 'viswiz-connection-hop';
    one.dataset.hops = '1';
    one.textContent = __('1 hop', 'viswiz');
    const two = document.createElement('button');
    two.type = 'button';
    two.className = 'viswiz-connection-hop';
    two.dataset.hops = '2';
    two.textContent = __('2 hops', 'viswiz');
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'viswiz-connection-focus-clear';
    clear.textContent = '×';
    clear.title = __('Clear focus', 'viswiz');
    clear.setAttribute('aria-label', __('Clear focus', 'viswiz'));
    controls.append(one, two, clear);
    bar.append(text, controls);
    toolbar.after(bar);
    one.addEventListener('click', () => { stateFor(container).focusHops = 1; applyState(container); });
    two.addEventListener('click', () => { stateFor(container).focusHops = 2; applyState(container); });
    clear.addEventListener('click', () => clearFocus(container));
    return bar;
  }

  function clearFocusClasses(container) {
    container.querySelectorAll('.is-viswiz-connection-outside,.is-viswiz-connection-root,.is-viswiz-connection-neighbor')
      .forEach((element) => element.classList.remove('is-viswiz-connection-outside', 'is-viswiz-connection-root', 'is-viswiz-connection-neighbor'));
    container.querySelectorAll('.is-viswiz-connection-focus-edge,.is-viswiz-connection-focus-edge-outside')
      .forEach((element) => element.classList.remove('is-viswiz-connection-focus-edge', 'is-viswiz-connection-focus-edge-outside'));
  }

  function applyFocus(container, spec) {
    const state = stateFor(container);
    clearFocusClasses(container);
    const bar = ensureFocusBar(container);
    if (!state.focusUuid) {
      container.classList.remove('has-viswiz-connection-focus');
      if (bar) bar.hidden = true;
      return;
    }
    const root = findNode(spec, state.focusUuid);
    if (!root) {
      state.focusUuid = '';
      if (bar) bar.hidden = true;
      return;
    }
    const relationType = filterSnapshot(container).relationType;
    const included = connectionNeighborhood(spec, state.focusUuid, state.focusHops, relationType);
    container.classList.add('has-viswiz-connection-focus');
    container.querySelectorAll('.viswiz-graph-node').forEach((group) => {
      const uuid = String(group.getAttribute('data-viswiz-node-uuid') || '');
      const inside = included.has(uuid);
      group.classList.toggle('is-viswiz-connection-outside', !inside);
      group.classList.toggle('is-viswiz-connection-root', inside && uuid === String(state.focusUuid));
      group.classList.toggle('is-viswiz-connection-neighbor', inside && uuid !== String(state.focusUuid));
    });
    const relations = visibleRelations(container, spec);
    const edges = [...container.querySelectorAll('.viswiz-graph-edge')];
    const labelsEls = [...container.querySelectorAll('.viswiz-graph-edge-label')];
    relations.forEach((relation, index) => {
      const inside = included.has(String(relation.from_node_uuid)) && included.has(String(relation.to_node_uuid));
      if (edges[index]) edges[index].classList.add(inside ? 'is-viswiz-connection-focus-edge' : 'is-viswiz-connection-focus-edge-outside');
      if (labelsEls[index]) labelsEls[index].classList.add(inside ? 'is-viswiz-connection-focus-edge' : 'is-viswiz-connection-focus-edge-outside');
    });
    if (bar) {
      bar.hidden = false;
      const name = $('.viswiz-connection-focus-name', bar);
      if (name) name.textContent = `${nodeLabel(root)} · ${included.size}`;
      bar.querySelectorAll('.viswiz-connection-hop').forEach((button) => {
        const active = Number(button.dataset.hops) === state.focusHops;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }
  }

  function focusConnections(container, uuid, hops = 1) {
    const state = stateFor(container);
    state.focusUuid = String(uuid || '');
    state.focusHops = hops === 2 ? 2 : 1;
    applyState(container);
  }

  function clearFocus(container) {
    const state = stateFor(container);
    state.focusUuid = '';
    state.focusHops = 1;
    applyState(container);
  }

  function applyState(container) {
    const state = stateFor(container);
    if (!state.spec) return;
    applyFacets(container, state.spec);
    applyFocus(container, state.spec);
  }

  function showPropertyView(container, spec, kind, value, opener = null) {
    const nodes = (Array.isArray(spec?.data?.nodes) ? spec.data.nodes : []).filter((node) => String(node[kind] || '') === String(value));
    if (!nodes.length) return;
    const position = { x: window.scrollX, y: window.scrollY };
    const overlay = document.createElement('div');
    overlay.className = 'viswiz-modal-overlay viswiz-property-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', `${kind === 'node_subtype' ? __('Property', 'viswiz') : __('Type', 'viswiz')}: ${labelize(value)}`);
    const modal = document.createElement('div');
    modal.className = 'viswiz-node-modal viswiz-property-modal';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'viswiz-modal-close';
    close.setAttribute('aria-label', __('Close', 'viswiz'));
    close.textContent = '×';
    const title = document.createElement('h3');
    title.textContent = labelize(value);
    const kindText = document.createElement('p');
    kindText.className = 'viswiz-property-kind';
    kindText.textContent = kind === 'node_subtype' ? __('Property', 'viswiz') : __('Type', 'viswiz');
    const select = document.createElement('button');
    select.type = 'button';
    select.className = 'viswiz-property-select-in-graph';
    select.textContent = __('Highlight in graph', 'viswiz');
    select.addEventListener('click', () => {
      const state = stateFor(container);
      state.selectedFacets.set(facetKey(kind, String(value)), { kind, value: String(value) });
      applyState(container);
      close.click();
    });
    const list = document.createElement('ul');
    list.className = 'viswiz-property-node-list';
    nodes.forEach((node) => {
      const li = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'viswiz-property-node-link';
      button.textContent = nodeLabel(node);
      button.addEventListener('click', () => {
        close.click();
        openNodeByUuid(container, node.uuid, opener);
      });
      li.appendChild(button);
      list.appendChild(li);
    });
    modal.append(close, title, kindText, select, list);
    overlay.appendChild(modal);
    const fullscreen = document.fullscreenElement;
    const target = fullscreen && (fullscreen === container || fullscreen.contains(container)) ? fullscreen : document.body;
    target.appendChild(overlay);
    const dismiss = () => {
      overlay.remove();
      if (opener?.isConnected) opener.focus();
      window.scrollTo(position.x, position.y);
    };
    close.addEventListener('click', dismiss);
    overlay.addEventListener('click', (event) => { if (event.target === overlay) dismiss(); });
    overlay.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        dismiss();
      }
    });
    try { close.focus({ preventScroll: true }); } catch (_) { close.focus(); }
    if (target === document.body) window.scrollTo(position.x, position.y);
  }

  function captureVisibilityFilters(container) {
    const filter = filterSnapshot(container);
    const selects = nativeFilterSelects(filter.toolbar);
    const nodeType = selects[0] || null;
    return {
      search: filter.search,
      searchValue: filter.search?.value || '',
      nodeType,
      nodeTypeValue: nodeType?.value || '',
    };
  }

  function temporarilyRevealAllNodeTypes(container) {
    const snapshot = captureVisibilityFilters(container);
    if (snapshot.search?.value) {
      snapshot.search.value = '';
      snapshot.search.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (snapshot.nodeType?.value) {
      snapshot.nodeType.value = '';
      snapshot.nodeType.dispatchEvent(new Event('change', { bubbles: true }));
    }
    return () => {
      if (snapshot.nodeType && snapshot.nodeType.value !== snapshot.nodeTypeValue) {
        snapshot.nodeType.value = snapshot.nodeTypeValue;
        snapshot.nodeType.dispatchEvent(new Event('change', { bubbles: true }));
      }
      if (snapshot.search && snapshot.search.value !== snapshot.searchValue) {
        snapshot.search.value = snapshot.searchValue;
        snapshot.search.dispatchEvent(new Event('input', { bubbles: true }));
      }
    };
  }

  function waitForNode(container, uuid, timeout = 1500) {
    const selector = `[data-viswiz-node-uuid="${CSS.escape(String(uuid))}"]`;
    const immediate = container.querySelector(selector);
    if (immediate) return Promise.resolve(immediate);
    return new Promise((resolve) => {
      let settled = false;
      const finish = (node) => {
        if (settled) return;
        settled = true;
        observer.disconnect();
        clearTimeout(timer);
        resolve(node);
      };
      const observer = new MutationObserver(() => {
        const target = container.querySelector(selector);
        if (target) finish(target);
      });
      observer.observe(container, { childList: true, subtree: true });
      const timer = window.setTimeout(() => finish(container.querySelector(selector)), timeout);
    });
  }

  function waitForNewNodeModal(existing, currentOverlay, timeout = 500) {
    const find = () => [...document.querySelectorAll('.viswiz-modal-overlay')]
      .find((candidate) => candidate !== currentOverlay && !existing.has(candidate) && !candidate.classList.contains('viswiz-property-overlay')) || null;
    const immediate = find();
    if (immediate) return Promise.resolve(immediate);
    return new Promise((resolve) => {
      const observer = new MutationObserver(() => {
        const modal = find();
        if (modal) {
          observer.disconnect();
          clearTimeout(timer);
          resolve(modal);
        }
      });
      observer.observe(document.documentElement, { childList: true, subtree: true });
      const timer = window.setTimeout(() => {
        observer.disconnect();
        resolve(find());
      }, timeout);
    });
  }

  async function openNodeByUuid(container, uuid) {
    const position = { x: window.scrollX, y: window.scrollY };
    let target = container.querySelector(`[data-viswiz-node-uuid="${CSS.escape(String(uuid))}"]`);
    let restore = () => {};
    if (!target) {
      restore = temporarilyRevealAllNodeTypes(container);
      target = await waitForNode(container, uuid);
    }
    if (!target) {
      restore();
      window.scrollTo(position.x, position.y);
      return null;
    }
    const state = stateFor(container);
    state.pendingNodeUuid = String(uuid);
    state.pendingScroll = position;
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
    restore();
    window.scrollTo(position.x, position.y);
    return target;
  }

  async function openRelatedNode(overlay, container, uuid) {
    const position = { x: window.scrollX, y: window.scrollY };
    let target = container.querySelector(`[data-viswiz-node-uuid="${CSS.escape(String(uuid))}"]`);
    let restore = () => {};
    if (!target) {
      restore = temporarilyRevealAllNodeTypes(container);
      target = await waitForNode(container, uuid);
    }
    if (!target) {
      restore();
      window.scrollTo(position.x, position.y);
      return;
    }
    const existing = new Set(document.querySelectorAll('.viswiz-modal-overlay'));
    const state = stateFor(container);
    state.pendingNodeUuid = String(uuid);
    state.pendingScroll = position;
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
    const nextOverlay = await waitForNewNodeModal(existing, overlay);
    if (nextOverlay) {
      nextOverlay.dataset.viswizNodeUuid = String(uuid);
      $('.viswiz-modal-close', overlay)?.click();
    }
    restore();
    window.scrollTo(position.x, position.y);
  }

  function replaceRelatedSection(overlay, container, spec, node) {
    const modal = $('.viswiz-node-modal', overlay);
    if (!modal) return;
    const relations = Array.isArray(spec?.data?.relations) ? spec.data.relations : [];
    const nodes = Array.isArray(spec?.data?.nodes) ? spec.data.nodes : [];
    const nodeMap = new Map(nodes.map((item) => [String(item.uuid), item]));
    const related = relations.filter((relation) => (
      String(relation.from_node_uuid) === String(node.uuid)
      || String(relation.to_node_uuid) === String(node.uuid)
    ));
    let list = $('.viswiz-related-list', modal);
    let heading = list?.previousElementSibling?.matches?.('h4') ? list.previousElementSibling : null;
    if (!related.length) {
      heading?.remove();
      list?.remove();
      return;
    }
    if (!list) {
      heading = document.createElement('h4');
      list = document.createElement('ul');
      list.className = 'viswiz-related-list';
      modal.append(heading, list);
    } else {
      list.replaceChildren();
    }
    if (heading) heading.textContent = spec.settings?.node_modal_related_heading || __('Related nodes', 'viswiz');
    related.forEach((relation) => {
      const outgoing = String(relation.from_node_uuid) === String(node.uuid);
      const otherUuid = outgoing ? relation.to_node_uuid : relation.from_node_uuid;
      const other = nodeMap.get(String(otherUuid));
      if (!other) return;
      const relationLabel = outgoing
        ? (relation.label || relation.relation_type || spec.settings?.node_modal_relation_fallback || __('Relation', 'viswiz'))
        : (relation.inverse_label || relation.label || relation.relation_type || spec.settings?.node_modal_relation_fallback || __('Relation', 'viswiz'));
      const item = document.createElement('li');
      const relationText = document.createElement('span');
      relationText.className = 'viswiz-related-relation';
      relationText.textContent = `${relationLabel}: `;
      const link = document.createElement('button');
      link.type = 'button';
      link.className = 'viswiz-related-node-link';
      link.textContent = nodeLabel(other);
      link.dataset.viswizRelatedNodeUuid = String(otherUuid);
      link.addEventListener('click', () => openRelatedNode(overlay, container, otherUuid));
      item.append(relationText, link);
      list.appendChild(item);
    });
  }

  function addPropertyActions(overlay, container, spec, node) {
    const modal = $('.viswiz-node-modal', overlay);
    if (!modal || modal.querySelector('.viswiz-node-properties')) return;
    if (!node.node_type && !node.node_subtype) return;
    const row = document.createElement('div');
    row.className = 'viswiz-node-properties';
    [
      ['node_type', node.node_type],
      ['node_subtype', node.node_subtype],
    ].forEach(([kind, value]) => {
      if (!value) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'viswiz-node-property-link';
      button.textContent = `${kind === 'node_subtype' ? __('Property', 'viswiz') : __('Type', 'viswiz')}: ${labelize(value)}`;
      button.addEventListener('click', () => showPropertyView(container, spec, kind, value, button));
      row.appendChild(button);
    });
    const typeLine = $('.viswiz-node-type', modal);
    if (typeLine) typeLine.after(row);
    else modal.appendChild(row);
  }

  function addFocusAction(overlay, container, node) {
    const modal = $('.viswiz-node-modal', overlay);
    if (!modal || modal.querySelector('.viswiz-focus-connections')) return;
    const row = document.createElement('div');
    row.className = 'viswiz-node-focus-actions';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'viswiz-focus-connections';
    button.textContent = __('Focus on connections', 'viswiz');
    button.addEventListener('click', () => {
      focusConnections(container, node.uuid, 1);
      $('.viswiz-modal-close', modal)?.click();
    });
    row.appendChild(button);
    const related = $('.viswiz-related-list', modal);
    const heading = related?.previousElementSibling?.matches?.('h4') ? related.previousElementSibling : null;
    if (heading) modal.insertBefore(row, heading);
    else modal.appendChild(row);
  }

  function portalModal(overlay) {
    if (!overlay || overlay.classList.contains('viswiz-property-overlay') || overlay.dataset.viswizPortaled === '1') return;
    const owner = overlay.closest('.viswiz-visualization');
    if (!owner) return;
    const state = stateFor(owner);
    overlay.__viswizOwner = owner;
    if (state.pendingNodeUuid) overlay.dataset.viswizNodeUuid = state.pendingNodeUuid;
    const position = state.pendingScroll || { x: window.scrollX, y: window.scrollY };
    const fullscreen = document.fullscreenElement;
    const target = fullscreen && (fullscreen === owner || fullscreen.contains(owner)) ? fullscreen : document.body;
    overlay.dataset.viswizPortaled = '1';
    if (overlay.parentNode !== target) target.appendChild(overlay);
    const close = $('.viswiz-modal-close', overlay);
    try { close?.focus({ preventScroll: true }); } catch (_) { close?.focus(); }
    if (target === document.body) window.scrollTo(position.x, position.y);
  }

  async function enhanceModal(overlay) {
    if (!overlay || enhancedModals.has(overlay) || overlay.classList.contains('viswiz-property-overlay')) return;
    const container = overlay.__viswizOwner || overlay.closest('.viswiz-visualization');
    if (!container) return;
    const spec = await getSpec(container);
    if (!isGraphSpec(spec)) return;
    const state = stateFor(container);
    const title = $('.viswiz-node-modal h3', overlay)?.textContent?.trim() || '';
    const uuid = overlay.dataset.viswizNodeUuid || state.pendingNodeUuid || '';
    const node = findNode(spec, uuid, title);
    if (!node) return;
    overlay.dataset.viswizNodeUuid = String(node.uuid);
    enhancedModals.add(overlay);
    replaceRelatedSection(overlay, container, spec, node);
    addPropertyActions(overlay, container, spec, node);
    addFocusAction(overlay, container, node);
    state.pendingNodeUuid = '';
  }

  function enhanceToolbar(container) {
    const toolbar = $('.viswiz-graph-toolbar', container);
    if (!toolbar) return;
    const state = stateFor(container);
    state.toolbar = toolbar;
    ensureSearchControl(container, toolbar);
    ensureClearFiltersControl(container, toolbar);
    ensureFilterGroup(toolbar);
    ensureSelectedFacetHost(toolbar);
    ensureGraphHeader(container, toolbar);
    bindContainerInteractions(container);
  }

  async function enhanceContainer(container) {
    const spec = await getSpec(container);
    if (!isGraphSpec(spec)) return;
    stateFor(container).spec = spec;
    styleNodes(container, spec);
    enhanceToolbar(container);
    ensureFocusBar(container);
    applyState(container);
    container.querySelectorAll('.viswiz-modal-overlay').forEach((overlay) => enhanceModal(overlay));
  }

  function queue(container) {
    if (!container || queued.has(container)) return;
    queued.add(container);
    Promise.resolve().then(() => {
      queued.delete(container);
      enhanceContainer(container);
    });
  }

  function structuralNode(node) {
    if (!(node instanceof Element)) return false;
    const selector = '.viswiz-visualization,.viswiz-graph-svg,.viswiz-graph-node,.viswiz-graph-toolbar,.viswiz-modal-overlay,.viswiz-title,.viswiz-fullscreen';
    return node.matches(selector) || Boolean(node.querySelector?.(selector));
  }

  function processAddedNode(node) {
    if (!(node instanceof Element)) return;
    if (node.matches('[data-viswiz-visualization]')) loadLateVisualization(node);
    node.querySelectorAll?.('[data-viswiz-visualization]').forEach(loadLateVisualization);

    if (node.matches('.viswiz-modal-overlay')) {
      portalModal(node);
      Promise.resolve().then(() => enhanceModal(node));
    }
    node.querySelectorAll?.('.viswiz-modal-overlay').forEach((overlay) => {
      portalModal(overlay);
      Promise.resolve().then(() => enhanceModal(overlay));
    });

    const owner = node.matches('.viswiz-visualization') ? node : node.closest('.viswiz-visualization');
    if (owner && structuralNode(node)) queue(owner);
    node.querySelectorAll?.('.viswiz-visualization').forEach(queue);
  }

  function scanExisting() {
    installRenderBridge();
    document.querySelectorAll('.viswiz-visualization').forEach(queue);
    document.querySelectorAll('.viswiz-modal-overlay').forEach((overlay) => {
      portalModal(overlay);
      enhanceModal(overlay);
    });
  }

  function start() {
    scanExisting();
    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach(processAddedNode);
        if (!(mutation.target instanceof Element)) return;
        const owner = mutation.target.closest('.viswiz-visualization');
        if (owner && [...mutation.addedNodes, ...mutation.removedNodes].some(structuralNode)) queue(owner);
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  window.VisWizGraphRuntime = {
    stateFor,
    setSpec,
    getSpec,
    refresh: queue,
    focusConnections,
    clearFocus,
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
