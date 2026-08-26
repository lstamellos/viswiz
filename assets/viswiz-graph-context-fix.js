(() => {
  'use strict';

  const svgNS = 'http://www.w3.org/2000/svg';
  const specCache = new WeakMap();
  const enhancedModals = new WeakSet();
  const $ = (selector, root = document) => root.querySelector(selector);

  function safeId(value) {
    return String(value || '').replace(/[^a-zA-Z0-9_-]/g, '') || Math.random().toString(36).slice(2);
  }

  function getSpec(container) {
    if (!container) return Promise.resolve(null);
    if (specCache.has(container)) return specCache.get(container);

    let promise;
    if (container.matches('[data-viswiz-inline-spec]')) {
      const payload = document.querySelector('#viswiz-dataset-payload');
      const editor = document.querySelector('#viswiz-dataset-editor');
      try {
        promise = Promise.resolve({
          id: `dataset-${Number(editor?.dataset?.datasetId || 0)}`,
          schema: editor?.dataset?.schema || 'graph',
          settings: { show_node_images: true, show_type_badges: true },
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

  function ensureDefs(svg) {
    if (!svg) return null;
    let defs = svg.querySelector(':scope > defs');
    if (!defs) {
      defs = document.createElementNS(svgNS, 'defs');
      svg.insertBefore(defs, svg.firstChild);
    }
    return defs;
  }

  function fixCardClip(group) {
    if (!group || group.dataset.viswizRoundedClipFixed === '1') return;
    const panel = group.querySelector('.viswiz-node-card-title-panel');
    if (!panel) return;

    const background = [...group.children].find((child) => (
      child.tagName?.toLowerCase() === 'rect'
      && !child.classList.contains('viswiz-node-card-title-panel')
      && !child.classList.contains('viswiz-node-card-shade')
    ));
    if (!background) return;

    const existingClip = panel.getAttribute('clip-path');
    if (existingClip) {
      group.dataset.viswizRoundedClipFixed = '1';
      return;
    }

    const svg = group.ownerSVGElement;
    const defs = ensureDefs(svg);
    if (!defs) return;

    const uuid = group.getAttribute('data-viswiz-node-uuid') || Math.random().toString(36).slice(2);
    const clipId = `viswiz-card-clip-${safeId(uuid)}-${Math.random().toString(36).slice(2, 8)}`;
    const clip = document.createElementNS(svgNS, 'clipPath');
    clip.setAttribute('id', clipId);
    const rect = document.createElementNS(svgNS, 'rect');
    ['x', 'y', 'width', 'height', 'rx'].forEach((attribute) => {
      const value = background.getAttribute(attribute);
      if (value !== null) rect.setAttribute(attribute, value);
    });
    clip.appendChild(rect);
    defs.appendChild(clip);

    const clipValue = `url(#${clipId})`;
    panel.setAttribute('clip-path', clipValue);
    const shade = group.querySelector('.viswiz-node-card-shade');
    if (shade && !shade.getAttribute('clip-path')) shade.setAttribute('clip-path', clipValue);
    group.dataset.viswizRoundedClipFixed = '1';
  }

  function fixCards(root = document) {
    if (root instanceof Element) {
      if (root.matches('.viswiz-graph-node')) fixCardClip(root);
      const parentGroup = root.closest('.viswiz-graph-node');
      if (parentGroup) fixCardClip(parentGroup);
    }
    root.querySelectorAll?.('.viswiz-graph-node').forEach(fixCardClip);
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

  function captureVisibilityFilters(container) {
    const toolbar = $('.viswiz-graph-toolbar', container);
    const search = toolbar?.querySelector('input[type="search"]') || null;
    const selects = toolbar ? [...toolbar.querySelectorAll('select:not(.viswiz-property-filter-mode)')] : [];
    const nodeType = selects[0] || null;
    return {
      search,
      searchValue: search?.value || '',
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

  function findNewNodeModal(existing, currentOverlay) {
    return [...document.querySelectorAll('.viswiz-modal-overlay')]
      .find((candidate) => candidate !== currentOverlay && !existing.has(candidate) && !candidate.classList.contains('viswiz-property-overlay')) || null;
  }

  async function openRelatedNode(overlay, container, uuid) {
    const position = { x: window.scrollX, y: window.scrollY };
    const selector = `[data-viswiz-node-uuid="${CSS.escape(String(uuid))}"]`;
    let target = container.querySelector(selector);
    let restoreFilters = () => {};

    if (!target) {
      restoreFilters = temporarilyRevealAllNodeTypes(container);
      target = await waitForNode(container, uuid);
    }

    if (!target) {
      restoreFilters();
      window.scrollTo(position.x, position.y);
      return;
    }

    const existingOverlays = new Set(document.querySelectorAll('.viswiz-modal-overlay'));
    container.__viswizOpeningNodeUuid = String(uuid);
    container.__viswizOpeningScroll = position;
    target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));

    const nextOverlay = findNewNodeModal(existingOverlays, overlay);
    if (!nextOverlay) {
      restoreFilters();
      window.scrollTo(position.x, position.y);
      return;
    }

    nextOverlay.dataset.viswizNodeUuid = String(uuid);
    container.__viswizOpeningNodeUuid = '';
    const close = $('.viswiz-modal-close', overlay);
    close?.click();
    restoreFilters();
    window.scrollTo(position.x, position.y);
  }

  function addRelatedSection(overlay, container, spec, node) {
    const relations = Array.isArray(spec?.data?.relations) ? spec.data.relations : [];
    const nodes = Array.isArray(spec?.data?.nodes) ? spec.data.nodes : [];
    const nodeMap = new Map(nodes.map((item) => [String(item.uuid), item]));
    const related = relations.filter((relation) => (
      String(relation.from_node_uuid) === String(node.uuid)
      || String(relation.to_node_uuid) === String(node.uuid)
    ));
    if (!related.length) return;

    const modal = $('.viswiz-node-modal', overlay);
    if (!modal) return;

    let list = $('.viswiz-related-list', modal);
    let heading = list?.previousElementSibling?.matches?.('h4') ? list.previousElementSibling : null;
    const isNewSection = !list;
    if (!list) {
      heading = document.createElement('h4');
      list = document.createElement('ul');
      list.className = 'viswiz-related-list';
    } else {
      list.replaceChildren();
    }
    if (heading) heading.textContent = spec.settings?.node_modal_related_heading || 'Related nodes';

    related.forEach((relation) => {
      const outgoing = String(relation.from_node_uuid) === String(node.uuid);
      const otherUuid = outgoing ? relation.to_node_uuid : relation.from_node_uuid;
      const other = nodeMap.get(String(otherUuid));
      if (!other) return;

      const relationLabel = outgoing
        ? (relation.label || relation.relation_type || spec.settings?.node_modal_relation_fallback || 'Relation')
        : (relation.inverse_label || relation.label || relation.relation_type || spec.settings?.node_modal_relation_fallback || 'Relation');

      const item = document.createElement('li');
      const relationText = document.createElement('span');
      relationText.className = 'viswiz-related-relation';
      relationText.textContent = `${relationLabel}: `;
      const link = document.createElement('button');
      link.type = 'button';
      link.className = 'viswiz-related-node-link';
      link.textContent = other.title || other.label || other.slug || 'Node';
      link.setAttribute('data-viswiz-related-node-uuid', String(otherUuid));
      link.addEventListener('click', () => openRelatedNode(overlay, container, otherUuid));
      item.append(relationText, link);
      list.appendChild(item);
    });

    if (isNewSection && list.childElementCount) modal.append(heading, list);
  }

  async function enhanceModal(overlay) {
    if (!overlay || enhancedModals.has(overlay) || overlay.classList.contains('viswiz-property-overlay')) return;
    const container = overlay.__viswizOwner || overlay.closest('.viswiz-visualization');
    if (!container) return;
    enhancedModals.add(overlay);

    const spec = await getSpec(container);
    if (!spec || spec.schema !== 'graph') return;
    const title = $('.viswiz-node-modal h3', overlay)?.textContent?.trim() || '';
    const uuid = overlay.dataset.viswizNodeUuid || container.__viswizOpeningNodeUuid || '';
    const node = findNode(spec, uuid, title);
    if (!node) return;
    addRelatedSection(overlay, container, spec, node);
  }

  function processAdded(node) {
    if (!(node instanceof Element)) return;
    fixCards(node);
    if (node.matches('.viswiz-modal-overlay')) Promise.resolve().then(() => enhanceModal(node));
    node.querySelectorAll?.('.viswiz-modal-overlay').forEach((overlay) => Promise.resolve().then(() => enhanceModal(overlay)));
  }

  function start() {
    fixCards(document);
    document.querySelectorAll('.viswiz-modal-overlay').forEach((overlay) => enhanceModal(overlay));
    if (!('MutationObserver' in window)) return;

    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => mutation.addedNodes.forEach(processAdded));
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
