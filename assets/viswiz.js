(() => {
  'use strict';
  const { __, sprintf } = window.wp.i18n;

  const inflight = new Map();
  const DEFAULT_COLORS = ['#2563eb', '#7c3aed', '#059669', '#ea580c', '#0891b2', '#be123c', '#4f46e5', '#a16207'];
  const svgNS = 'http://www.w3.org/2000/svg';
  function fetchSpec(url) {
    if (inflight.has(url)) return inflight.get(url);
    const request = fetch(url, { credentials: 'same-origin' })
      .then(async (response) => {
        const body = await response.json();
        if (!response.ok || (body && body.code)) throw new Error(body?.message || `HTTP ${response.status}`);
        return body;
      })
      .finally(() => inflight.delete(url));
    inflight.set(url, request);
    return request;
  }

  function el(tag, attrs = {}, text = '') {
    const node = document.createElement(tag);
    Object.entries(attrs).forEach(([key, value]) => {
      if (key === 'class') node.className = value;
      else if (key.startsWith('data-')) node.setAttribute(key, value);
      else if (key in node) node[key] = value;
      else node.setAttribute(key, value);
    });
    if (text !== '') node.textContent = text;
    return node;
  }

  function svgEl(tag, attrs = {}, text = '') {
    const node = document.createElementNS(svgNS, tag);
    Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
    if (text !== '') node.textContent = text;
    return node;
  }

  function color(settings, index = 0) {
    if (index === 0 && settings.primary_color) return settings.primary_color;
    if (index === 1 && settings.secondary_color) return settings.secondary_color;
    return DEFAULT_COLORS[index % DEFAULT_COLORS.length];
  }

  function applyTheme(container, settings) {
    container.style.setProperty('--viswiz-primary', settings.primary_color || '#2563eb');
    container.style.setProperty('--viswiz-secondary', settings.secondary_color || '#64748b');
    container.style.setProperty('--viswiz-text', settings.text_color || '#111827');
    container.style.setProperty('--viswiz-bg', settings.background_color || '#ffffff');
  }

  function formatNumber(value, spec) {
    const number = Number(value || 0);
    if (spec?.meta?.currency) {
      try {
        return new Intl.NumberFormat(document.documentElement.lang || undefined, { style: 'currency', currency: spec.meta.currency }).format(number);
      } catch (_) {}
    }
    return new Intl.NumberFormat(document.documentElement.lang || undefined, { maximumFractionDigits: 2 }).format(number);
  }

  function addHeader(container, spec) {
    const title = spec.settings?.title || spec.title || '';
    if (title) container.appendChild(el('h3', { class: 'viswiz-title' }, title));
  }

  function svgFrame(container, width = 800, height = 440) {
    const svg = svgEl('svg', { class: 'viswiz-svg', viewBox: `0 0 ${width} ${height}`, role: 'img', 'aria-label': __('Visualization', 'viswiz') });
    container.appendChild(svg);
    return svg;
  }

  function rows(spec) {
    return Array.isArray(spec.data?.rows) ? spec.data.rows : [];
  }

  function renderPie(container, spec) {
    const data = rows(spec).filter((row) => Number(row.value) > 0);
    addHeader(container, spec);
    if (!data.length) return empty(container);
    const svg = svgFrame(container, 440, 300);
    const total = data.reduce((sum, row) => sum + Number(row.value || 0), 0);
    let angle = -Math.PI / 2;
    const cx = 150, cy = 150, r = 120;
    data.forEach((row, index) => {
      const span = (Number(row.value || 0) / total) * Math.PI * 2;
      const end = angle + span;
      const large = span > Math.PI ? 1 : 0;
      const x1 = cx + r * Math.cos(angle), y1 = cy + r * Math.sin(angle);
      const x2 = cx + r * Math.cos(end), y2 = cy + r * Math.sin(end);
      const path = span >= Math.PI * 2 - 0.0001
        ? `M ${cx-r} ${cy} A ${r} ${r} 0 1 0 ${cx+r} ${cy} A ${r} ${r} 0 1 0 ${cx-r} ${cy}`
        : `M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z`;
      svg.appendChild(svgEl('path', { d: path, fill: row.color || color(spec.settings, index) }));
      angle = end;
    });
    if (spec.settings?.show_legend !== false) {
      const legend = el('ul', { class: 'viswiz-legend' });
      data.forEach((row, index) => {
        const li = el('li');
        const swatch = el('span', { class: 'viswiz-swatch' });
        swatch.style.background = row.color || color(spec.settings, index);
        li.append(swatch, document.createTextNode(`${row.label || ''}: ${formatNumber(row.value, spec)}`));
        legend.appendChild(li);
      });
      container.appendChild(legend);
    }
  }

  function renderBars(container, spec, vertical = false) {
    const data = rows(spec);
    addHeader(container, spec);
    if (!data.length) return empty(container);
    const width = 800, height = Math.max(320, vertical ? 420 : data.length * 42 + 60);
    const svg = svgFrame(container, width, height);
    const max = Math.max(1, ...data.map((r) => Math.abs(Number(r.value || 0))));
    if (vertical) {
      const chartH = height - 80, chartW = width - 80, barW = Math.max(8, chartW / data.length * 0.65);
      data.forEach((row, index) => {
        const value = Number(row.value || 0), h = Math.abs(value) / max * chartH;
        const x = 50 + (index + 0.5) * (chartW / data.length) - barW / 2;
        const y = 20 + chartH - h;
        svg.appendChild(svgEl('rect', { x, y, width: barW, height: h, rx: 4, fill: row.color || color(spec.settings, index) }));
        const label = svgEl('text', { x: x + barW / 2, y: height - 35, 'text-anchor': 'middle', class: 'viswiz-axis-label' }, String(row.label || ''));
        svg.appendChild(label);
      });
    } else {
      data.forEach((row, index) => {
        const value = Number(row.value || 0), w = Math.abs(value) / max * 520, y = 25 + index * 42;
        svg.appendChild(svgEl('text', { x: 10, y: y + 18, class: 'viswiz-axis-label' }, String(row.label || '')));
        svg.appendChild(svgEl('rect', { x: 210, y, width: w, height: 24, rx: 4, fill: row.color || color(spec.settings, index) }));
        svg.appendChild(svgEl('text', { x: 220 + w, y: y + 18, class: 'viswiz-value-label' }, formatNumber(value, spec)));
      });
    }
  }

  function pointsForLine(spec) {
    return rows(spec).map((row, index) => ({
      row,
      x: row.x_numeric != null ? Number(row.x_numeric) : index,
      y: row.y_value != null ? Number(row.y_value) : Number(row.value || 0),
    })).filter((p) => Number.isFinite(p.x) && Number.isFinite(p.y));
  }

  function renderLine(container, spec, area = false) {
    const data = pointsForLine(spec);
    addHeader(container, spec);
    if (!data.length) return empty(container);
    const width = 800, height = 420, pad = 55;
    const svg = svgFrame(container, width, height);
    const xs = data.map((p) => p.x), ys = data.map((p) => p.y);
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(0, ...ys), maxY = Math.max(1, ...ys);
    const sx = (v) => pad + (v - minX) / (maxX - minX || 1) * (width - pad * 2);
    const sy = (v) => height - pad - (v - minY) / (maxY - minY || 1) * (height - pad * 2);
    const path = data.map((p, i) => `${i ? 'L' : 'M'} ${sx(p.x)} ${sy(p.y)}`).join(' ');
    if (area) {
      const d = `${path} L ${sx(data[data.length - 1].x)} ${sy(minY)} L ${sx(data[0].x)} ${sy(minY)} Z`;
      svg.appendChild(svgEl('path', { d, fill: color(spec.settings, 0), opacity: 0.18 }));
    }
    svg.appendChild(svgEl('path', { d: path, fill: 'none', stroke: color(spec.settings, 0), 'stroke-width': 3 }));
    data.forEach((p, i) => {
      const circle = svgEl('circle', { cx: sx(p.x), cy: sy(p.y), r: 5, fill: p.row.color || color(spec.settings, i) });
      circle.appendChild(svgEl('title', {}, `${p.row.label || p.row.x_value || i}: ${formatNumber(p.y, spec)}`));
      svg.appendChild(circle);
    });
  }

  function renderScatter(container, spec) {
    const data = pointsForLine(spec);
    addHeader(container, spec);
    if (!data.length) return empty(container);
    const width = 800, height = 420, pad = 55;
    const svg = svgFrame(container, width, height);
    const xs = data.map((p) => p.x), ys = data.map((p) => p.y);
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
    const sx = (v) => pad + (v - minX) / (maxX - minX || 1) * (width - pad * 2);
    const sy = (v) => height - pad - (v - minY) / (maxY - minY || 1) * (height - pad * 2);
    data.forEach((p, i) => {
      const c = svgEl('circle', { cx: sx(p.x), cy: sy(p.y), r: 7, fill: p.row.color || color(spec.settings, i), tabindex: 0 });
      c.appendChild(svgEl('title', {}, `${p.row.label || ''}: ${p.x}, ${p.y}`));
      svg.appendChild(c);
    });
  }

  function renderCounter(container, spec) {
    addHeader(container, spec);
    const grid = el('div', { class: 'viswiz-counter-grid' });
    rows(spec).forEach((row, index) => {
      const card = el('div', { class: 'viswiz-counter-card' });
      card.style.borderTopColor = row.color || color(spec.settings, index);
      card.append(el('strong', {}, formatNumber(row.value, spec)), el('span', {}, row.label || ''));
      grid.appendChild(card);
    });
    container.appendChild(grid);
  }

  function renderTimeline(container, spec) {
    addHeader(container, spec);
    const list = el('ol', { class: 'viswiz-timeline' });
    rows(spec).forEach((row) => {
      const item = el('li');
      const date = row.x_value || row.label || '';
      item.append(el('time', {}, date), el('span', {}, row.label && row.label !== date ? row.label : formatNumber(row.value, spec)));
      list.appendChild(item);
    });
    container.appendChild(list);
  }

  function renderProgress(container, spec) {
    addHeader(container, spec);
    const targetDefault = Number(spec.settings?.target || 0);
    rows(spec).forEach((row) => {
      const target = Number(row.meta?.target || targetDefault || 0);
      const value = Number(row.value || 0), percent = target > 0 ? Math.max(0, Math.min(100, value / target * 100)) : 0;
      const wrap = el('div', { class: 'viswiz-progress-item' });
      const head = el('div', { class: 'viswiz-progress-head' });
      head.append(el('strong', {}, row.label || ''), el('span', {}, target > 0 ? `${formatNumber(value, spec)} / ${formatNumber(target, spec)}` : formatNumber(value, spec)));
      const track = el('div', { class: 'viswiz-progress-track', role: 'progressbar', 'aria-valuenow': String(value), 'aria-valuemax': String(target || value || 1) });
      const fill = el('div', { class: 'viswiz-progress-fill' }); fill.style.width = `${percent}%`; fill.style.background = row.color || color(spec.settings, 0);
      track.appendChild(fill); wrap.append(head, track); container.appendChild(wrap);
    });
  }

  function renderMap(container, spec) {
    addHeader(container, spec);
    const data = rows(spec).filter((r) => Number.isFinite(Number(r.latitude)) && Number.isFinite(Number(r.longitude)));
    if (!data.length) return empty(container);
    const width = 900, height = 450, svg = svgFrame(container, width, height);
    svg.classList.add('viswiz-map-svg');
    for (let lon = -120; lon <= 120; lon += 60) svg.appendChild(svgEl('line', { x1: (lon + 180) / 360 * width, x2: (lon + 180) / 360 * width, y1: 0, y2: height, class: 'viswiz-map-grid' }));
    for (let lat = -60; lat <= 60; lat += 30) svg.appendChild(svgEl('line', { x1: 0, x2: width, y1: (90 - lat) / 180 * height, y2: (90 - lat) / 180 * height, class: 'viswiz-map-grid' }));
    data.forEach((row, i) => {
      const x = (Number(row.longitude) + 180) / 360 * width;
      const y = (90 - Number(row.latitude)) / 180 * height;
      const r = Math.max(5, Math.min(24, 5 + Math.sqrt(Math.abs(Number(row.value || 0)))));
      const c = svgEl('circle', { cx: x, cy: y, r, fill: row.color || color(spec.settings, i), tabindex: 0, class: 'viswiz-map-marker' });
      c.appendChild(svgEl('title', {}, `${row.label || ''} ${formatNumber(row.value, spec)}`)); svg.appendChild(c);
    });
  }

  function renderDiagram(container, spec) {
    addHeader(container, spec);
    const grid = el('div', { class: 'viswiz-diagram-grid' });
    rows(spec).forEach((row, i) => {
      const card = el('article', { class: 'viswiz-diagram-card' });
      card.style.borderColor = row.color || color(spec.settings, i);
      card.append(el('h4', {}, row.label || sprintf(__('Section %d', 'viswiz'), i + 1)));
      const body = el('div'); body.textContent = row.meta?.text || row.meta?.description || formatNumber(row.value, spec); card.appendChild(body); grid.appendChild(card);
    });
    container.appendChild(grid);
  }

  function graphLayout(nodes, relations, renderer, width, height) {
    const result = new Map();
    if (!nodes.length) return result;
    if (renderer === 'org_chart' || renderer === 'flow_diagram') {
      const incoming = new Map(nodes.map((n) => [n.uuid, 0]));
      const outgoing = new Map(nodes.map((n) => [n.uuid, []]));
      relations.forEach((r) => {
        if (incoming.has(r.to_node_uuid) && outgoing.has(r.from_node_uuid)) {
          incoming.set(r.to_node_uuid, incoming.get(r.to_node_uuid) + 1);
          outgoing.get(r.from_node_uuid).push(r.to_node_uuid);
        }
      });
      const queue = [...nodes.filter((n) => incoming.get(n.uuid) === 0).map((n) => n.uuid)];
      if (!queue.length) queue.push(nodes[0].uuid);
      const level = new Map(queue.map((id) => [id, 0]));
      for (let qi = 0; qi < queue.length; qi++) {
        const id = queue[qi], l = level.get(id) || 0;
        (outgoing.get(id) || []).forEach((next) => {
          const nl = Math.max(level.get(next) || 0, l + 1);
          if (!level.has(next) || nl > level.get(next)) level.set(next, nl);
          if (!queue.includes(next)) queue.push(next);
        });
      }
      nodes.forEach((n) => { if (!level.has(n.uuid)) level.set(n.uuid, 0); });
      const groups = new Map(); nodes.forEach((n) => { const l = level.get(n.uuid); if (!groups.has(l)) groups.set(l, []); groups.get(l).push(n); });
      const maxLevel = Math.max(0, ...groups.keys());
      groups.forEach((group, l) => group.forEach((node, i) => {
        const horizontal = renderer === 'flow_diagram';
        const primary = (l + 1) / (maxLevel + 2);
        const secondary = (i + 1) / (group.length + 1);
        result.set(node.uuid, horizontal ? { x: width * primary, y: height * secondary } : { x: width * secondary, y: height * primary });
      }));
      return result;
    }
    const radius = Math.min(width, height) * 0.36, cx = width / 2, cy = height / 2;
    nodes.forEach((node, i) => {
      const a = (i / nodes.length) * Math.PI * 2 - Math.PI / 2;
      const ring = nodes.length > 30 ? 0.58 + (i % 3) * 0.2 : 1;
      result.set(node.uuid, { x: cx + Math.cos(a) * radius * ring, y: cy + Math.sin(a) * radius * ring });
    });
    return result;
  }

  function renderGraph(container, spec) {
    container.__viswizSpecSettings = spec.settings || {};
    addHeader(container, spec);
    const allNodes = Array.isArray(spec.data?.nodes) ? spec.data.nodes : [];
    const allRelations = Array.isArray(spec.data?.relations) ? spec.data.relations : [];
    if (!allNodes.length) return empty(container);

    const frame = el('div', { class: 'viswiz-graph-frame' });
    const stage = el('div', { class: 'viswiz-graph-stage' });
    let query = '';
    let nodeType = '';
    let relationType = '';
    let activeSvg = null;
    let baseView = null;
    let view = null;
    let status = null;

    const applyView = () => {
      if (activeSvg && view) activeSvg.setAttribute('viewBox', `${view.x} ${view.y} ${view.w} ${view.h}`);
    };
    const zoom = (factor) => {
      if (!view || !baseView) return;
      const nextW = Math.max(baseView.w * 0.32, Math.min(baseView.w * 3, view.w * factor));
      const nextH = Math.max(baseView.h * 0.32, Math.min(baseView.h * 3, view.h * factor));
      view.x += (view.w - nextW) / 2;
      view.y += (view.h - nextH) / 2;
      view.w = nextW;
      view.h = nextH;
      applyView();
    };
    const resetView = () => {
      if (!baseView) return;
      view = { ...baseView };
      applyView();
    };

    if (spec.settings?.show_graph_toolbar !== false) {
      const toolbar = el('div', { class: 'viswiz-graph-toolbar' });
      const search = el('input', { type: 'search', placeholder: __('Search nodes', 'viswiz'), 'aria-label': __('Search nodes', 'viswiz') });
      const typeSelect = el('select', { 'aria-label': __('Filter node type', 'viswiz') });
      typeSelect.appendChild(el('option', { value: '' }, __('All node types', 'viswiz')));
      [...new Set(allNodes.map((n) => n.node_type).filter(Boolean))].sort().forEach((type) => typeSelect.appendChild(el('option', { value: type }, type)));
      const relationSelect = el('select', { 'aria-label': __('Filter relation type', 'viswiz') });
      relationSelect.appendChild(el('option', { value: '' }, __('All relation types', 'viswiz')));
      [...new Set(allRelations.map((r) => r.relation_type).filter(Boolean))].sort().forEach((type) => relationSelect.appendChild(el('option', { value: type }, type)));
      const zoomOut = el('button', { type: 'button', class: 'viswiz-graph-tool', title: __('Zoom out', 'viswiz'), 'aria-label': __('Zoom out', 'viswiz') }, '−');
      const zoomReset = el('button', { type: 'button', class: 'viswiz-graph-tool', title: __('Reset zoom', 'viswiz'), 'aria-label': __('Reset zoom', 'viswiz') }, '100%');
      const zoomIn = el('button', { type: 'button', class: 'viswiz-graph-tool', title: __('Zoom in', 'viswiz'), 'aria-label': __('Zoom in', 'viswiz') }, '+');
      status = el('span', { class: 'viswiz-graph-status', 'aria-live': 'polite' });
      if (spec.settings?.show_graph_search !== false) toolbar.appendChild(search);
      if (spec.settings?.show_graph_filters !== false) toolbar.append(typeSelect, relationSelect);
      if (spec.settings?.show_graph_zoom !== false) toolbar.append(zoomOut, zoomReset, zoomIn);
      toolbar.appendChild(status);
      frame.appendChild(toolbar);
      search.addEventListener('input', () => { query = search.value.trim().toLowerCase(); view = null; draw(); });
      typeSelect.addEventListener('change', () => { nodeType = typeSelect.value; view = null; draw(); });
      relationSelect.addEventListener('change', () => { relationType = relationSelect.value; draw(); });
      zoomOut.addEventListener('click', () => zoom(1.25));
      zoomReset.addEventListener('click', resetView);
      zoomIn.addEventListener('click', () => zoom(0.8));
    }

    frame.appendChild(stage);
    container.appendChild(frame);

    function draw() {
      stage.replaceChildren();
      const nodes = allNodes.filter((node) => {
        if (nodeType && node.node_type !== nodeType) return false;
        if (!query) return true;
        return `${node.title || ''} ${node.label || ''} ${node.slug || ''} ${node.node_type || ''} ${node.node_subtype || ''}`.toLowerCase().includes(query);
      });
      if (!nodes.length) {
        if (status) status.textContent = __('No matching nodes', 'viswiz');
        stage.appendChild(el('p', { class: 'viswiz-empty' }, __('No matching nodes', 'viswiz')));
        activeSvg = null;
        return;
      }
      const ids = new Set(nodes.map((n) => n.uuid));
      const relations = allRelations.filter((r) => ids.has(r.from_node_uuid) && ids.has(r.to_node_uuid) && (!relationType || r.relation_type === relationType));
      if (status) status.textContent = `${nodes.length}/${allNodes.length} ${__('nodes', 'viswiz')} · ${relations.length}/${allRelations.length} ${__('relations', 'viswiz')}`;

      const width = 1000;
      const height = Math.max(560, Math.min(1100, 440 + nodes.length * 4));
      const svg = svgEl('svg', { viewBox: `0 0 ${width} ${height}`, class: 'viswiz-graph-svg', role: 'img', 'aria-label': __('Node graph', 'viswiz') });
      activeSvg = svg;
      baseView = { x: 0, y: 0, w: width, h: height };
      if (!view) view = { ...baseView };
      applyView();

      const defs = svgEl('defs');
      const markerId = `vw-arrow-${spec.id}-${Math.random().toString(36).slice(2)}`;
      const marker = svgEl('marker', { id: markerId, viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 7, markerHeight: 7, orient: 'auto-start-reverse' });
      marker.appendChild(svgEl('path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: 'currentColor' }));
      defs.appendChild(marker);
      svg.appendChild(defs);
      const graphLayer = svgEl('g', { class: 'viswiz-graph-layer' });
      svg.appendChild(graphLayer);

      const layout = graphLayout(nodes, relations, spec.renderer, width, height);
      const nodeMap = new Map(nodes.map((n) => [n.uuid, n]));
      relations.forEach((rel) => {
        const a = layout.get(rel.from_node_uuid), b = layout.get(rel.to_node_uuid); if (!a || !b) return;
        const line = svgEl('line', { x1: a.x, y1: a.y, x2: b.x, y2: b.y, class: 'viswiz-graph-edge', 'stroke-width': Math.max(1, Math.min(6, Number(rel.intensity || 1))), 'marker-end': rel.direction === 'undirected' ? '' : `url(#${markerId})` });
        graphLayer.appendChild(line);
        if (rel.direction === 'bidirectional') line.setAttribute('marker-start', `url(#${markerId})`);
        if (spec.settings?.show_relation_labels !== false && (rel.label || rel.relation_type)) {
          const label = svgEl('text', { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 - 6, class: 'viswiz-graph-edge-label', 'text-anchor': 'middle' }, rel.label || rel.relation_type);
          graphLayer.appendChild(label);
        }
      });
      nodes.forEach((node, i) => {
        const pos = layout.get(node.uuid); if (!pos) return;
        const g = svgEl('g', { class: 'viswiz-graph-node', transform: `translate(${pos.x},${pos.y})`, tabindex: 0, role: 'button', 'aria-label': `${__('View node', 'viswiz')}: ${node.title || node.label || __('Node', 'viswiz')}` });
        const rect = svgEl('rect', { x: -76, y: -35, width: 152, height: 70, rx: 14, fill: node.meta?.color || color(spec.settings, i), opacity: 0.96 });
        g.appendChild(rect);
        g.appendChild(svgEl('text', { x: 0, y: -3, 'text-anchor': 'middle', class: 'viswiz-graph-node-title' }, truncate(node.title || node.label || node.slug || '', 24)));
        if (spec.settings?.show_type_badges !== false && node.node_type) g.appendChild(svgEl('text', { x: 0, y: 17, 'text-anchor': 'middle', class: 'viswiz-graph-node-type-label' }, truncate(`${node.node_type}${node.node_subtype ? ` / ${node.node_subtype}` : ''}`, 27)));
        const open = () => showNodeModal(container, node, relations, nodeMap, g);
        g.addEventListener('click', open);
        g.addEventListener('keydown', (event) => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); open(); } });
        graphLayer.appendChild(g);
      });

      let dragging = false;
      let last = null;
      svg.addEventListener('pointerdown', (event) => {
        if (event.target.closest?.('.viswiz-graph-node')) return;
        dragging = true;
        last = { x: event.clientX, y: event.clientY };
        svg.classList.add('is-panning');
        svg.setPointerCapture?.(event.pointerId);
      });
      svg.addEventListener('pointermove', (event) => {
        if (!dragging || !last || !view) return;
        const rect = svg.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        view.x -= (event.clientX - last.x) * (view.w / rect.width);
        view.y -= (event.clientY - last.y) * (view.h / rect.height);
        last = { x: event.clientX, y: event.clientY };
        applyView();
      });
      const endPan = () => { dragging = false; last = null; svg.classList.remove('is-panning'); };
      svg.addEventListener('pointerup', endPan);
      svg.addEventListener('pointercancel', endPan);
      svg.addEventListener('wheel', (event) => {
        if (!event.ctrlKey && !event.metaKey) return;
        event.preventDefault();
        zoom(event.deltaY < 0 ? 0.88 : 1.14);
      }, { passive: false });
      stage.appendChild(svg);
    }
    draw();
  }

  function showNodeModal(container, node, relations, nodeMap, opener = null) {
    const settings = container.__viswizSpecSettings || {};
    const titleFallback = settings.node_modal_title_fallback || __('Node', 'viswiz');
    const overlay = el('div', { class: 'viswiz-modal-overlay', role: 'dialog', 'aria-modal': 'true', 'aria-label': node.title || node.label || titleFallback });
    const modal = el('div', { class: 'viswiz-node-modal' });
    const close = el('button', { type: 'button', class: 'viswiz-modal-close', 'aria-label': settings.node_modal_close_label || __('Close', 'viswiz') }, '×');
    modal.append(close, el('h3', {}, node.title || node.label || node.slug || titleFallback));

    const images = container.__viswizSpecSettings?.show_node_images === false ? [] : (Array.isArray(node.image_gallery) ? node.image_gallery.filter((image) => image?.url) : []);
    if (images.length) {
      const gallery = el('div', { class: 'viswiz-node-gallery' });
      const image = el('img', { class: 'viswiz-node-image', src: images[0].url, alt: images[0].alt || node.title || '' });
      const caption = el('div', { class: 'viswiz-node-image-caption' }, images[0].caption || '');
      const count = el('span', { class: 'viswiz-node-image-count' });
      let index = 0;
      const updateImage = () => {
        const current = images[index];
        image.src = current.url;
        image.alt = current.alt || node.title || '';
        caption.textContent = current.caption || '';
        caption.hidden = !current.caption;
        count.textContent = `${index + 1}/${images.length}`;
      };
      gallery.appendChild(image);
      if (images.length > 1) {
        const controls = el('div', { class: 'viswiz-node-gallery-controls' });
        const previous = el('button', { type: 'button', 'aria-label': settings.node_modal_previous_image_label || __('Previous image', 'viswiz') }, '‹');
        const next = el('button', { type: 'button', 'aria-label': settings.node_modal_next_image_label || __('Next image', 'viswiz') }, '›');
        previous.addEventListener('click', () => { index = (index - 1 + images.length) % images.length; updateImage(); });
        next.addEventListener('click', () => { index = (index + 1) % images.length; updateImage(); });
        controls.append(previous, count, next);
        gallery.appendChild(controls);
      }
      gallery.appendChild(caption);
      updateImage();
      modal.appendChild(gallery);
    }
    if (node.node_type) modal.appendChild(el('p', { class: 'viswiz-node-type' }, `${node.node_type}${node.node_subtype ? ` · ${node.node_subtype}` : ''}`));
    if (node.description_html || node.description) {
      const description = el('div', { class: 'viswiz-node-description' });
      description.innerHTML = node.description_html || node.description;
      modal.appendChild(description);
    }
    if (Array.isArray(node.public_fields) && node.public_fields.length) {
      const details = el('dl', { class: 'viswiz-node-public-fields' });
      node.public_fields.forEach((field) => {
        if (!field?.value) return;
        details.appendChild(el('dt', {}, field.label || ''));
        const dd = el('dd');
        if (field.type === 'url') {
          const link = el('a', { href: field.value, target: '_blank', rel: 'noopener noreferrer' }, field.value); dd.appendChild(link);
        } else if (field.type === 'formatted') {
          dd.innerHTML = field.value;
        } else {
          dd.textContent = field.value;
        }
        details.appendChild(dd);
      });
      if (details.childElementCount) modal.appendChild(details);
    }
    const related = relations.filter((relation) => relation.from_node_uuid === node.uuid || relation.to_node_uuid === node.uuid);
    if (related.length) {
      modal.appendChild(el('h4', {}, settings.node_modal_related_heading || __('Related nodes', 'viswiz')));
      const list = el('ul', { class: 'viswiz-related-list' });
      related.forEach((relation) => {
        const outgoing = relation.from_node_uuid === node.uuid;
        const other = nodeMap.get(outgoing ? relation.to_node_uuid : relation.from_node_uuid);
        if (!other) return;
        const relationLabel = outgoing
          ? (relation.label || relation.relation_type || settings.node_modal_relation_fallback || __('Relation', 'viswiz'))
          : (relation.inverse_label || relation.label || relation.relation_type || settings.node_modal_relation_fallback || __('Relation', 'viswiz'));
        list.appendChild(el('li', {}, `${relationLabel}: ${other.title || other.label || other.slug}`));
      });
      modal.appendChild(list);
    }
    overlay.appendChild(modal);
    container.appendChild(overlay);
    close.focus();

    const dismiss = () => {
      document.removeEventListener('keydown', onKeydown);
      overlay.remove();
      if (opener?.isConnected) opener.focus();
    };
    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        dismiss();
        return;
      }
      if (event.key !== 'Tab') return;
      const focusable = [...modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])')].filter((item) => !item.disabled && !item.hidden);
      if (!focusable.length) return;
      const first = focusable[0], last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    };
    close.addEventListener('click', dismiss);
    overlay.addEventListener('click', (event) => { if (event.target === overlay) dismiss(); });
    document.addEventListener('keydown', onKeydown);
  }

  function truncate(value, length) { const s = String(value || ''); return s.length > length ? `${s.slice(0, length - 1)}…` : s; }
  function empty(container) { container.appendChild(el('p', { class: 'viswiz-empty' }, __('No data available.', 'viswiz'))); }

  function addFullscreen(container, spec) {
    if (container.__viswizFullscreenHandler) {
      document.removeEventListener('fullscreenchange', container.__viswizFullscreenHandler);
      container.__viswizFullscreenHandler = null;
    }
    if (!spec.settings?.full_screen || !document.fullscreenEnabled) return;
    const button = el('button', { type: 'button', class: 'viswiz-fullscreen' }, __('Full screen', 'viswiz'));
    button.addEventListener('click', async () => {
      if (document.fullscreenElement === container) await document.exitFullscreen(); else await container.requestFullscreen();
    });
    const onFullscreen = () => { button.textContent = document.fullscreenElement === container ? __('Exit full screen', 'viswiz') : __('Full screen', 'viswiz'); };
    container.__viswizFullscreenHandler = onFullscreen;
    document.addEventListener('fullscreenchange', onFullscreen);
    container.prepend(button);
  }

  function render(container, spec) {
    container.replaceChildren();
    container.classList.add(`is-${spec.renderer || 'pie'}`);
    applyTheme(container, spec.settings || {});
    const renderers = {
      pie: renderPie, bar: (c, s) => renderBars(c, s, false), column: (c, s) => renderBars(c, s, true),
      line: renderLine, area: (c, s) => renderLine(c, s, true), scatter: renderScatter, counter: renderCounter,
      timeline: renderTimeline, progress: renderProgress, map: renderMap, graph: renderGraph, flow_diagram: renderGraph,
      org_chart: renderGraph, diagram: renderDiagram,
    };
    (renderers[spec.renderer] || renderPie)(container, spec);
    addFullscreen(container, spec);
  }

  function load(container) {
    const endpoint = container.dataset.viswizEndpoint;
    if (!endpoint) return;
    const run = () => fetchSpec(endpoint).then((spec) => { render(container, spec); schedule(container, spec); }).catch((error) => { container.replaceChildren(el('p', { class: 'viswiz-error' }, error.message || __('Could not load visualization.', 'viswiz'))); });
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => { if (entries.some((entry) => entry.isIntersecting)) { observer.disconnect(); run(); } }, { rootMargin: '300px' }); observer.observe(container);
    } else run();
  }

  function schedule(container, spec) {
    const refresh = Number(spec.refresh_ms || 0); if (!refresh || spec.source_type !== 'woo_live') return;
    if (container.__viswizTimer) clearTimeout(container.__viswizTimer);
    container.__viswizTimer = setTimeout(() => {
      if (document.visibilityState === 'visible' && document.body.contains(container)) load(container); else schedule(container, spec);
    }, Math.max(60000, refresh));
  }

  function init() { document.querySelectorAll('[data-viswiz-visualization]').forEach(load); }
  window.VisWiz = { render, load, init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
