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
    const tag = svgEl('g', {
      class: 'viswiz-node-card-tag',
      'pointer-events': 'none',
    });
    tag.appendChild(svgEl('rect', {
      x,
      y,
      width,
      height: 17,
      rx: 8.5,
      fill: 'rgba(15,23,42,.82)',
      stroke: 'rgba(255,255,255,.55)',
      'stroke-width': '.55',
      class: 'viswiz-node-card-tag-bg',
    }));
    const text = svgEl('text', {
      x: x + width / 2,
      y: y + 11.5,
      'text-anchor': 'middle',
      fill: '#ffffff',
      'font-size': '8.5',
      'font-weight': '600',
      'font-family': 'inherit',
      class: 'viswiz-node-card-tag-text',
    });
    text.textContent = textValue;
    tag.appendChild(text);
    group.appendChild(tag);
    return width;
  }

  function enforcePresentation(group) {
    group.querySelectorAll('.viswiz-node-card-cover,.viswiz-node-card-shade,.viswiz-node-card-title-panel,.viswiz-node-card-tag').forEach((element) => {
      element.setAttribute('pointer-events', 'none');
    });
    const cover = group.querySelector('.viswiz-node-card-cover');
    if (cover) cover.setAttribute('preserveAspectRatio', 'xMidYMid slice');
    const shade = group.querySelector('.viswiz-node-card-shade');
    if (shade) shade.setAttribute('fill', 'rgba(0,0,0,.13)');
    const panel = group.querySelector('.viswiz-node-card-title-panel');
    if (panel) panel.setAttribute('fill', 'rgba(0,0,0,.72)');
    group.querySelectorAll('.viswiz-node-card-tag-bg').forEach((tag) => {
      tag.setAttribute('fill', 'rgba(15,23,42,.82)');
      tag.setAttribute('stroke', 'rgba(255,255,255,.55)');
      tag.setAttribute('stroke-width', '.55');
    });
    group.querySelectorAll('.viswiz-node-card-tag-text').forEach((text) => {
      text.setAttribute('fill', '#ffffff');
      text.setAttribute('font-size', '8.5');
      text.setAttribute('font-weight', '600');
    });
  }

  function styleNode(group, node, spec, index) {
    if (!group || !node) return;

    const alreadyStyled = group.dataset.viswizNodeCardStyled === '1';
    group.classList.add('viswiz-node-card-styled');
    group.setAttribute('data-viswiz-node-uuid', String(node.uuid || ''));
    group.querySelectorAll('.viswiz-graph-node-image-frame,.viswiz-graph-node-image').forEach((element) => element.remove());

    const background = [...group.children].find((child) => child.tagName?.toLowerCase() === 'rect' && !child.classList.contains('viswiz-node-card-title-panel') && !child.classList.contains('viswiz-node-card-shade'));
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

    const title = group.querySelector('.viswiz-graph-node-title');
    if (title) {
      title.setAttribute('x', '0');
      title.setAttribute('y', '21');
      title.setAttribute('text-anchor', 'middle');
      title.setAttribute('fill', '#ffffff');
      title.setAttribute('font-weight', '700');
      title.setAttribute('font-size', '11');
      title.setAttribute('pointer-events', 'none');
    }

    const oldType = group.querySelector('.viswiz-graph-node-type-label');
    if (oldType) oldType.setAttribute('display', 'none');

    if (alreadyStyled) {
      enforcePresentation(group);
      return;
    }
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
      fill: 'rgba(0,0,0,.13)',
      'clip-path': clipPath,
      'pointer-events': 'none',
      class: 'viswiz-node-card-shade',
    });
    const titlePanel = svgEl('rect', {
      x,
      y: 2,
      width,
      height: 33,
      fill: 'rgba(0,0,0,.72)',
      'clip-path': clipPath,
      'pointer-events': 'none',
      class: 'viswiz-node-card-title-panel',
    });
    const insertionPoint = group.querySelector('.viswiz-node-card-cover') || background;
    insertionPoint.after(shade, titlePanel);

    if (spec.settings?.show_type_badges !== false && node.node_type) {
      const hasSubtype = Boolean(node.node_subtype);
      const firstWidth = addTag(group, node.node_type, x + 7, y + 7, hasSubtype ? 11 : 18);
      if (hasSubtype) {
        const remaining = Math.max(7, width - firstWidth - 24);
        const subtypeMax = Math.max(6, Math.floor((remaining - 14) / 5.2));
        addTag(group, node.node_subtype, x + 13 + firstWidth, y + 7, subtypeMax);
      }
    }
    enforcePresentation(group);
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
