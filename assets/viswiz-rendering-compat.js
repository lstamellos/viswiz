(() => {
  'use strict';

  const handled = new WeakSet();
  const $ = (selector, root = document) => root.querySelector(selector);

  function loadVisualization(container) {
    if (!container || handled.has(container) || !container.dataset.viswizEndpoint || !window.VisWiz?.load) return;
    const hasRenderedContent = container.children.length > 0 && !container.querySelector('.viswiz-loading');
    if (hasRenderedContent) {
      handled.add(container);
      return;
    }
    handled.add(container);
    window.VisWiz.load(container);
  }

  function scan(root = document) {
    if (root instanceof Element && root.matches('[data-viswiz-visualization]')) loadVisualization(root);
    root.querySelectorAll?.('[data-viswiz-visualization]').forEach(loadVisualization);
  }

  function renderDatasetPreview() {
    const container = $('[data-viswiz-inline-spec]');
    const payloadNode = $('#viswiz-dataset-payload');
    const editor = $('#viswiz-dataset-editor');
    if (!container || !payloadNode || !editor || container.children.length || editor.dataset.schema !== 'graph' || !window.VisWiz?.render) return;

    let payload = {};
    try {
      payload = JSON.parse(payloadNode.textContent || '{}');
    } catch (_) {
      return;
    }

    window.VisWiz.render(container, {
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
    });
  }

  function start() {
    if (!window.VisWiz) return;
    renderDatasetPreview();
    scan(document);

    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
          if (node.nodeType === Node.ELEMENT_NODE) scan(node);
        });
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
