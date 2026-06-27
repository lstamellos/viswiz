(function () {
  const POLL_INTERVAL = 30000;

  function fetchJson(endpoint, params = {}) {
    const url = new URL(`${VisWizData.restUrl}${endpoint}`);
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) {
        url.searchParams.set(key, value);
      }
    });
    return fetch(url.toString(), {
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': VisWizData.nonce,
      },
    }).then((response) => response.json());
  }

  function renderProgress(container, data) {
    container.innerHTML = '';
    const label = document.createElement('div');
    label.className = 'viswiz-progress-label';
    label.textContent = data.label;

    const bar = document.createElement('div');
    bar.className = 'viswiz-progress-bar';

    const fill = document.createElement('div');
    fill.className = 'viswiz-progress-fill';
    const targetValue = data.target > 0 ? data.target : getMaxTargetValue(data.targets);
    const percent = targetValue > 0 ? Math.min(100, (data.value / targetValue) * 100) : 0;
    fill.style.width = `${percent}%`;

    const meta = document.createElement('div');
    meta.className = 'viswiz-progress-meta';
    meta.textContent = `${formatCurrency(data.value)} / ${formatCurrency(targetValue)} (${percent.toFixed(1)}%)`;

    bar.appendChild(fill);
    container.appendChild(label);
    container.appendChild(bar);
    container.appendChild(buildProgressMarkers(data.targets, targetValue));
    container.appendChild(meta);
  }

  function getMaxTargetValue(targets) {
    if (!targets || !targets.length) {
      return 0;
    }
    return targets.reduce((max, target) => Math.max(max, parseFloat(target.value || 0)), 0);
  }

  function buildProgressMarkers(targets, maxValue) {
    const wrapper = document.createElement('div');
    wrapper.className = 'viswiz-progress-markers';
    (targets || []).forEach((target) => {
      const targetValue = parseFloat(target.value || 0);
      if (!targetValue) {
        return;
      }
      const marker = document.createElement('div');
      marker.className = 'viswiz-progress-marker';
      const offset = maxValue > 0 ? Math.min(100, (targetValue / maxValue) * 100) : 0;
      marker.style.left = `${offset}%`;
      marker.title = `${target.name || 'Target'}: ${targetValue}`;
      const markerLabel = document.createElement('span');
      markerLabel.className = 'viswiz-progress-marker-label';
      markerLabel.textContent = target.name || 'Target';
      marker.appendChild(markerLabel);
      wrapper.appendChild(marker);
    });
    return wrapper;
  }

  function renderPie(container, data) {
    container.innerHTML = '';
    const title = document.createElement('div');
    title.className = 'viswiz-pie-title';
    title.textContent = data.title;

    const svg = d3
      .create('svg')
      .attr('viewBox', '0 0 220 220')
      .attr('class', 'viswiz-pie-chart');

    const total = data.values.reduce((sum, entry) => sum + entry.value, 0);
    const pie = d3.pie().value((entry) => entry.value || 0);
    const arc = d3.arc().innerRadius(0).outerRadius(100);
    const g = svg.append('g').attr('transform', 'translate(110,110)');

    g.selectAll('path')
      .data(pie(data.values))
      .enter()
      .append('path')
      .attr('d', arc)
      .attr('fill', (entry, index) => entry.data.color || defaultColors[index % defaultColors.length])
      .attr('stroke', '#fff')
      .attr('stroke-width', 1);

    container.appendChild(title);
    container.appendChild(svg.node());

    const legend = document.createElement('ul');
    legend.className = 'viswiz-pie-legend';
    data.values.forEach((entry, index) => {
      const item = document.createElement('li');
      const swatch = document.createElement('span');
      swatch.className = 'viswiz-swatch';
      swatch.style.backgroundColor = entry.color || defaultColors[index % defaultColors.length];
      item.appendChild(swatch);
      item.appendChild(document.createTextNode(`${entry.label}: ${formatPieValue(entry.value, data.isCurrency)}`));
      legend.appendChild(item);
    });
    container.appendChild(legend);
  }

  function renderDiagram(container, data) {
    container.innerHTML = '';
    const header = document.createElement('h3');
    header.textContent = 'Diagram';
    container.appendChild(header);

    data.forEach((section) => {
      const sectionEl = document.createElement('div');
      sectionEl.className = 'viswiz-diagram-section';

      const title = document.createElement('strong');
      title.textContent = section.title || 'Section';
      sectionEl.appendChild(title);

      const list = document.createElement('ul');
      (section.items || []).forEach((item) => {
        const li = document.createElement('li');
        li.textContent = item;
        list.appendChild(li);
      });
      sectionEl.appendChild(list);
      container.appendChild(sectionEl);
    });
  }

  function renderGraph(container, data) {
    container.innerHTML = '';

    const nodes = (data.nodes || []).map((n) => ({
      ...n,
      id: n.id,
      label: n.label || n.title || '',
      title: n.title || n.label || '',
      main_image_url: n.main_image_url || n.mainImageUrl || '',
      default_image_url: n.default_image_url || n.defaultImageUrl || n.image_url || n.imageUrl || n.thumbnail_url || n.thumbnailUrl || '',
      other_image_urls: n.other_image_urls || [],
      custom_labels: n.custom_labels || [],
    }));
    nodes.forEach((node) => {
      node.display_image_url = node.main_image_url || node.default_image_url || '';
    });

    const links = (data.links || []).map((l) => ({
      source: l.from,
      target: l.to,
      label: l.label || '',
      direction: l.direction || 'directed',
      intensity: parseFloat(l.intensity || 1),
      relation_type: l.relation_type || '',
    }));

    if (!nodes.length) {
      container.textContent = 'No graph data available.';
      return;
    }

    const width = container.clientWidth || 400;
    const height = Math.max(360, Math.round(width * 0.62));
    const cardWidth = 150;
    const cardHeight = 112;
    const imageHeight = 64;
    const linkDistance = parseInt(container.dataset.linkDistance, 10) || 150;
    const chargeStrength = parseInt(container.dataset.chargeStrength, 10) || -500;
    const nodeColor = getComputedStyle(container).getPropertyValue('--viswiz-primary').trim() || '#4caf50';
    const linkColor = getComputedStyle(container).getPropertyValue('--viswiz-secondary').trim() || '#999';
    const textColor = getComputedStyle(container).getPropertyValue('--viswiz-text').trim() || '#333';

    const svg = d3
      .create('svg')
      .attr('viewBox', [0, 0, width, height])
      .attr('class', 'viswiz-graph-svg')
      .attr('width', '100%')
      .attr('height', height)
      .attr('role', 'img')
      .attr('aria-label', 'Graph visualization');

    const simulation = d3
      .forceSimulation(nodes)
      .force('link', d3.forceLink(links).id((d) => d.id).distance(linkDistance))
      .force('charge', d3.forceManyBody().strength(chargeStrength))
      .force('center', d3.forceCenter(width / 2, height / 2))
      .force('collision', d3.forceCollide().radius(Math.hypot(cardWidth, cardHeight) / 2 + 12));

    const defs = svg.append('defs');
    defs
      .append('marker')
      .attr('id', 'viswiz-arrowhead')
      .attr('viewBox', '0 -5 10 10')
      .attr('refX', 9)
      .attr('refY', 0)
      .attr('markerWidth', 7)
      .attr('markerHeight', 7)
      .attr('orient', 'auto')
      .append('path')
      .attr('d', 'M0,-5L10,0L0,5')
      .attr('fill', linkColor);

    const link = svg
      .append('g')
      .attr('class', 'viswiz-graph-links-g')
      .selectAll('line')
      .data(links)
      .join('line')
      .attr('stroke', linkColor)
      .attr('stroke-width', (d) => Math.max(1, Math.min(8, d.intensity || 1)))
      .attr('marker-start', (d) => d.direction === 'bidirectional' ? 'url(#viswiz-arrowhead)' : null)
      .attr('marker-end', (d) => d.direction === 'undirected' ? null : 'url(#viswiz-arrowhead)');

    const linkLabels = svg
      .append('g')
      .attr('class', 'viswiz-graph-link-labels')
      .selectAll('text')
      .data(links)
      .join('text')
      .attr('font-size', 10)
      .attr('fill', textColor)
      .attr('text-anchor', 'middle')
      .text((d) => [d.label, d.relation_type].filter(Boolean).join(' · '));

    const node = svg
      .append('g')
      .attr('class', 'viswiz-graph-nodes')
      .selectAll('g')
      .data(nodes)
      .join('g')
      .attr('class', 'viswiz-graph-node-card')
      .attr('tabindex', 0)
      .attr('role', 'button')
      .attr('aria-label', (d) => `View details for ${d.title || d.label || 'node'}`)
      .on('click', (event, d) => showNodeDetails(container, d, nodes, links))
      .on('keydown', (event, d) => {
        if (event.key === 'Enter' || event.key === ' ') {
          event.preventDefault();
          showNodeDetails(container, d, nodes, links);
        }
      })
      .call(drag(simulation));

    node.append('rect')
      .attr('x', -cardWidth / 2)
      .attr('y', -cardHeight / 2)
      .attr('width', cardWidth)
      .attr('height', cardHeight)
      .attr('rx', 10)
      .attr('fill', '#fff')
      .attr('stroke', nodeColor)
      .attr('stroke-width', 2);

    node.filter((d) => !!d.display_image_url)
      .append('image')
      .attr('href', (d) => d.display_image_url)
      .attr('x', -cardWidth / 2 + 8)
      .attr('y', -cardHeight / 2 + 8)
      .attr('width', cardWidth - 16)
      .attr('height', imageHeight)
      .attr('preserveAspectRatio', 'xMidYMid slice');

    node.filter((d) => !d.display_image_url)
      .append('rect')
      .attr('x', -cardWidth / 2 + 8)
      .attr('y', -cardHeight / 2 + 8)
      .attr('width', cardWidth - 16)
      .attr('height', imageHeight)
      .attr('rx', 6)
      .attr('fill', nodeColor)
      .attr('opacity', 0.12);

    node.append('text')
      .attr('y', cardHeight / 2 - 24)
      .attr('text-anchor', 'middle')
      .attr('font-size', 12)
      .attr('font-weight', 700)
      .attr('fill', textColor)
      .attr('pointer-events', 'none')
      .each(function (d) { wrapSvgText(d3.select(this), d.title || d.label, cardWidth - 18, 2); });

    simulation.on('tick', () => {
      nodes.forEach((d) => {
        d.x = Math.max(cardWidth / 2 + 4, Math.min(width - cardWidth / 2 - 4, d.x));
        d.y = Math.max(cardHeight / 2 + 4, Math.min(height - cardHeight / 2 - 4, d.y));
      });

      link
        .attr('x1', (d) => edgePoint(d.source, d.target, cardWidth, cardHeight).x)
        .attr('y1', (d) => edgePoint(d.source, d.target, cardWidth, cardHeight).y)
        .attr('x2', (d) => edgePoint(d.target, d.source, cardWidth, cardHeight).x)
        .attr('y2', (d) => edgePoint(d.target, d.source, cardWidth, cardHeight).y);

      linkLabels
        .attr('x', (d) => (d.source.x + d.target.x) / 2)
        .attr('y', (d) => (d.source.y + d.target.y) / 2 - 8);

      node.attr('transform', (d) => `translate(${d.x},${d.y})`);
    });

    container.appendChild(svg.node());

    function drag(sim) {
      function dragstarted(event) {
        if (!event.active) sim.alphaTarget(0.3).restart();
        event.subject.fx = event.subject.x;
        event.subject.fy = event.subject.y;
      }
      function dragged(event) {
        event.subject.fx = event.x;
        event.subject.fy = event.y;
      }
      function dragended(event) {
        if (!event.active) sim.alphaTarget(0);
        event.subject.fx = null;
        event.subject.fy = null;
      }
      return d3.drag().on('start', dragstarted).on('drag', dragged).on('end', dragended);
    }
  }

  function edgePoint(from, to, width, height) {
    const dx = to.x - from.x;
    const dy = to.y - from.y;
    if (!dx && !dy) {
      return { x: from.x, y: from.y };
    }
    const scale = Math.min(Math.abs((width / 2) / dx) || Infinity, Math.abs((height / 2) / dy) || Infinity);
    return { x: from.x + dx * scale, y: from.y + dy * scale };
  }

  function wrapSvgText(text, value, width, maxLines) {
    const words = String(value || '').split(/\s+/).filter(Boolean);
    let line = [];
    let lineNumber = 0;
    const lineHeight = 14;
    const y = parseFloat(text.attr('y')) || 0;
    let tspan = text.text(null).append('tspan').attr('x', 0).attr('y', y);
    words.forEach((word) => {
      line.push(word);
      tspan.text(line.join(' '));
      if (tspan.node().getComputedTextLength() > width && line.length > 1) {
        line.pop();
        tspan.text(line.join(' '));
        line = [word];
        lineNumber += 1;
        if (lineNumber >= maxLines) {
          tspan.text(`${tspan.text()}…`);
          return;
        }
        tspan = text.append('tspan').attr('x', 0).attr('y', y).attr('dy', lineNumber * lineHeight).text(word);
      }
    });
  }

  function showNodeDetails(container, node, nodes = [], links = []) {
    const existing = container.querySelector('.viswiz-graph-node-modal');
    if (existing) existing.remove();

    const overlay = document.createElement('div');
    overlay.className = 'viswiz-graph-node-modal';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');

    const card = document.createElement('div');
    card.className = 'viswiz-graph-node-modal-card';
    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'viswiz-graph-node-modal-close';
    close.textContent = '×';
    close.setAttribute('aria-label', 'Close node details');
    close.addEventListener('click', () => overlay.remove());
    card.appendChild(close);

    if (node.display_image_url) {
      const image = document.createElement('img');
      image.className = 'viswiz-graph-node-modal-image';
      image.src = node.display_image_url;
      image.alt = node.title || node.label || '';
      card.appendChild(image);
    }

    if (node.other_image_urls && node.other_image_urls.length) {
      const gallery = document.createElement('div');
      gallery.className = 'viswiz-graph-node-modal-gallery';
      node.other_image_urls.forEach((url, index) => {
        const image = document.createElement('img');
        image.src = url;
        image.alt = `${node.title || node.label || 'Node'} image ${index + 1}`;
        gallery.appendChild(image);
      });
      card.appendChild(gallery);
    }

    const title = document.createElement('h3');
    const titleLink = createNodeLink(container, node, nodes, links);
    titleLink.textContent = node.title || node.label || 'Node details';
    title.appendChild(titleLink);
    card.appendChild(title);
    const details = document.createElement('dl');
    details.className = 'viswiz-node-detail-list';
    appendTypeDetail(details, 'Node type', node.node_type_label || node.node_type || node.entity_type_label || node.entity_type, node.node_type || node.entity_type, 'type', container, nodes, links);
    appendTypeDetail(details, 'Node subtype', node.node_subtype_label || node.node_subtype, node.node_subtype, 'subtype', container, nodes, links);
    if (node.node_subtype === 'proposed') {
      appendDetail(details, 'Proposed subtype reason', node.proposed_subtype_reason, 'long');
      appendDetail(details, 'Example entity', node.proposed_subtype_example);
      appendDetail(details, 'Why existing types do not fit', node.proposed_subtype_gap, 'long');
      appendDetail(details, 'Proposal status', node.proposed_subtype_status);
    }
    appendDetail(details, 'Description', node.description, node.description ? 'formatted' : 'short');
    (node.custom_labels || []).forEach((item) => appendDetail(details, item.key || 'Custom field', item.value, item.type));
    card.appendChild(details);
    appendRelatedNodes(card, container, node, nodes, links);
    overlay.appendChild(card);
    overlay.addEventListener('click', (event) => { if (event.target === overlay) overlay.remove(); });
    container.appendChild(overlay);
    close.focus();
  }

  function appendDetail(list, label, value, type = 'short', meta = {}) {
    if (value === undefined || value === null || value === '') return;
    const dt = document.createElement('dt');
    dt.textContent = label;
    const dd = document.createElement('dd');
    if (type === 'node-link' && meta.node) {
      dd.appendChild(createNodeLink(meta.container, meta.node, meta.nodes || [], meta.links || []));
    } else if (type === 'url') {
      const link = document.createElement('a');
      link.href = value;
      link.textContent = value;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      dd.appendChild(link);
    } else if (type === 'formatted') {
      dd.innerHTML = value;
    } else {
      dd.textContent = value;
    }
    list.appendChild(dt);
    list.appendChild(dd);
  }


  function createNodeLink(container, node, nodes, links) {
    const link = document.createElement('a');
    link.href = `#${encodeURIComponent(node.id || node.title || node.label || 'node')}`;
    link.textContent = node.title || node.label || 'Node details';
    link.addEventListener('click', (event) => {
      event.preventDefault();
      showNodeDetails(container, node, nodes, links);
    });
    return link;
  }

  function appendTypeDetail(list, label, value, key, filter, container, nodes, links) {
    if (!value) return;
    const dt = document.createElement('dt');
    dt.textContent = label;
    const dd = document.createElement('dd');
    const link = document.createElement('a');
    link.href = `#${filter}-${encodeURIComponent(key || value)}`;
    link.textContent = value;
    link.addEventListener('click', (event) => {
      event.preventDefault();
      showTypeNodes(container, filter, key, value, nodes, links);
    });
    dd.appendChild(link);
    list.appendChild(dt);
    list.appendChild(dd);
  }

  function getRelatedNodes(node, nodes, links) {
    return links.reduce((related, link) => {
      const sourceId = typeof link.source === 'object' ? link.source.id : link.source;
      const targetId = typeof link.target === 'object' ? link.target.id : link.target;
      const isSource = sourceId === node.id;
      const isTarget = targetId === node.id;
      if (!isSource && !isTarget) return related;
      const relatedId = isSource ? targetId : sourceId;
      const relatedNode = nodes.find((candidate) => candidate.id === relatedId);
      if (!relatedNode) return related;
      related.push({
        node: relatedNode,
        relation: [link.label, link.relation_type].filter(Boolean).join(' · ') || (isSource ? 'Outgoing relation' : 'Incoming relation'),
      });
      return related;
    }, []);
  }

  function appendRelatedNodes(card, container, node, nodes, links) {
    const related = getRelatedNodes(node, nodes, links);
    if (!related.length) return;
    const section = document.createElement('section');
    section.className = 'viswiz-related-nodes';
    const heading = document.createElement('h4');
    heading.textContent = 'Related nodes';
    section.appendChild(heading);
    const grid = document.createElement('div');
    grid.className = 'viswiz-related-node-grid';
    related.forEach((item) => grid.appendChild(createNodePreviewCard(container, item.node, nodes, links, item.relation)));
    section.appendChild(grid);
    card.appendChild(section);
  }

  function createNodePreviewCard(container, node, nodes, links, relationLabel = '') {
    const item = document.createElement('article');
    item.className = 'viswiz-related-node-card';
    if (node.display_image_url) {
      const image = document.createElement('img');
      image.src = node.display_image_url;
      image.alt = node.title || node.label || '';
      item.appendChild(image);
    } else {
      const placeholder = document.createElement('div');
      placeholder.className = 'viswiz-related-node-placeholder';
      item.appendChild(placeholder);
    }
    const title = document.createElement('h5');
    title.appendChild(createNodeLink(container, node, nodes, links));
    item.appendChild(title);
    if (relationLabel) {
      const relation = document.createElement('span');
      relation.className = 'viswiz-related-node-relation';
      relation.textContent = relationLabel;
      item.appendChild(relation);
    }
    return item;
  }

  function showTypeNodes(container, filter, key, label, nodes, links) {
    const matching = nodes.filter((node) => filter === 'subtype' ? node.node_subtype === key : (node.node_type || node.entity_type) === key);
    const pseudoNode = { title: label || 'Nodes' };
    showNodeDetails(container, pseudoNode, nodes, links);
    const card = container.querySelector('.viswiz-graph-node-modal-card');
    if (!card) return;
    card.querySelectorAll('.viswiz-node-detail-list, .viswiz-graph-node-modal-image, .viswiz-graph-node-modal-gallery').forEach((el) => el.remove());
    const heading = card.querySelector('h3');
    if (heading) heading.textContent = `${label || 'Selected type'} nodes`;
    const section = document.createElement('section');
    section.className = 'viswiz-related-nodes';
    const grid = document.createElement('div');
    grid.className = 'viswiz-related-node-grid';
    matching.forEach((node) => grid.appendChild(createNodePreviewCard(container, node, nodes, links)));
    section.appendChild(grid);
    card.appendChild(section);
  }


  function loadAutoProgress(container) {
    const params = buildSalesParams(container);
    fetchJson('/sales', params)
      .then((data) => {
        const target = parseFloat(container.dataset.target || VisWizData.target || 0);
        renderProgress(container, {
          label: container.dataset.label || 'Sales Progress',
          value: parseFloat(data.totalSales || 0),
          target: target || 0,
        });
      })
      .catch(() => {
        container.textContent = 'Unable to load sales data.';
      });
  }

  function loadAutoPie(container) {
    const params = buildSalesParams(container);
    const scope = params.scope || VisWizData.salesScope || 'total';
    if (scope === 'total') {
      fetchJson('/sales-status', params)
        .then((data) => {
          renderPie(container, {
            title: container.dataset.title || 'Sales Breakdown',
            values: data.statusCounts || [],
            isCurrency: false,
          });
        })
        .catch(() => {
          container.textContent = 'Unable to load sales breakdown.';
        });
      return;
    }

    fetchJson('/sales-breakdown', params)
      .then((data) => {
        renderPie(container, {
          title: container.dataset.title || 'Sales Breakdown',
          values: data.values || [],
          isCurrency: true,
        });
      })
      .catch(() => {
        container.textContent = 'Unable to load sales breakdown.';
      });
  }

  function loadManualProgress(container, index) {
    const manualOverride = getManualData(container);
    if (manualOverride) {
      if (Array.isArray(manualOverride)) {
        renderProgressList(container, manualOverride, container.dataset.label || 'Manual Progress');
        return;
      }
      renderProgress(container, {
        label: manualOverride.label || container.dataset.label || 'Manual Progress',
        value: parseFloat(manualOverride.value || 0),
        target: parseFloat(manualOverride.target || 0),
        targets: manualOverride.targets || [],
      });
      return;
    }

    const manual = VisWizData.manualProgress || [];
    const item = manual[index];
    if (item) {
      renderProgress(container, {
        label: item.label || 'Manual Progress',
        value: parseFloat(item.value || 0),
        target: parseFloat(item.target || 0),
        targets: item.targets || [],
      });
    } else {
      container.textContent = 'No manual progress data available.';
    }
  }

  function renderProgressList(container, items, fallbackLabel) {
    container.innerHTML = '';
    items.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'viswiz-progress-item';
      renderProgress(row, {
        label: item.label || fallbackLabel,
        value: parseFloat(item.value || 0),
        target: parseFloat(item.target || 0),
        targets: item.targets || [],
      });
      container.appendChild(row);
    });
  }

  function loadManualPie(container) {
    const manualOverride = getManualData(container);
    const manual = manualOverride || VisWizData.manualPie || [];
    renderPie(container, {
      title: container.dataset.title || 'Manual Pie Chart',
      values: manual,
      isCurrency: true,
    });
  }

  function initProgress() {
    document.querySelectorAll('.viswiz-progress').forEach((container, index) => {
      applyFormatting(container);
      if (container.dataset.type === 'manual') {
        loadManualProgress(container, index);
      } else {
        loadAutoProgress(container);
        setInterval(() => loadAutoProgress(container), POLL_INTERVAL);
      }
    });
  }

  function initPie() {
    document.querySelectorAll('.viswiz-pie').forEach((container) => {
      applyFormatting(container);
      if (container.dataset.type === 'manual') {
        loadManualPie(container);
      } else {
        loadAutoPie(container);
        setInterval(() => loadAutoPie(container), POLL_INTERVAL);
      }
    });
  }

  function initDiagram() {
    document.querySelectorAll('.viswiz-diagram').forEach((container) => {
      applyFormatting(container);
      const manual = getManualData(container) || VisWizData.diagramData || [];
      renderDiagram(container, manual);
    });
  }

  function initGraph() {
    document.querySelectorAll('.viswiz-graph').forEach((container) => {
      applyFormatting(container);
      const manual = getManualData(container) || VisWizData.graphData || {};
      renderGraph(container, manual);
    });
  }

  function buildSalesParams(container) {
    const productIds =
      container.dataset.productIds ||
      (Array.isArray(VisWizData.salesProduct) ? VisWizData.salesProduct.join(',') : VisWizData.salesProduct) ||
      '';
    const categoryIds =
      container.dataset.categoryIds ||
      (Array.isArray(VisWizData.salesCategory) ? VisWizData.salesCategory.join(',') : VisWizData.salesCategory) ||
      '';
    return {
      scope: container.dataset.scope || VisWizData.salesScope || '',
      period_mode: container.dataset.periodMode || VisWizData.salesPeriodMode || '',
      period_value: container.dataset.periodValue || VisWizData.salesPeriodValue || '',
      period_unit: container.dataset.periodUnit || VisWizData.salesPeriodUnit || '',
      period_start: container.dataset.periodStart || VisWizData.salesPeriodStart || '',
      product_ids: productIds,
      category_ids: categoryIds,
    };
  }

  function getManualData(container) {
    if (!container.dataset.manual) {
      return null;
    }

    try {
      return JSON.parse(container.dataset.manual);
    } catch (error) {
      return null;
    }
  }

  function applyFormatting(container) {
    if (container.dataset.animation && container.dataset.animation !== 'none') {
      container.classList.add(`viswiz-animate-${container.dataset.animation}`);
    }
    if (container.dataset.colors) {
      try {
        const colors = JSON.parse(container.dataset.colors);
        if (colors.primary) {
          container.style.setProperty('--viswiz-primary', colors.primary);
        }
        if (colors.secondary) {
          container.style.setProperty('--viswiz-secondary', colors.secondary);
        }
        if (colors.accent) {
          container.style.setProperty('--viswiz-accent', colors.accent);
        }
        if (colors.background) {
          container.style.setProperty('--viswiz-background', colors.background);
        }
        if (colors.text) {
          container.style.setProperty('--viswiz-text', colors.text);
        }
      } catch (error) {
        return;
      }
    }
  }

  function formatCurrency(value) {
    const amount = parseFloat(value || 0);
    const code = VisWizData.currencyCode || 'USD';
    try {
      return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: code,
      }).format(amount);
    } catch (error) {
      return `${VisWizData.currencySymbol || '$'}${amount.toFixed(2)}`;
    }
  }

  function formatPieValue(value, isCurrency) {
    if (isCurrency === false) {
      const amount = parseFloat(value || 0);
      try {
        return new Intl.NumberFormat().format(amount);
      } catch (error) {
        return amount.toFixed(0);
      }
    }
    return formatCurrency(value);
  }

  const defaultColors = [
    '#4caf50',
    '#03a9f4',
    '#ffc107',
    '#e91e63',
    '#9c27b0',
    '#ff5722',
  ];

  document.addEventListener('DOMContentLoaded', () => {
    initProgress();
    initPie();
    initDiagram();
    initGraph();
  });
})();
