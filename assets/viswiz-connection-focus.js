(() => {
  'use strict';

  const greek = (document.documentElement.lang || '').toLowerCase().startsWith('el');
  const labels = greek ? {
    focusConnections: 'Εστίαση στις συνδέσεις',
    connectionFocus: 'Συνδέσεις',
    clearFocus: 'Καθαρισμός εστίασης',
    oneHop: '1 hop',
    twoHops: '2 hops',
    node: 'Node',
  } : {
    focusConnections: 'Focus on connections',
    connectionFocus: 'Connections',
    clearFocus: 'Clear focus',
    oneHop: '1 hop',
    twoHops: '2 hops',
    node: 'Node',
  };

  const stateMap = new WeakMap();
  const specCache = new WeakMap();
  const enhancedModals = new WeakSet();
  const queued = new WeakSet();

  function stateFor(container) {
    if (!stateMap.has(container)) stateMap.set(container, { uuid: '', hops: 1, spec: null });
    return stateMap.get(container);
  }

  function datasetPreviewSpec() {
    const payload = document.querySelector('#viswiz-dataset-payload');
    const editor = document.querySelector('#viswiz-dataset-editor');
    if (!payload || !editor || editor.dataset.schema !== 'graph') return null;
    try {
      return {
        id: `dataset-${Number(editor.dataset.datasetId || 0)}`,
        schema: 'graph',
        data: JSON.parse(payload.textContent || '{}'),
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
    return node?.title || node?.label || node?.slug || labels.node;
  }

  function nativeFilterSelects(container) {
    const toolbar = container.querySelector('.viswiz-graph-toolbar');
    return toolbar ? [...toolbar.querySelectorAll('select:not(.viswiz-property-filter-mode)')] : [];
  }

  function currentRelationType(container) {
    return nativeFilterSelects(container)[1]?.value || '';
  }

  function currentVisibleRelations(container, spec) {
    const toolbar = container.querySelector('.viswiz-graph-toolbar');
    const query = (toolbar?.querySelector('input[type="search"]')?.value || '').trim().toLowerCase();
    const selects = nativeFilterSelects(container);
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

  function clearClasses(container) {
    container.querySelectorAll('.is-viswiz-connection-outside,.is-viswiz-connection-root,.is-viswiz-connection-neighbor').forEach((element) => {
      element.classList.remove('is-viswiz-connection-outside', 'is-viswiz-connection-root', 'is-viswiz-connection-neighbor');
    });
    container.querySelectorAll('.is-viswiz-connection-focus-edge,.is-viswiz-connection-focus-edge-outside').forEach((element) => {
      element.classList.remove('is-viswiz-connection-focus-edge', 'is-viswiz-connection-focus-edge-outside');
    });
  }

  function ensureFocusBar(container) {
    const frame = container.querySelector('.viswiz-graph-frame');
    const toolbar = frame?.querySelector('.viswiz-graph-toolbar');
    if (!frame || !toolbar) return null;
    let bar = frame.querySelector(':scope > .viswiz-connection-focus-bar');
    if (bar) return bar;

    bar = document.createElement('div');
    bar.className = 'viswiz-connection-focus-bar';
    bar.hidden = true;
    bar.setAttribute('aria-live', 'polite');

    const text = document.createElement('div');
    text.className = 'viswiz-connection-focus-label';
    text.append(document.createTextNode(`${labels.connectionFocus}: `));
    const name = document.createElement('strong');
    name.className = 'viswiz-connection-focus-name';
    text.appendChild(name);

    const controls = document.createElement('div');
    controls.className = 'viswiz-connection-focus-controls';
    const one = document.createElement('button');
    one.type = 'button';
    one.className = 'viswiz-connection-hop';
    one.dataset.hops = '1';
    one.textContent = labels.oneHop;
    one.setAttribute('aria-label', labels.oneHop);
    const two = document.createElement('button');
    two.type = 'button';
    two.className = 'viswiz-connection-hop';
    two.dataset.hops = '2';
    two.textContent = labels.twoHops;
    two.setAttribute('aria-label', labels.twoHops);
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.className = 'viswiz-connection-focus-clear';
    clear.textContent = '×';
    clear.title = labels.clearFocus;
    clear.setAttribute('aria-label', labels.clearFocus);

    controls.append(one, two, clear);
    bar.append(text, controls);
    toolbar.after(bar);

    one.addEventListener('click', () => setHops(container, 1));
    two.addEventListener('click', () => setHops(container, 2));
    clear.addEventListener('click', () => clearFocus(container));
    return bar;
  }

  function renderFocusBar(container, node, count) {
    const bar = ensureFocusBar(container);
    if (!bar) return;
    const state = stateFor(container);
    bar.hidden = !state.uuid;
    if (!state.uuid) return;
    const name = bar.querySelector('.viswiz-connection-focus-name');
    if (name) name.textContent = `${nodeLabel(node)} · ${count}`;
    bar.querySelectorAll('.viswiz-connection-hop').forEach((button) => {
      const active = Number(button.dataset.hops) === state.hops;
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function applyFocus(container) {
    const state = stateFor(container);
    const spec = state.spec;
    clearClasses(container);
    if (!spec || !state.uuid) {
      renderFocusBar(container, null, 0);
      container.classList.remove('has-viswiz-connection-focus');
      return;
    }

    const root = findNode(spec, state.uuid);
    if (!root) {
      clearFocus(container);
      return;
    }

    const included = connectionNeighborhood(spec, state.uuid, state.hops, currentRelationType(container));
    container.classList.add('has-viswiz-connection-focus');
    container.querySelectorAll('.viswiz-graph-node').forEach((group) => {
      const uuid = String(group.getAttribute('data-viswiz-node-uuid') || '');
      const inside = included.has(uuid);
      group.classList.toggle('is-viswiz-connection-outside', !inside);
      group.classList.toggle('is-viswiz-connection-root', inside && uuid === String(state.uuid));
      group.classList.toggle('is-viswiz-connection-neighbor', inside && uuid !== String(state.uuid));
    });

    const relations = currentVisibleRelations(container, spec);
    const edges = [...container.querySelectorAll('.viswiz-graph-edge')];
    const edgeLabels = [...container.querySelectorAll('.viswiz-graph-edge-label')];
    relations.forEach((relation, index) => {
      const inside = included.has(String(relation.from_node_uuid)) && included.has(String(relation.to_node_uuid));
      if (edges[index]) edges[index].classList.add(inside ? 'is-viswiz-connection-focus-edge' : 'is-viswiz-connection-focus-edge-outside');
      if (edgeLabels[index]) edgeLabels[index].classList.add(inside ? 'is-viswiz-connection-focus-edge' : 'is-viswiz-connection-focus-edge-outside');
    });

    renderFocusBar(container, root, included.size);
  }

  function setHops(container, hops) {
    const state = stateFor(container);
    state.hops = hops === 2 ? 2 : 1;
    applyFocus(container);
  }

  function focusConnections(container, uuid, hops = 1) {
    const state = stateFor(container);
    state.uuid = String(uuid || '');
    state.hops = hops === 2 ? 2 : 1;
    applyFocus(container);
  }

  function clearFocus(container) {
    const state = stateFor(container);
    state.uuid = '';
    state.hops = 1;
    applyFocus(container);
  }

  function addModalAction(overlay, container, spec, node) {
    const modal = overlay.querySelector('.viswiz-node-modal');
    if (!modal || modal.querySelector('.viswiz-focus-connections')) return;
    const row = document.createElement('div');
    row.className = 'viswiz-node-focus-actions';
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'viswiz-focus-connections';
    button.textContent = labels.focusConnections;
    button.addEventListener('click', () => {
      focusConnections(container, node.uuid, 1);
      modal.querySelector('.viswiz-modal-close')?.click();
    });
    row.appendChild(button);
    const related = modal.querySelector('.viswiz-related-list');
    const heading = related?.previousElementSibling?.matches?.('h4') ? related.previousElementSibling : null;
    if (heading) modal.insertBefore(row, heading);
    else modal.appendChild(row);
  }

  async function enhanceModal(overlay) {
    if (!overlay || enhancedModals.has(overlay) || overlay.classList.contains('viswiz-property-overlay')) return;
    const container = overlay.__viswizOwner || overlay.closest('.viswiz-visualization');
    if (!container) return;
    const spec = await getSpec(container);
    if (!spec || spec.schema !== 'graph') return;
    const title = overlay.querySelector('.viswiz-node-modal h3')?.textContent?.trim() || '';
    const uuid = overlay.dataset.viswizNodeUuid || container.__viswizOpeningNodeUuid || '';
    const node = findNode(spec, uuid, title);
    if (!node) return;
    enhancedModals.add(overlay);
    addModalAction(overlay, container, spec, node);
  }

  async function enhanceContainer(container) {
    const state = stateFor(container);
    if (!state.spec) state.spec = await getSpec(container);
    if (!state.spec || state.spec.schema !== 'graph') return;
    ensureFocusBar(container);
    container.querySelectorAll('.viswiz-modal-overlay').forEach((overlay) => enhanceModal(overlay));
    applyFocus(container);
    if (container.dataset.viswizConnectionFocusBound === '1') return;
    container.dataset.viswizConnectionFocusBound = '1';
    const toolbar = container.querySelector('.viswiz-graph-toolbar');
    toolbar?.addEventListener('input', () => queue(container));
    toolbar?.addEventListener('change', () => queue(container));
    container.addEventListener('viswiz:focus-connections', (event) => {
      focusConnections(container, event.detail?.uuid || '', Number(event.detail?.hops || 1));
    });
    container.addEventListener('viswiz:clear-connection-focus', () => clearFocus(container));
  }

  function queue(container) {
    if (!container || queued.has(container)) return;
    queued.add(container);
    Promise.resolve().then(() => {
      queued.delete(container);
      enhanceContainer(container);
    });
  }

  function scan(root = document) {
    if (root instanceof Element && root.matches('.viswiz-visualization')) queue(root);
    root.querySelectorAll?.('.viswiz-visualization').forEach(queue);
    if (root instanceof Element && root.matches('.viswiz-modal-overlay')) enhanceModal(root);
    root.querySelectorAll?.('.viswiz-modal-overlay').forEach(enhanceModal);
  }

  function start() {
    scan(document);
    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
        if (!(node instanceof Element)) return;
        scan(node);
        const container = node.closest('.viswiz-visualization');
        if (container && node.matches('.viswiz-graph-node,.viswiz-graph-edge,.viswiz-graph-edge-label,.viswiz-graph-stage,.viswiz-graph-toolbar')) queue(container);
      }));
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
