(() => {
  'use strict';

  const greek = (document.documentElement.lang || '').toLowerCase().startsWith('el');
  const labels = greek ? {
    clearSearch: 'Καθαρισμός αναζήτησης',
    clearAllFilters: 'Καθαρισμός όλων των φίλτρων',
    selectedFilters: 'Επιλεγμένα φίλτρα',
    nodeType: 'Τύπος',
    nodeSubtype: 'Ιδιότητα',
  } : {
    clearSearch: 'Clear search',
    clearAllFilters: 'Clear all filters',
    selectedFilters: 'Selected filters',
    nodeType: 'Type',
    nodeSubtype: 'Property',
  };

  const enhanced = new WeakSet();
  const queued = new WeakSet();
  const facetState = new WeakMap();
  const specCache = new WeakMap();

  function nativeFilterSelects(toolbar) {
    return [...toolbar.querySelectorAll('select:not(.viswiz-property-filter-mode)')];
  }

  function getState(container) {
    if (!facetState.has(container)) facetState.set(container, { selected: new Map(), spec: null });
    return facetState.get(container);
  }

  function facetKey(kind, value) {
    return `${kind}\u0000${value}`;
  }

  function labelize(value) {
    return String(value || '')
      .replace(/[_-]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim()
      .replace(/(^|\s)\S/g, (letter) => letter.toUpperCase());
  }

  function getSpec(container) {
    if (specCache.has(container)) return specCache.get(container);
    let promise;
    if (container.matches('[data-viswiz-inline-spec]')) {
      const payload = document.querySelector('#viswiz-dataset-payload');
      const editor = document.querySelector('#viswiz-dataset-editor');
      try {
        promise = Promise.resolve({
          schema: editor?.dataset?.schema || 'graph',
          data: JSON.parse(payload?.textContent || '{}'),
        });
      } catch (_) {
        promise = Promise.resolve(null);
      }
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

  function lockLegacyFacetUi(toolbar) {
    const mode = toolbar.querySelector('.viswiz-property-filter-mode');
    if (mode) {
      if (mode.value !== 'fade') {
        mode.value = 'fade';
        mode.dispatchEvent(new Event('change', { bubbles: true }));
      }
      mode.hidden = true;
      mode.disabled = true;
      mode.tabIndex = -1;
      mode.setAttribute('aria-hidden', 'true');
    }
    const legacyPill = toolbar.querySelector('.viswiz-property-filter-clear');
    if (legacyPill) {
      legacyPill.hidden = true;
      legacyPill.tabIndex = -1;
      legacyPill.setAttribute('aria-hidden', 'true');
    }
  }

  function ensureSearchControl(toolbar) {
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
      clear.setAttribute('aria-label', labels.clearSearch);
      clear.title = labels.clearSearch;
      group.appendChild(clear);

      clear.addEventListener('click', () => {
        if (!search.value) return;
        search.value = '';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        try { search.focus({ preventScroll: true }); } catch (_) { search.focus(); }
      });
      search.addEventListener('input', () => {
        clear.disabled = !search.value;
      });
    }
    clear.disabled = !search.value;
  }

  function clearSelectedFacets(container) {
    const state = getState(container);
    if (!state.selected.size) return;
    state.selected.clear();
    applyMultiFacet(container);
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
      clear.textContent = labels.clearAllFilters;
      clear.setAttribute('aria-label', labels.clearAllFilters);
      clear.title = labels.clearAllFilters;
      relationSelect.after(clear);

      clear.addEventListener('click', () => {
        nativeFilterSelects(toolbar).forEach((select) => {
          if (!select.value) return;
          select.value = '';
          select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        clearSelectedFacets(container);
        container.dispatchEvent(new CustomEvent('viswiz:clear-property-filter', { bubbles: false }));
      });
    }
  }

  function ensureSelectedFacetHost(toolbar) {
    let host = toolbar.querySelector('.viswiz-selected-facets');
    if (!host) {
      host = document.createElement('div');
      host.className = 'viswiz-selected-facets';
      host.setAttribute('aria-label', labels.selectedFilters);
      const clearAll = toolbar.querySelector('.viswiz-clear-all-filters');
      if (clearAll) clearAll.after(host);
      else toolbar.appendChild(host);
    }
    return host;
  }

  function renderSelectedFacets(container) {
    const toolbar = container.querySelector('.viswiz-graph-toolbar');
    if (!toolbar) return;
    const host = ensureSelectedFacetHost(toolbar);
    const state = getState(container);
    host.replaceChildren();
    host.hidden = !state.selected.size;

    state.selected.forEach((facet, key) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'viswiz-selected-facet';
      const kindLabel = facet.kind === 'node_subtype' ? labels.nodeSubtype : labels.nodeType;
      button.textContent = `× ${kindLabel}: ${labelize(facet.value)}`;
      button.title = `${labels.clearAllFilters}: ${labelize(facet.value)}`;
      button.setAttribute('aria-label', button.title);
      button.addEventListener('click', () => {
        state.selected.delete(key);
        applyMultiFacet(container);
      });
      host.appendChild(button);
    });
  }

  function moveZoomControls(container, toolbar) {
    const zoomButtons = [...toolbar.querySelectorAll('button.viswiz-graph-tool')]
      .filter((button) => ['−', '+', '100%'].includes(button.textContent.trim()));
    if (!zoomButtons.length) return;

    let group = container.querySelector(':scope > .viswiz-view-controls');
    if (!group) {
      group = document.createElement('div');
      group.className = 'viswiz-view-controls';
      container.insertBefore(group, container.firstChild);
    }

    zoomButtons.forEach((button) => group.appendChild(button));
    const fullscreen = container.querySelector(':scope > .viswiz-fullscreen');
    if (fullscreen) group.appendChild(fullscreen);
  }

  function nodeMatchesSelected(group, selected) {
    if (!selected.size) return true;
    return [...group.querySelectorAll('.viswiz-node-card-tag')].some((tag) => (
      selected.has(facetKey(tag.dataset.viswizPropertyKind || '', tag.dataset.viswizPropertyValue || ''))
    ));
  }

  function currentlyVisibleRelations(container, spec) {
    const toolbar = container.querySelector('.viswiz-graph-toolbar');
    if (!toolbar) return [];
    const query = (toolbar.querySelector('input[type="search"]')?.value || '').trim().toLowerCase();
    const selects = nativeFilterSelects(toolbar);
    const nodeType = selects[0]?.value || '';
    const relationType = selects[1]?.value || '';
    const nodes = (Array.isArray(spec?.data?.nodes) ? spec.data.nodes : []).filter((node) => {
      if (nodeType && node.node_type !== nodeType) return false;
      if (!query) return true;
      return `${node.title || ''} ${node.label || ''} ${node.slug || ''} ${node.node_type || ''} ${node.node_subtype || ''}`.toLowerCase().includes(query);
    });
    const ids = new Set(nodes.map((node) => String(node.uuid)));
    return (Array.isArray(spec?.data?.relations) ? spec.data.relations : []).filter((relation) => (
      ids.has(String(relation.from_node_uuid))
      && ids.has(String(relation.to_node_uuid))
      && (!relationType || relation.relation_type === relationType)
    ));
  }

  function applyMultiFacet(container) {
    const state = getState(container);
    const selected = state.selected;
    const matched = new Set();

    container.querySelectorAll('.viswiz-graph-node').forEach((group) => {
      const match = nodeMatchesSelected(group, selected);
      const uuid = String(group.getAttribute('data-viswiz-node-uuid') || '');
      if (match && uuid) matched.add(uuid);
      group.style.display = '';
      group.style.opacity = selected.size && !match ? '0.18' : '';
      group.style.filter = selected.size && !match ? 'grayscale(1)' : '';
      group.classList.toggle('is-viswiz-property-muted', Boolean(selected.size && !match));
      group.classList.remove('is-viswiz-property-hidden');
      group.querySelectorAll('.viswiz-node-card-tag').forEach((tag) => {
        const active = selected.has(facetKey(tag.dataset.viswizPropertyKind || '', tag.dataset.viswizPropertyValue || ''));
        tag.classList.toggle('is-active', active);
        tag.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    });

    const relations = currentlyVisibleRelations(container, state.spec);
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
    const legacyPill = container.querySelector('.viswiz-property-filter-clear');
    if (legacyPill) legacyPill.hidden = true;
  }

  function toggleFacet(container, tag) {
    const kind = tag.dataset.viswizPropertyKind || '';
    const value = tag.dataset.viswizPropertyValue || '';
    if (!kind || !value) return;
    const state = getState(container);
    const key = facetKey(kind, value);
    if (state.selected.has(key)) state.selected.delete(key);
    else state.selected.set(key, { kind, value });
    applyMultiFacet(container);
  }

  function bindMultiFacetEvents(container) {
    if (enhanced.has(container)) return;
    enhanced.add(container);

    container.addEventListener('click', (event) => {
      const tag = event.target.closest?.('.viswiz-node-card-tag');
      if (!tag || !container.contains(tag)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      toggleFacet(container, tag);
    }, true);

    container.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' && event.key !== ' ') return;
      const tag = event.target.closest?.('.viswiz-node-card-tag');
      if (!tag || !container.contains(tag)) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      toggleFacet(container, tag);
    }, true);

    container.addEventListener('viswiz:clear-property-filter', () => {
      const state = getState(container);
      if (!state.selected.size) return;
      state.selected.clear();
      applyMultiFacet(container);
    });
  }

  async function enhance(container) {
    const toolbar = container.querySelector('.viswiz-graph-toolbar');
    if (!toolbar) return;

    const state = getState(container);
    if (!state.spec) state.spec = await getSpec(container);
    lockLegacyFacetUi(toolbar);
    ensureSearchControl(toolbar);
    ensureClearFiltersControl(container, toolbar);
    ensureSelectedFacetHost(toolbar);
    moveZoomControls(container, toolbar);
    bindMultiFacetEvents(container);
    applyMultiFacet(container);
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
    if (root instanceof Element && root.matches('.viswiz-visualization')) queue(root);
    root.querySelectorAll?.('.viswiz-visualization').forEach(queue);
  }

  function processMutation(mutation) {
    mutation.addedNodes.forEach((node) => {
      if (!(node instanceof Element)) return;
      const owner = node.closest('.viswiz-visualization');
      if (owner && (
        node.matches('.viswiz-graph-toolbar,.viswiz-property-filter-mode,.viswiz-graph-node,.viswiz-fullscreen')
        || node.querySelector?.('.viswiz-graph-toolbar,.viswiz-property-filter-mode,.viswiz-graph-node,.viswiz-fullscreen')
      )) queue(owner);
      scan(node);
    });
  }

  function start() {
    scan(document);
    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => mutations.forEach(processMutation));
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
