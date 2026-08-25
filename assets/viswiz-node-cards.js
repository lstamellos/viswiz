(() => {
  'use strict';

  const svgNS = 'http://www.w3.org/2000/svg';
  const specCache = new WeakMap();
  const queued = new WeakSet();
  const $ = (selector, root = document) => root.querySelector(selector);

  function datasetPreviewSpec() {
    const payloadNode = $('#viswiz-dataset-payload');
    const editor = $('#viswiz-dataset-editor');
    if (!payloadNode || !editor || editor.dataset.schema !== 'graph') return null;

    try {
      return {
        id: `dataset-${Number(editor.dataset.datasetId || 0)}`,
        schema: 'graph',
        settings: {
          show_node_images: true,
          show_type_badges: true,
        },
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

  function visibleNodes(container, spec) {
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

  function primaryImage(node) {
    const gallery = Array.isArray(node?.image_gallery) ? node.image_gallery.filter((image) => image?.url) : [];
    return gallery.find((image) => image.featured) || gallery[0] || null;
  }

  function svgEl(tag, attrs = {}) {
    const element = document.createElementNS(svgNS, tag);
    Object.entries(attrs).forEach(([key, value]) => element.setAttribute(key, String(value)));
    return element;
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

  function truncateTag(value, max = 14) {
    const text = labelize(value);
    return text.length > max ? `${text.slice(0, max - 1)}…` : text;
  }

  function tagWidth(label) {
    return Math.max(32, Math.min(70, 14 + label.length * 5.2));
  }

  function addTag(group, label, x, y, maxChars) {
    if (!label) return 0;
    const textValue = truncateTag(label, maxChars);
    const width = tagWidth(textValue);
    const tag = svgEl('g', { class: 'viswiz-node-card-tag' });
    tag.appendChild(svgEl('rect', {
      x,
      y,
      width,
      height: 17,
      rx: 8.5,
      class: 'viswiz-node-card-tag-bg',
    }));
    const text = svgEl('text', {
      x: x + width / 2,
      y: y + 11.5,
      'text-anchor': 'middle',
      class: 'viswiz-node-card-tag-text',
    });
    text.textContent = textValue;
    tag.appendChild(text);
    group.appendChild(tag);
    return width;
  }

  function styleNode(group, node, spec, index) {
    if (!group || !node || group.dataset.viswizNodeCardStyled === '1') return;
    group.dataset.viswizNodeCardStyled = '1';
    group.classList.add('viswiz-node-card-styled');
    group.setAttribute('data-viswiz-node-uuid', String(node.uuid || ''));

    group.querySelectorAll('.viswiz-graph-node-image-frame,.viswiz-graph-node-image').forEach((element) => element.remove());

    const background = [...group.children].find((child) => child.tagName?.toLowerCase() === 'rect' && !child.classList.contains('viswiz-node-card-title-panel'));
    if (!background) return;

    const x = -76;
    const y = -35;
    const width = 152;
    const height = 70;
    const rx = 14;
    background.setAttribute('x', String(x));
    background.setAttribute('y', String(y));
    background.setAttribute('width', String(width));
    background.setAttribute('height', String(height));
    background.setAttribute('rx', String(rx));

    const svg = group.ownerSVGElement;
    const defs = svg?.querySelector('defs');
    const image = spec.settings?.show_node_images === false ? null : primaryImage(node);
    const suffix = `${safeId(spec.id)}-${safeId(node.uuid || index)}`;
    const clipId = `viswiz-node-clip-${suffix}`;

    if (image && defs) {
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
        class: 'viswiz-node-card-cover',
      });
      picture.setAttributeNS('http://www.w3.org/1999/xlink', 'href', image.url);
      background.after(picture);
    }

    const titlePanel = svgEl('rect', {
      x,
      y: 0,
      width,
      height: 35,
      rx,
      class: 'viswiz-node-card-title-panel',
      'clip-path': image ? `url(#${clipId})` : '',
    });
    const shade = svgEl('rect', {
      x,
      y,
      width,
      height,
      rx,
      class: 'viswiz-node-card-shade',
      'clip-path': image ? `url(#${clipId})` : '',
    });
    const insertionPoint = group.querySelector('.viswiz-node-card-cover') || background;
    insertionPoint.after(shade, titlePanel);

    const title = group.querySelector('.viswiz-graph-node-title');
    if (title) {
      title.setAttribute('x', '0');
      title.setAttribute('y', '20');
      title.setAttribute('text-anchor', 'middle');
    }

    const oldType = group.querySelector('.viswiz-graph-node-type-label');
    if (oldType) oldType.setAttribute('display', 'none');

    if (spec.settings?.show_type_badges !== false && node.node_type) {
      const hasSubtype = Boolean(node.node_subtype);
      const firstLabel = truncateTag(node.node_type, hasSubtype ? 11 : 18);
      const firstWidth = addTag(group, firstLabel, x + 7, y + 7, hasSubtype ? 11 : 18);
      if (hasSubtype) {
        const remaining = Math.max(7, width - firstWidth - 24);
        const subtypeMax = Math.max(6, Math.floor((remaining - 14) / 5.2));
        addTag(group, node.node_subtype, x + 13 + firstWidth, y + 7, subtypeMax);
      }
    }
  }

  async function enhance(container) {
    const spec = await getSpec(container);
    if (!spec || spec.schema !== 'graph') return;
    const groups = [...container.querySelectorAll('.viswiz-graph-node')];
    if (!groups.length) return;
    const nodes = visibleNodes(container, spec);
    groups.forEach((group, index) => styleNode(group, nodes[index], spec, index));
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

  function start() {
    scan(document);
    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => {
        const owner = mutation.target instanceof Element ? mutation.target.closest('.viswiz-visualization') : null;
        if (owner) queue(owner);
        mutation.addedNodes.forEach((node) => {
          if (node instanceof Element) scan(node);
        });
      });
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
