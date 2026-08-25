(() => {
  'use strict';

  const handled = new WeakSet();
  const specCache = new WeakMap();
  const enhancementQueued = new WeakSet();
  const $ = (selector, root = document) => root.querySelector(selector);
  const svgNS = 'http://www.w3.org/2000/svg';

  function loadVisualization(container) {
    if (!container || handled.has(container) || !container.dataset.viswizEndpoint || !window.VisWiz?.load) return;
    const hasRenderedContent = container.children.length > 0 && !container.querySelector('.viswiz-loading');
    if (hasRenderedContent) {
      handled.add(container);
      queueEnhancement(container);
      return;
    }
    handled.add(container);
    window.VisWiz.load(container);
    queueEnhancement(container);
  }

  function datasetPreviewSpec() {
    const payloadNode = $('#viswiz-dataset-payload');
    const editor = $('#viswiz-dataset-editor');
    if (!payloadNode || !editor || editor.dataset.schema !== 'graph') return null;

    let payload = {};
    try {
      payload = JSON.parse(payloadNode.textContent || '{}');
    } catch (_) {
      return null;
    }

    return {
      id: `dataset-${Number(editor.dataset.datasetId || 0)}`,
      title: '',
      renderer: 'graph',
      schema: 'graph',
      source_type: 'dataset',
      settings: {
        primary_color: '#2563eb',
        secondary_color: '#64748b',
        text_color: '#111827',
        background_color: '#ffffff',
        show_graph_toolbar: true,
        show_graph_search: true,
        show_graph_filters: true,
        show_graph_zoom: true,
        show_relation_labels: true,
        show_node_images: true,
        show_type_badges: true,
        full_screen: false,
      },
      data: payload,
      meta: {},
    };
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
          if (!response.ok || body?.code) return null;
          return body;
        })
        .catch(() => null);
    } else {
      promise = Promise.resolve(null);
    }
    specCache.set(container, promise);
    return promise;
  }

  function scan(root = document) {
    if (root instanceof Element && root.matches('[data-viswiz-visualization]')) loadVisualization(root);
    root.querySelectorAll?.('[data-viswiz-visualization]').forEach(loadVisualization);

    if (root instanceof Element) {
      const owner = root.matches('.viswiz-visualization') ? root : root.closest('.viswiz-visualization');
      if (owner) queueEnhancement(owner);
    }
  }

  function renderDatasetPreview() {
    const container = $('[data-viswiz-inline-spec]');
    const spec = datasetPreviewSpec();
    if (!container || !spec || container.children.length || !window.VisWiz?.render) return;

    specCache.set(container, Promise.resolve(spec));
    window.VisWiz.render(container, spec);
    queueEnhancement(container);
  }

  function currentVisibleNodes(container, spec) {
    const allNodes = Array.isArray(spec?.data?.nodes) ? spec.data.nodes : [];
    const toolbar = $('.viswiz-graph-toolbar', container);
    const query = (toolbar?.querySelector('input[type="search"]')?.value || '').trim().toLowerCase();
    const selects = toolbar ? [...toolbar.querySelectorAll('select')] : [];
    const nodeType = selects[0]?.value || '';

    return allNodes.filter((node) => {
      if (nodeType && node.node_type !== nodeType) return false;
      if (!query) return true;
      return `${node.title || ''} ${node.label || ''} ${node.slug || ''} ${node.node_type || ''} ${node.node_subtype || ''}`.toLowerCase().includes(query);
    });
  }

  function primaryNodeImage(node) {
    const gallery = Array.isArray(node?.image_gallery) ? node.image_gallery.filter((image) => image?.url) : [];
    return gallery.find((image) => image.featured) || gallery[0] || null;
  }

  function svgEl(tag, attrs = {}) {
    const node = document.createElementNS(svgNS, tag);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
    return node;
  }

  function enhanceGraph(container, spec) {
    if (!container || !spec || spec.schema !== 'graph') return;
    const groups = [...container.querySelectorAll('.viswiz-graph-node')];
    if (!groups.length) return;

    const nodes = currentVisibleNodes(container, spec);
    groups.forEach((group, index) => {
      const node = nodes[index];
      if (!node) return;
      group.setAttribute('data-viswiz-node-uuid', String(node.uuid || ''));
      if (group.dataset.viswizMediaEnhanced === '1') return;
      group.dataset.viswizMediaEnhanced = '1';

      if (spec.settings?.show_node_images === false) return;
      const image = primaryNodeImage(node);
      if (!image) return;

      const background = group.querySelector('rect');
      if (!background) return;
      background.setAttribute('x', '-83');
      background.setAttribute('width', '166');

      const frame = svgEl('rect', {
        x: -76,
        y: -25,
        width: 38,
        height: 38,
        rx: 8,
        class: 'viswiz-graph-node-image-frame',
      });
      const picture = svgEl('image', {
        x: -74,
        y: -23,
        width: 34,
        height: 34,
        preserveAspectRatio: 'xMidYMid slice',
        href: image.thumb || image.url,
        class: 'viswiz-graph-node-image',
      });
      picture.setAttributeNS('http://www.w3.org/1999/xlink', 'href', image.thumb || image.url);
      background.after(frame, picture);

      const title = group.querySelector('.viswiz-graph-node-title');
      const type = group.querySelector('.viswiz-graph-node-type-label');
      if (title) title.setAttribute('x', '16');
      if (type) type.setAttribute('x', '16');
    });
  }

  function queueEnhancement(container) {
    if (!container || enhancementQueued.has(container)) return;
    enhancementQueued.add(container);
    Promise.resolve().then(async () => {
      enhancementQueued.delete(container);
      const spec = await getSpec(container);
      enhanceGraph(container, spec);
    });
  }

  function rememberNodeActivation(event) {
    if (event.type === 'keydown' && !['Enter', ' '].includes(event.key)) return;
    const group = event.target?.closest?.('.viswiz-graph-node');
    if (!group) return;
    const container = group.closest('.viswiz-visualization');
    if (!container) return;
    container.__viswizOpeningNodeUuid = group.getAttribute('data-viswiz-node-uuid') || '';
    container.__viswizOpeningScroll = { x: window.scrollX, y: window.scrollY };
  }

  function preserveScrollAfterDismiss(event) {
    const overlay = event.target?.closest?.('.viswiz-modal-overlay');
    if (!overlay) return;
    const isDismissClick = event.type === 'click' && (event.target === overlay || event.target.closest?.('.viswiz-modal-close'));
    const isDismissKey = event.type === 'keydown' && event.key === 'Escape';
    if (!isDismissClick && !isDismissKey) return;
    const position = { x: window.scrollX, y: window.scrollY };
    Promise.resolve().then(() => window.scrollTo(position.x, position.y));
  }

  function findNode(spec, uuid) {
    return (Array.isArray(spec?.data?.nodes) ? spec.data.nodes : []).find((node) => String(node.uuid) === String(uuid));
  }

  function ensureNodeVisible(container, spec, uuid) {
    enhanceGraph(container, spec);
    let target = container.querySelector(`[data-viswiz-node-uuid="${String(uuid)}"]`);
    if (target) return target;

    const toolbar = $('.viswiz-graph-toolbar', container);
    const search = toolbar?.querySelector('input[type="search"]');
    const nodeType = toolbar?.querySelector('select');
    if (search?.value) {
      search.value = '';
      search.dispatchEvent(new Event('input', { bubbles: true }));
    }
    if (nodeType?.value) {
      nodeType.value = '';
      nodeType.dispatchEvent(new Event('change', { bubbles: true }));
    }

    enhanceGraph(container, spec);
    target = container.querySelector(`[data-viswiz-node-uuid="${String(uuid)}"]`);
    return target;
  }

  function openRelatedNode(overlay, container, spec, uuid) {
    const targetNode = findNode(spec, uuid);
    if (!targetNode) return;
    const position = { x: window.scrollX, y: window.scrollY };
    const target = ensureNodeVisible(container, spec, uuid);
    const close = $('.viswiz-modal-close', overlay);
    if (close) close.click();
    if (!target) return;

    Promise.resolve().then(() => {
      window.scrollTo(position.x, position.y);
      container.__viswizOpeningNodeUuid = String(uuid);
      container.__viswizOpeningScroll = position;
      target.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, view: window }));
    });
  }

  async function enhanceModal(overlay) {
    const container = overlay.__viswizOwner;
    if (!container) return;
    const spec = await getSpec(container);
    if (!spec || spec.schema !== 'graph') return;

    enhanceGraph(container, spec);
    let uuid = overlay.dataset.viswizNodeUuid || container.__viswizOpeningNodeUuid || '';
    let node = findNode(spec, uuid);
    if (!node) {
      const title = $('.viswiz-node-modal h3', overlay)?.textContent?.trim() || '';
      node = (spec.data?.nodes || []).find((candidate) => [candidate.title, candidate.label, candidate.slug].includes(title));
      uuid = node?.uuid || '';
    }
    if (!node || !uuid) return;
    overlay.dataset.viswizNodeUuid = String(uuid);

    const list = $('.viswiz-related-list', overlay);
    if (!list) return;
    const relations = Array.isArray(spec.data?.relations) ? spec.data.relations : [];
    const nodeMap = new Map((spec.data?.nodes || []).map((item) => [String(item.uuid), item]));
    const related = relations.filter((relation) => String(relation.from_node_uuid) === String(uuid) || String(relation.to_node_uuid) === String(uuid));
    if (!related.length) return;

    list.replaceChildren();
    related.forEach((relation) => {
      const outgoing = String(relation.from_node_uuid) === String(uuid);
      const otherUuid = outgoing ? relation.to_node_uuid : relation.from_node_uuid;
      const other = nodeMap.get(String(otherUuid));
      if (!other) return;
      const relationLabel = outgoing
        ? (relation.label || relation.relation_type || 'Relation')
        : (relation.inverse_label || relation.label || relation.relation_type || 'Relation');

      const item = document.createElement('li');
      const relationText = document.createElement('span');
      relationText.className = 'viswiz-related-relation';
      relationText.textContent = `${relationLabel}: `;
      const link = document.createElement('button');
      link.type = 'button';
      link.className = 'viswiz-related-node-link';
      link.textContent = other.title || other.label || other.slug || 'Node';
      link.setAttribute('data-viswiz-related-node-uuid', String(otherUuid));
      link.addEventListener('click', () => openRelatedNode(overlay, container, spec, otherUuid));
      item.append(relationText, link);
      list.appendChild(item);
    });
  }

  function portalModal(overlay) {
    if (!overlay || overlay.dataset.viswizPortaled === '1') return;
    const owner = overlay.closest('.viswiz-visualization');
    if (!owner) return;

    overlay.__viswizOwner = owner;
    if (owner.__viswizOpeningNodeUuid) overlay.dataset.viswizNodeUuid = owner.__viswizOpeningNodeUuid;
    const position = owner.__viswizOpeningScroll || { x: window.scrollX, y: window.scrollY };
    const fullscreen = document.fullscreenElement;
    const target = fullscreen && (fullscreen === owner || fullscreen.contains(owner)) ? fullscreen : document.body;

    overlay.dataset.viswizPortaled = '1';
    if (overlay.parentNode !== target) target.appendChild(overlay);

    const close = $('.viswiz-modal-close', overlay);
    try {
      close?.focus({ preventScroll: true });
    } catch (_) {
      close?.focus();
    }
    if (target === document.body) window.scrollTo(position.x, position.y);
    enhanceModal(overlay);
  }

  function processAddedNode(node) {
    if (!(node instanceof Element)) return;
    scan(node);
    if (node.matches('.viswiz-modal-overlay')) portalModal(node);
    node.querySelectorAll?.('.viswiz-modal-overlay').forEach(portalModal);
    const owner = node.matches('.viswiz-visualization') ? node : node.closest('.viswiz-visualization');
    if (owner) queueEnhancement(owner);
  }

  function start() {
    if (!window.VisWiz) return;
    renderDatasetPreview();
    scan(document);
    document.querySelectorAll('.viswiz-modal-overlay').forEach(portalModal);

    document.addEventListener('click', rememberNodeActivation, true);
    document.addEventListener('keydown', rememberNodeActivation, true);
    document.addEventListener('click', preserveScrollAfterDismiss, true);
    document.addEventListener('keydown', preserveScrollAfterDismiss, true);

    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        const owner = mutation.target instanceof Element ? mutation.target.closest('.viswiz-visualization') : null;
        if (owner) queueEnhancement(owner);
        mutation.addedNodes.forEach(processAddedNode);
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();
