(function () {
  const POLL_INTERVAL = 30000;
  const DEFAULT_NODE_MODAL_LABELS = {
    node_modal_title_fallback: 'Node details',
    node_modal_close_label: 'Close node details',
    node_modal_featured_image_label: 'Featured image',
    node_modal_previous_image_label: 'Previous node image',
    node_modal_next_image_label: 'Next node image',
    node_modal_proposed_subtype_reason: 'Proposed subtype reason',
    node_modal_example_entity: 'Example entity',
    node_modal_proposed_subtype_gap: 'Why existing types do not fit',
    node_modal_proposal_status: 'Proposal status',
    node_modal_custom_field: 'Custom field',
    node_modal_related_heading: 'Related nodes by relation',
    node_modal_relation_fallback: 'Relation',
    node_modal_outgoing_relation: 'Outgoing relation',
    node_modal_incoming_relation: 'Incoming relation',
    node_modal_direction_outgoing: 'Outgoing',
    node_modal_direction_incoming: 'Incoming',
    node_modal_direction_bidirectional: 'Bidirectional',
    node_modal_direction_undirected: 'Undirected',
    node_modal_nodes_title_fallback: 'Nodes',
    node_modal_selected_type_nodes_template: '{type} nodes',
  };

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

  function normalizeNodeImageGallery(rawNode) {
    const gallery = [];
    const seen = new Set();
    const addImage = (image, featured = false) => {
      if (!image) return;
      const url = typeof image === 'string' ? image : (image.url || image.src || image.image_url || image.imageUrl || image.thumb_url || image.thumbUrl || '');
      if (!url || seen.has(url)) return;
      seen.add(url);
      gallery.push({
        url,
        thumb_url: typeof image === 'string' ? url : (image.thumb_url || image.thumbUrl || image.thumbnail_url || image.thumbnailUrl || url),
        alt: typeof image === 'string' ? (rawNode.title || rawNode.label || '') : (image.alt || rawNode.title || rawNode.label || ''),
        caption: typeof image === 'string' ? '' : (image.caption || ''),
        caption_html: typeof image === 'string' ? '' : (image.caption_html || image.captionHtml || ''),
        featured: !!featured || !!(typeof image === 'object' && image.featured),
      });
    };

    const sourceGallery = rawNode.image_gallery || rawNode.imageGallery || rawNode.gallery || [];
    if (Array.isArray(sourceGallery) && sourceGallery.length) {
      sourceGallery.forEach((image) => addImage(image, !!(image && image.featured)));
      gallery.sort((a, b) => Number(!!b.featured) - Number(!!a.featured));
      return gallery;
    }
    addImage(rawNode.main_image_url || rawNode.mainImageUrl || rawNode.default_image_url || rawNode.defaultImageUrl || rawNode.image_url || rawNode.imageUrl || rawNode.thumbnail_url || rawNode.thumbnailUrl || '', true);
    const otherImages = rawNode.other_image_urls || rawNode.otherImageUrls || [];
    if (Array.isArray(otherImages)) {
      otherImages.forEach((url) => addImage(url, false));
    }
    gallery.sort((a, b) => Number(!!b.featured) - Number(!!a.featured));
    return gallery;
  }

  function renderGraph(container, data) {
    container.innerHTML = '';
    container.__viswizNodeModalLabels = null;

    const nodes = (data.nodes || []).map((n) => ({
      ...n,
      id: n.id,
      label: n.label || n.title || '',
      title: n.title || n.label || '',
      main_image_url: n.main_image_url || n.mainImageUrl || '',
      default_image_url: n.default_image_url || n.defaultImageUrl || n.image_url || n.imageUrl || n.thumbnail_url || n.thumbnailUrl || '',
      other_image_urls: Array.isArray(n.other_image_urls || n.otherImageUrls) ? (n.other_image_urls || n.otherImageUrls) : [],
      image_gallery: normalizeNodeImageGallery(n),
      description_html: n.description_html || n.descriptionHtml || '',
      custom_labels: n.custom_labels || [],
    }));
    nodes.forEach((node) => {
      node.display_image_url = node.main_image_url || node.default_image_url || (node.image_gallery[0] ? node.image_gallery[0].url : '');
    });

    const nodeIds = new Set(nodes.map((node) => String(node.id)));
    const links = (data.links || []).map((l) => ({
      source: l.from,
      target: l.to,
      label: l.label || '',
      direction: l.direction || 'directed',
      intensity: parseFloat(l.intensity || 1),
      relation_type: l.relation_type || '',
      relation_type_label: l.relation_type_label || l.relationTypeLabel || l.relation_type || '',
    })).map((link) => ({
      ...link,
      relation_filter_key: getRelationFilterKey(link),
      relation_filter_label: getRelationText(link) || link.relation_type_label || link.relation_type || link.label || 'Unlabelled relation',
    })).filter((link) => nodeIds.has(String(link.source)) && nodeIds.has(String(link.target)));

    if (!nodes.length) {
      container.textContent = 'No graph data available.';
      return;
    }

    let width = container.clientWidth || 400;
    const fullscreenActive = document.fullscreenElement === container;
    let height = fullscreenActive ? Math.max(480, (container.clientHeight || window.innerHeight || 720) - 72) : Math.max(360, Math.round(width * 0.62));
    const nodeStyle = container.dataset.nodeStyle || 'card';
    const labelStyle = container.dataset.nodeLabelStyle || 'rounded';
    const nodeRadius = parseInt(container.dataset.nodeRadius, 10) || 20;
    const baseCardWidth = parseInt(container.dataset.nodeCardWidth, 10) || 150;
    const scaleNodesByRelations = container.dataset.scaleNodesByRelations === '1';
    const relationSizeStep = Math.max(0, parseFloat(container.dataset.relationSizeStep || '3') || 0);
    const maxRelationSizeBoost = Math.max(0, parseFloat(container.dataset.maxRelationSizeBoost || '30') || 0);
    const showNodeImages = container.dataset.showNodeImages !== '0';
    const showTypeBadges = container.dataset.showTypeBadges !== '0';
    const showGraphToolbar = container.dataset.showGraphToolbar !== '0';
    const showGraphSearch = container.dataset.showGraphSearch !== '0';
    const showGraphFilters = container.dataset.showGraphFilters !== '0';
    const showGraphZoom = container.dataset.showGraphZoom !== '0';
    const showRelationLabels = container.dataset.showRelationLabels !== '0';
    const graphFilterMode = container.dataset.graphFilterMode === 'hide' ? 'hide' : 'fade';
    const baseLinkDistance = parseInt(container.dataset.linkDistance, 10) || 150;
    const chargeStrength = parseInt(container.dataset.chargeStrength, 10) || -500;
    const relationCountByNode = getRelationCounts(nodes, links);
    const metricOptions = {
      nodeRadius,
      nodeCardWidth: baseCardWidth,
      scaleNodesByRelations,
      relationSizeStep,
      maxRelationSizeBoost,
    };
    nodes.forEach((node) => {
      node.__viswizRelationCount = relationCountByNode.get(String(node.id)) || 0;
      node.__viswizMetrics = getGraphNodeMetrics(node, nodeStyle, metricOptions);
    });
    const maxNodeWidth = nodes.reduce((max, node) => Math.max(max, node.__viswizMetrics.width), nodeStyle === 'round' ? nodeRadius * 2 : baseCardWidth);
    const maxNodeHeight = nodes.reduce((max, node) => Math.max(max, node.__viswizMetrics.height), nodeStyle === 'round' ? nodeRadius * 2 : 112);
    width = Math.max(width, Math.ceil(maxNodeWidth + 32));
    height = Math.max(height, Math.ceil(maxNodeHeight + 32));
    const linkDistance = Math.max(baseLinkDistance, getMaxRelationLabelWidth(links) + maxNodeWidth + 56);
    const nodeColor = getComputedStyle(container).getPropertyValue('--viswiz-primary').trim() || '#4caf50';
    const linkColor = getComputedStyle(container).getPropertyValue('--viswiz-secondary').trim() || '#999';
    const textColor = getComputedStyle(container).getPropertyValue('--viswiz-text').trim() || '#333';
    let activeTypeFilter = null;
    const toolbarFilters = { query: '', nodeTypes: new Set(), nodeSubtypes: new Set(), relationTypes: new Set(), statusEl: null };
    const nodeById = new Map(nodes.map((item) => [String(item.id), item]));

    const graphFrame = document.createElement('div');
    graphFrame.className = 'viswiz-graph-frame';

    const svg = d3
      .create('svg')
      .attr('viewBox', [0, 0, width, height])
      .attr('class', 'viswiz-graph-svg')
      .attr('width', '100%')
      .attr('height', height)
      .attr('role', 'img')
      .attr('aria-label', 'Graph visualization');

    const zoomLayer = svg.append('g').attr('class', 'viswiz-graph-zoom-layer');

    const simulation = d3
      .forceSimulation(nodes)
      .force('link', d3.forceLink(links).id((d) => d.id).distance((d) => Math.max(linkDistance, getRelationLabelWidth(d) + maxNodeWidth + 56)))
      .force('charge', d3.forceManyBody().strength(chargeStrength))
      .force('center', d3.forceCenter(width / 2, height / 2))
      .force('collision', d3.forceCollide().radius((d) => getGraphNodeCollisionRadius(d) + 14));

    const defs = svg.append('defs');
    const markerId = `viswiz-arrowhead-${Math.random().toString(36).slice(2)}`;
    defs
      .append('marker')
      .attr('id', markerId)
      .attr('viewBox', '0 -5 10 10')
      .attr('refX', 9)
      .attr('refY', 0)
      .attr('markerWidth', 7)
      .attr('markerHeight', 7)
      .attr('orient', 'auto-start-reverse')
      .append('path')
      .attr('d', 'M0,-5L10,0L0,5')
      .attr('fill', linkColor);

    const link = zoomLayer
      .append('g')
      .attr('class', 'viswiz-graph-links-g')
      .selectAll('line')
      .data(links)
      .join('line')
      .attr('stroke', linkColor)
      .attr('stroke-width', (d) => Math.max(1, Math.min(8, d.intensity || 1)))
      .attr('marker-start', (d) => d.direction === 'bidirectional' ? `url(#${markerId})` : null)
      .attr('marker-end', (d) => d.direction === 'undirected' ? null : `url(#${markerId})`);

    const linkLabels = zoomLayer
      .append('g')
      .attr('class', 'viswiz-graph-link-labels')
      .selectAll('g')
      .data(links)
      .join('g')
      .style('display', showRelationLabels ? null : 'none');

    if (showRelationLabels) {
      linkLabels.each(function (d) { appendRelationBadge(d3.select(this), d, textColor); });
    }

    const node = zoomLayer
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

    node.append(nodeStyle === 'round' ? 'circle' : 'rect')
      .attr('x', (d) => nodeStyle === 'round' ? null : -d.__viswizMetrics.width / 2)
      .attr('y', (d) => nodeStyle === 'round' ? null : -d.__viswizMetrics.height / 2)
      .attr('r', (d) => nodeStyle === 'round' ? d.__viswizMetrics.radius : null)
      .attr('width', (d) => nodeStyle === 'round' ? null : d.__viswizMetrics.width)
      .attr('height', (d) => nodeStyle === 'round' ? null : d.__viswizMetrics.height)
      .attr('rx', (d) => nodeStyle === 'round' ? null : getGraphNodeCornerRadius(d.__viswizMetrics, labelStyle))
      .attr('fill', nodeStyle === 'round' ? nodeColor : (labelStyle === 'plain' ? 'transparent' : '#fff'))
      .attr('stroke', nodeColor)
      .attr('stroke-width', 2);

    if (nodeStyle === 'card' && showNodeImages) {
      node.filter((d) => !!d.display_image_url)
        .append('image')
        .attr('href', (d) => d.display_image_url)
        .attr('x', (d) => -d.__viswizMetrics.width / 2 + 8)
        .attr('y', (d) => -d.__viswizMetrics.height / 2 + 8)
        .attr('width', (d) => d.__viswizMetrics.width - 16)
        .attr('height', (d) => getGraphCardImageHeight(d))
        .attr('preserveAspectRatio', 'xMidYMid slice');

      node.filter((d) => !d.display_image_url)
        .append('rect')
        .attr('x', (d) => -d.__viswizMetrics.width / 2 + 8)
        .attr('y', (d) => -d.__viswizMetrics.height / 2 + 8)
        .attr('width', (d) => d.__viswizMetrics.width - 16)
        .attr('height', (d) => getGraphCardImageHeight(d))
        .attr('rx', 6)
        .attr('fill', nodeColor)
        .attr('opacity', 0.12);
    }

    if (showTypeBadges) {
      node.each(function (d) {
        appendSvgTypeBadges(d3.select(this), d, d.__viswizMetrics.width / 2 - 12, -d.__viswizMetrics.height / 2 + 14, (filter, key) => {
          activeTypeFilter = activeTypeFilter && activeTypeFilter.filter === filter && activeTypeFilter.key === key ? null : { filter, key };
          applyGraphTypeFilter();
        });
      });
    }

    node.append('text')
      .attr('y', (d) => nodeStyle === 'card' && showNodeImages ? d.__viswizMetrics.height / 2 - 24 : 4)
      .attr('text-anchor', 'middle')
      .attr('font-size', nodeStyle === 'round' ? 11 : 12)
      .attr('font-weight', 700)
      .attr('fill', nodeStyle === 'round' ? '#fff' : textColor)
      .attr('pointer-events', 'none')
      .each(function (d) { wrapSvgText(d3.select(this), d.title || d.label, d.__viswizMetrics.labelWidth, d.__viswizMetrics.maxTitleLines); });

    function nodeMatchesBaseFilters(d) {
      if (activeTypeFilter) {
        const matchesBadge = activeTypeFilter.filter === 'subtype' ? d.node_subtype === activeTypeFilter.key : (d.node_type || d.entity_type) === activeTypeFilter.key;
        if (!matchesBadge) return false;
      }
      if (toolbarFilters.nodeTypes.size && !toolbarFilters.nodeTypes.has(String(d.node_type || d.entity_type || ''))) return false;
      if (toolbarFilters.nodeSubtypes.size && !toolbarFilters.nodeSubtypes.has(String(d.node_subtype || ''))) return false;
      if (toolbarFilters.query) {
        const haystack = getNodeSearchText(d);
        if (!haystack.includes(toolbarFilters.query)) return false;
      }
      return true;
    }

    function relationMatchesFilters(d) {
      return !toolbarFilters.relationTypes.size || toolbarFilters.relationTypes.has(String(d.relation_filter_key || getRelationFilterKey(d)));
    }

    function nodeMatchesFilter(d) {
      if (!nodeMatchesBaseFilters(d)) return false;
      if (!toolbarFilters.relationTypes.size) return true;
      return links.some((candidate) => {
        if (!relationMatchesFilters(candidate)) return false;
        return getLinkSourceId(candidate) === String(d.id) || getLinkTargetId(candidate) === String(d.id);
      });
    }

    function linkMatchesFilter(d) {
      const sourceNode = getLinkNode(d.source, nodeById);
      const targetNode = getLinkNode(d.target, nodeById);
      return !!sourceNode && !!targetNode && relationMatchesFilters(d) && nodeMatchesFilter(sourceNode) && nodeMatchesFilter(targetNode);
    }

    function applyGraphTypeFilter() {
      const visibleNodes = nodes.filter(nodeMatchesFilter).length;
      const visibleLinks = links.filter(linkMatchesFilter).length;
      const hide = graphFilterMode === 'hide';
      node
        .attr('opacity', (d) => nodeMatchesFilter(d) ? 1 : (hide ? 0 : 0.22))
        .style('display', (d) => hide && !nodeMatchesFilter(d) ? 'none' : null)
        .style('pointer-events', (d) => nodeMatchesFilter(d) ? null : 'none')
        .style('filter', (d) => nodeMatchesFilter(d) ? null : 'grayscale(1)');
      link
        .attr('opacity', (d) => linkMatchesFilter(d) ? 0.6 : (hide ? 0 : 0.12))
        .style('display', (d) => hide && !linkMatchesFilter(d) ? 'none' : null);
      linkLabels
        .attr('opacity', (d) => showRelationLabels && linkMatchesFilter(d) ? 1 : (hide || !showRelationLabels ? 0 : 0.18))
        .style('display', (d) => !showRelationLabels || (hide && !linkMatchesFilter(d)) ? 'none' : null);
      if (toolbarFilters.statusEl) {
        toolbarFilters.statusEl.textContent = `${visibleNodes} of ${nodes.length} nodes · ${visibleLinks} of ${links.length} relations`;
      }
    }

    simulation.on('tick', () => {
      nodes.forEach((d) => {
        const boundsX = getGraphNodeWidth(d) / 2 + 4;
        const boundsY = getGraphNodeHeight(d) / 2 + 4;
        d.x = Math.max(boundsX, Math.min(width - boundsX, d.x));
        d.y = Math.max(boundsY, Math.min(height - boundsY, d.y));
      });

      link
        .attr('x1', (d) => edgePoint(d.source, d.target, getGraphNodeWidth(d.source), getGraphNodeHeight(d.source)).x)
        .attr('y1', (d) => edgePoint(d.source, d.target, getGraphNodeWidth(d.source), getGraphNodeHeight(d.source)).y)
        .attr('x2', (d) => edgePoint(d.target, d.source, getGraphNodeWidth(d.target), getGraphNodeHeight(d.target)).x)
        .attr('y2', (d) => edgePoint(d.target, d.source, getGraphNodeWidth(d.target), getGraphNodeHeight(d.target)).y);

      linkLabels
        .attr('transform', (d) => relationBadgeTransform(d));

      node.attr('transform', (d) => `translate(${d.x},${d.y})`);
    });

    graphFrame.appendChild(svg.node());
    if (showGraphZoom) {
      const zoomControls = buildGraphZoomControls();
      graphFrame.appendChild(zoomControls);
      container.appendChild(graphFrame);

      const zoom = d3.zoom()
        .scaleExtent([0.35, 3])
        .on('zoom', (event) => zoomLayer.attr('transform', event.transform));
      svg.call(zoom);
      zoomControls.querySelector('[data-viswiz-zoom="in"]').addEventListener('click', () => svg.transition().duration(160).call(zoom.scaleBy, 1.2));
      zoomControls.querySelector('[data-viswiz-zoom="out"]').addEventListener('click', () => svg.transition().duration(160).call(zoom.scaleBy, 1 / 1.2));
    } else {
      container.appendChild(graphFrame);
      const zoom = d3.zoom()
        .scaleExtent([0.35, 3])
        .on('zoom', (event) => zoomLayer.attr('transform', event.transform));
      svg.call(zoom);
    }

    if (showGraphToolbar && (showGraphSearch || showGraphFilters)) {
      const toolbar = buildGraphExplorerToolbar(container, nodes, links, toolbarFilters, applyGraphTypeFilter);
      container.insertBefore(toolbar, graphFrame);
    }
    applyGraphTypeFilter();


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


  function getRelationCounts(nodes, links) {
    const counts = new Map(nodes.map((node) => [String(node.id), 0]));
    (links || []).forEach((link) => {
      const sourceId = getLinkSourceId(link);
      const targetId = getLinkTargetId(link);
      if (counts.has(sourceId)) counts.set(sourceId, (counts.get(sourceId) || 0) + 1);
      if (targetId && targetId !== sourceId && counts.has(targetId)) counts.set(targetId, (counts.get(targetId) || 0) + 1);
    });
    return counts;
  }

  function getGraphNodeRelationBoost(node, options) {
    if (!options.scaleNodesByRelations) return 0;
    const relationCount = parseInt(node.__viswizRelationCount || 0, 10) || 0;
    return Math.min(options.maxRelationSizeBoost || 0, relationCount * (options.relationSizeStep || 0));
  }

  function getGraphNodeMetrics(node, nodeStyle, options) {
    const title = node.title || node.label || '';
    const boost = getGraphNodeRelationBoost(node, options);
    if (nodeStyle === 'round') {
      let radius = Math.max(10, (options.nodeRadius || 20) + boost);
      let lines = [''];
      for (let iteration = 0; iteration < 10; iteration += 1) {
        const labelWidth = Math.max(24, radius * 1.72);
        const maxChars = Math.max(6, Math.floor(labelWidth / 6.2));
        lines = wrapTextToLines(title, maxChars, Infinity);
        const maxLineChars = Math.max(1, ...lines.map((line) => String(line).length));
        const neededRadius = Math.ceil(Math.max(
          (options.nodeRadius || 20) + boost,
          (maxLineChars * 6.2) / 2 + 12,
          (lines.length * 13.2) / 2 + 12
        ));
        if (neededRadius <= radius + 1) break;
        radius = neededRadius;
      }
      return {
        radius,
        width: radius * 2,
        height: radius * 2,
        labelWidth: Math.max(24, radius * 1.72),
        maxTitleLines: Infinity,
      };
    }

    if (nodeStyle === 'compact') {
      const baseWidth = Math.max(90, (options.nodeRadius || 20) * 3.4);
      let width = baseWidth;
      let lines = [''];
      for (let iteration = 0; iteration < 6; iteration += 1) {
        const maxChars = Math.max(8, Math.floor((width - 24) / 6.2));
        lines = wrapTextToLines(title, maxChars, Infinity);
        const maxLineChars = Math.max(1, ...lines.map((line) => String(line).length));
        const neededWidth = Math.max(baseWidth, Math.ceil(maxLineChars * 6.2 + 28));
        if (neededWidth <= width + 1) break;
        width = neededWidth;
      }
      const height = Math.max(46, lines.length * 14 + 22);
      return {
        radius: Math.hypot(width, height) / 2,
        width,
        height,
        labelWidth: Math.max(24, width - 18),
        maxTitleLines: Infinity,
      };
    }

    const cardBoost = boost;
    const width = Math.max(90, (options.nodeCardWidth || 150) + cardBoost * 2);
    const height = Math.max(112, 112 + Math.round(cardBoost * 1.35));
    return {
      radius: Math.hypot(width, height) / 2,
      width,
      height,
      labelWidth: Math.max(24, width - 18),
      maxTitleLines: 2,
    };
  }

  function getGraphNodeWidth(node) {
    return node && node.__viswizMetrics ? node.__viswizMetrics.width : 80;
  }

  function getGraphNodeHeight(node) {
    return node && node.__viswizMetrics ? node.__viswizMetrics.height : 46;
  }

  function getGraphNodeCollisionRadius(node) {
    return node && node.__viswizMetrics ? node.__viswizMetrics.radius : 28;
  }

  function getGraphNodeCornerRadius(metrics, labelStyle) {
    if (labelStyle === 'plain') return 0;
    if (labelStyle === 'pill') return Math.max(0, metrics.height / 2);
    return 10;
  }

  function getGraphCardImageHeight(node) {
    const metrics = node.__viswizMetrics || { height: 112 };
    return Math.min(96, Math.max(64, metrics.height - 48));
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
    const estimatedCharWidth = 6.2;
    const maxChars = Math.max(4, Math.floor(width / estimatedCharWidth));
    const lines = wrapTextToLines(value, maxChars, maxLines);
    const y = parseFloat(text.attr('y')) || 0;

    text.text(null);
    lines.forEach((line, index) => {
      text.append('tspan')
        .attr('x', 0)
        .attr('y', y)
        .attr('dy', `${(index - (lines.length - 1) / 2) * 1.05}em`)
        .text(line);
    });
  }


  function getRelationText(link) {
    return [link.label, link.relation_type_label || link.relation_type].filter(Boolean).join(' · ');
  }

  function getRelationLabelWidth(link) {
    const text = getRelationText(link);
    return Math.min(180, Math.max(64, text.length * 5.8 + 24));
  }

  function getMaxRelationLabelWidth(links) {
    return (links || []).reduce((max, link) => Math.max(max, getRelationLabelWidth(link)), 64);
  }

  function appendRelationBadge(group, link, textColor) {
    const label = getRelationText(link);
    if (!label) {
      group.attr('display', 'none');
      return;
    }
    const width = getRelationLabelWidth(link);
    const lines = wrapTextToLines(label, Math.max(10, Math.floor((width - 18) / 5.8)), 3);
    const height = Math.max(24, lines.length * 13 + 10);

    group.attr('class', 'viswiz-graph-link-badge');
    group.append('rect')
      .attr('x', -width / 2)
      .attr('y', -height / 2)
      .attr('width', width)
      .attr('height', height)
      .attr('rx', height / 2)
      .attr('ry', height / 2);

    const text = group.append('text')
      .attr('text-anchor', 'middle')
      .attr('font-size', 10)
      .attr('font-weight', 600)
      .attr('fill', textColor)
      .attr('dominant-baseline', 'middle');

    lines.forEach((line, index) => {
      text.append('tspan')
        .attr('x', 0)
        .attr('dy', index === 0 ? `${-(lines.length - 1) * 0.55}em` : '1.1em')
        .text(line);
    });
  }

  function relationBadgeTransform(link) {
    const x = (link.source.x + link.target.x) / 2;
    const y = (link.source.y + link.target.y) / 2;
    const angle = Math.atan2(link.target.y - link.source.y, link.target.x - link.source.x) * 180 / Math.PI;
    return `translate(${x},${y}) rotate(${angle})`;
  }

  function wrapTextToLines(value, maxChars, maxLines) {
    const words = String(value || '').split(/\s+/).filter(Boolean);
    const lines = [];
    let current = '';
    words.forEach((word) => {
      const parts = word.length > maxChars ? word.match(new RegExp(`.{1,${maxChars}}`, 'g')) : [word];
      parts.forEach((part) => {
        const next = current ? `${current} ${part}` : part;
        if (next.length > maxChars && current) {
          lines.push(current);
          current = part;
        } else {
          current = next;
        }
      });
    });
    if (current) lines.push(current);
    if (maxLines === Infinity) {
      return lines.length ? lines : [''];
    }
    const trimmed = lines.slice(0, maxLines);
    if (lines.length > maxLines && trimmed.length) {
      trimmed[trimmed.length - 1] = `${trimmed[trimmed.length - 1].replace(/…$/, '')}…`;
    }
    return trimmed.length ? trimmed : [''];
  }

  function buildGraphZoomControls() {
    const controls = document.createElement('div');
    controls.className = 'viswiz-graph-zoom-controls';
    [
      { action: 'in', label: '+', aria: 'Zoom in' },
      { action: 'out', label: '−', aria: 'Zoom out' },
    ].forEach((control) => {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.viswizZoom = control.action;
      button.textContent = control.label;
      button.setAttribute('aria-label', control.aria);
      controls.appendChild(button);
    });
    return controls;
  }


  function getLinkSourceId(link) {
    return String(typeof link.source === 'object' && link.source ? link.source.id : link.source || '');
  }

  function getLinkTargetId(link) {
    return String(typeof link.target === 'object' && link.target ? link.target.id : link.target || '');
  }

  function getLinkNode(value, nodeById) {
    if (typeof value === 'object' && value) return value;
    return nodeById.get(String(value || '')) || null;
  }

  function getRelationFilterKey(link) {
    return String(link.relation_type || link.relation_type_label || link.label || 'unlabelled');
  }

  function getNodeSearchText(node) {
    const custom = Array.isArray(node.custom_labels) ? node.custom_labels.map((item) => `${item.key || ''} ${item.value || ''}`).join(' ') : '';
    return [
      node.id,
      node.label,
      node.title,
      node.description,
      node.node_type,
      node.node_type_label,
      node.entity_type,
      node.entity_type_label,
      node.node_subtype,
      node.node_subtype_label,
      custom,
    ].filter(Boolean).join(' ').toLowerCase();
  }

  function uniqueFilterItems(items) {
    const map = new Map();
    items.forEach((item) => {
      if (!item || !item.key) return;
      if (!map.has(String(item.key))) map.set(String(item.key), { key: String(item.key), label: item.label || item.key });
    });
    return Array.from(map.values()).sort((a, b) => String(a.label).localeCompare(String(b.label)));
  }

  function buildGraphExplorerToolbar(container, nodes, links, state, onChange) {
    const toolbar = document.createElement('div');
    toolbar.className = 'viswiz-graph-toolbar';
    toolbar.setAttribute('aria-label', 'Graph exploration tools');

    const showSearch = container.dataset.showGraphSearch !== '0';
    const showFilters = container.dataset.showGraphFilters !== '0';
    if (showSearch) {
      const searchWrap = document.createElement('label');
      searchWrap.className = 'viswiz-graph-toolbar-search';
      const label = document.createElement('span');
      label.textContent = 'Search graph';
      const input = document.createElement('input');
      input.type = 'search';
      input.placeholder = 'Search nodes…';
      input.addEventListener('input', () => {
        state.query = input.value.trim().toLowerCase();
        onChange();
      });
      searchWrap.appendChild(label);
      searchWrap.appendChild(input);
      toolbar.appendChild(searchWrap);
    }

    if (showFilters) {
      const nodeTypes = uniqueFilterItems(nodes.map((node) => ({ key: node.node_type || node.entity_type, label: node.node_type_label || node.entity_type_label || node.node_type || node.entity_type })));
      const nodeSubtypes = uniqueFilterItems(nodes.map((node) => ({ key: node.node_subtype, label: node.node_subtype_label || node.node_subtype })));
      const relationTypes = uniqueFilterItems(links.map((link) => ({ key: link.relation_filter_key || getRelationFilterKey(link), label: link.relation_filter_label || getRelationText(link) || link.relation_type_label || link.relation_type || link.label })));
      toolbar.appendChild(buildCheckboxFilterGroup('Node types', nodeTypes, state.nodeTypes, onChange));
      toolbar.appendChild(buildCheckboxFilterGroup('Subtypes', nodeSubtypes, state.nodeSubtypes, onChange));
      toolbar.appendChild(buildCheckboxFilterGroup('Relations', relationTypes, state.relationTypes, onChange));
    }

    const actions = document.createElement('div');
    actions.className = 'viswiz-graph-toolbar-actions';
    const reset = document.createElement('button');
    reset.type = 'button';
    reset.textContent = 'Reset filters';
    reset.addEventListener('click', () => {
      state.query = '';
      state.nodeTypes.clear();
      state.nodeSubtypes.clear();
      state.relationTypes.clear();
      toolbar.querySelectorAll('input[type="checkbox"]').forEach((input) => { input.checked = false; });
      toolbar.querySelectorAll('input[type="search"]').forEach((input) => { input.value = ''; });
      onChange();
    });
    const status = document.createElement('span');
    status.className = 'viswiz-graph-toolbar-status';
    status.setAttribute('aria-live', 'polite');
    state.statusEl = status;
    actions.appendChild(reset);
    actions.appendChild(status);
    toolbar.appendChild(actions);
    return toolbar;
  }

  function buildCheckboxFilterGroup(title, items, targetSet, onChange) {
    const group = document.createElement('details');
    group.className = 'viswiz-graph-filter-group';
    const summary = document.createElement('summary');
    summary.textContent = `${title}${items.length ? ` (${items.length})` : ''}`;
    group.appendChild(summary);
    const list = document.createElement('div');
    list.className = 'viswiz-graph-filter-options';
    if (!items.length) {
      const empty = document.createElement('span');
      empty.className = 'viswiz-graph-filter-empty';
      empty.textContent = 'No values';
      list.appendChild(empty);
    }
    items.forEach((item) => {
      const label = document.createElement('label');
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.value = item.key;
      input.addEventListener('change', () => {
        if (input.checked) targetSet.add(item.key);
        else targetSet.delete(item.key);
        onChange();
      });
      label.appendChild(input);
      label.appendChild(document.createTextNode(` ${item.label}`));
      list.appendChild(label);
    });
    group.appendChild(list);
    return group;
  }

  function getNodeModalLabels(container) {
    if (container && container.__viswizNodeModalLabels) return container.__viswizNodeModalLabels;
    let configured = {};
    if (container && container.dataset && container.dataset.colors) {
      try {
        configured = JSON.parse(container.dataset.colors) || {};
      } catch (error) {
        configured = {};
      }
    }
    if (container && container.dataset && container.dataset.nodeModalLabels) {
      try {
        configured = { ...configured, ...(JSON.parse(container.dataset.nodeModalLabels) || {}) };
      } catch (error) {
        // Keep labels parsed from data-colors.
      }
    }
    const labels = { ...DEFAULT_NODE_MODAL_LABELS };
    Object.keys(DEFAULT_NODE_MODAL_LABELS).forEach((key) => {
      if (configured[key] !== undefined && configured[key] !== null && String(configured[key]).trim() !== '') {
        labels[key] = String(configured[key]);
      }
    });
    if (container) container.__viswizNodeModalLabels = labels;
    return labels;
  }

  function applyNodeModalLabelTemplate(template, replacements = {}) {
    return String(template || '').replace(/\{([a-zA-Z0-9_]+)\}/g, (match, key) => {
      return Object.prototype.hasOwnProperty.call(replacements, key) ? replacements[key] : match;
    });
  }

  function showNodeDetails(container, node, nodes = [], links = []) {
    const labels = getNodeModalLabels(container);
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
    close.setAttribute('aria-label', labels.node_modal_close_label);
    card.appendChild(close);

    const galleryItems = Array.isArray(node.image_gallery) ? node.image_gallery : [];
    const gallery = buildNodeDetailGallery(node, galleryItems, labels);
    appendHtmlTypeBadges(gallery.frame, node, container, nodes, links);
    card.appendChild(gallery.frame);

    const title = document.createElement('h3');
    const titleLink = createNodeLink(container, node, nodes, links);
    titleLink.textContent = node.title || node.label || labels.node_modal_title_fallback;
    title.appendChild(titleLink);
    card.appendChild(title);
    const details = document.createElement('dl');
    details.className = 'viswiz-node-detail-list';
    if (node.node_subtype === 'proposed') {
      appendDetail(details, labels.node_modal_proposed_subtype_reason, node.proposed_subtype_reason, 'long');
      appendDetail(details, labels.node_modal_example_entity, node.proposed_subtype_example);
      appendDetail(details, labels.node_modal_proposed_subtype_gap, node.proposed_subtype_gap, 'long');
      appendDetail(details, labels.node_modal_proposal_status, node.proposed_subtype_status);
    }
    appendNodeDescription(card, node.description_html || node.description, !!node.description_html);
    (node.custom_labels || []).forEach((item) => appendDetail(details, item.key || labels.node_modal_custom_field, item.value, item.type));
    if (details.childElementCount) {
      card.appendChild(details);
    }
    appendRelatedNodes(card, container, node, nodes, links, labels);
    overlay.appendChild(card);
    const removeOverlay = () => {
      overlay.remove();
      document.removeEventListener('keydown', onModalKeydown);
    };
    const onModalKeydown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        removeOverlay();
      }
    };
    close.addEventListener('click', removeOverlay);
    overlay.addEventListener('click', (event) => { if (event.target === overlay) removeOverlay(); });
    document.addEventListener('keydown', onModalKeydown);
    container.appendChild(overlay);
    close.focus();
  }

  function appendNodeDescription(card, value, isFormatted = false) {
    if (value === undefined || value === null || value === '') return;
    const description = document.createElement('div');
    description.className = 'viswiz-node-detail-description viswiz-node-detail-formatted';
    if (isFormatted) {
      description.innerHTML = value;
    } else {
      description.textContent = value;
    }
    card.appendChild(description);
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
      dd.classList.add('viswiz-node-detail-formatted');
      dd.innerHTML = value;
    } else {
      dd.textContent = value;
    }
    list.appendChild(dt);
    list.appendChild(dd);
  }


  function buildNodeDetailGallery(node, galleryItems, labels = DEFAULT_NODE_MODAL_LABELS) {
    let activeIndex = 0;
    const frame = document.createElement('div');
    frame.className = 'viswiz-graph-node-modal-image-frame viswiz-graph-node-gallery-frame';

    const image = document.createElement('img');
    image.className = 'viswiz-graph-node-modal-image';

    const placeholder = document.createElement('div');
    placeholder.className = 'viswiz-graph-node-modal-image-placeholder';

    const counter = document.createElement('div');
    counter.className = 'viswiz-graph-node-gallery-counter';

    const caption = document.createElement('div');
    caption.className = 'viswiz-graph-node-gallery-caption';

    const update = () => {
      const item = galleryItems[activeIndex];
      if (!item || !item.url) {
        if (image.parentNode) image.remove();
        if (!placeholder.parentNode) frame.appendChild(placeholder);
        counter.hidden = true;
        caption.hidden = true;
        return;
      }
      if (placeholder.parentNode) placeholder.remove();
      if (!image.parentNode) frame.insertBefore(image, frame.firstChild);
      image.src = item.url;
      image.alt = item.alt || node.title || node.label || '';
      caption.innerHTML = '';
      if (item.featured) {
        const badge = document.createElement('span');
        badge.className = 'viswiz-graph-node-gallery-featured-label';
        badge.textContent = labels.node_modal_featured_image_label;
        caption.appendChild(badge);
      }
      const captionHtml = item.caption_html || item.captionHtml || '';
      const captionText = item.caption || '';
      if (captionHtml || captionText) {
        const captionBody = document.createElement('span');
        captionBody.className = 'viswiz-graph-node-gallery-caption-text';
        if (captionHtml) {
          captionBody.innerHTML = captionHtml;
        } else {
          captionBody.textContent = captionText;
        }
        caption.appendChild(captionBody);
      }
      caption.hidden = !caption.childElementCount;
      counter.textContent = `${activeIndex + 1} / ${galleryItems.length}`;
      counter.hidden = galleryItems.length < 2;
    };

    if (galleryItems.length > 1) {
      const makeButton = (direction, label) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `viswiz-graph-node-gallery-nav viswiz-graph-node-gallery-nav--${direction}`;
        button.setAttribute('aria-label', label);
        button.textContent = direction === 'prev' ? '‹' : '›';
        button.addEventListener('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          activeIndex = direction === 'prev'
            ? (activeIndex - 1 + galleryItems.length) % galleryItems.length
            : (activeIndex + 1) % galleryItems.length;
          update();
        });
        frame.appendChild(button);
      };
      makeButton('prev', labels.node_modal_previous_image_label);
      makeButton('next', labels.node_modal_next_image_label);
    }

    frame.appendChild(counter);
    frame.appendChild(caption);
    update();
    return { frame };
  }

  function createNodeLink(container, node, nodes, links) {
    const labels = getNodeModalLabels(container);
    const link = document.createElement('a');
    link.href = `#${encodeURIComponent(node.id || node.title || node.label || 'node')}`;
    link.textContent = node.title || node.label || labels.node_modal_title_fallback;
    link.addEventListener('click', (event) => {
      event.preventDefault();
      showNodeDetails(container, node, nodes, links);
    });
    return link;
  }

  function getNodeTypeBadges(node) {
    return [
      {
        filter: 'type',
        key: node.node_type || node.entity_type,
        label: node.node_type_label || node.entity_type_label || node.node_type || node.entity_type,
        className: 'viswiz-node-type-badge--type',
      },
      {
        filter: 'subtype',
        key: node.node_subtype,
        label: node.node_subtype_label || node.node_subtype,
        className: 'viswiz-node-type-badge--subtype',
      },
    ].filter((badge) => badge.key && badge.label);
  }

  function appendSvgTypeBadges(group, node, rightX, topY, onSelect) {
    getNodeTypeBadges(node).forEach((badge, index) => {
      const width = Math.min(118, Math.max(44, String(badge.label).length * 6 + 14));
      const badgeGroup = group.append('g')
        .attr('class', `viswiz-node-svg-type-badge ${badge.className}`)
        .attr('role', 'link')
        .attr('tabindex', 0)
        .attr('transform', `translate(${rightX - width},${topY + index * 18})`)
        .on('click', (event) => {
          event.preventDefault();
          event.stopPropagation();
          onSelect(badge.filter, badge.key);
        })
        .on('keydown', (event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            event.stopPropagation();
            onSelect(badge.filter, badge.key);
          }
        });
      badgeGroup.append('rect').attr('width', width).attr('height', 15).attr('rx', 7.5);
      badgeGroup.append('text').attr('x', width / 2).attr('y', 10.5).text(badge.label);
    });
  }

  function appendHtmlTypeBadges(wrapper, node, container, nodes, links) {
    const badges = getNodeTypeBadges(node);
    if (!badges.length) return;
    const list = document.createElement('div');
    list.className = 'viswiz-node-type-badges';
    badges.forEach((badge) => {
      const link = document.createElement('a');
      link.href = `#${badge.filter}-${encodeURIComponent(badge.key)}`;
      link.className = `viswiz-node-type-badge ${badge.className}`;
      link.textContent = badge.label;
      link.addEventListener('click', (event) => {
        event.preventDefault();
        showTypeNodes(container, badge.filter, badge.key, badge.label, nodes, links);
      });
      list.appendChild(link);
    });
    wrapper.appendChild(list);
  }

  function getRelatedNodes(node, nodes, links, labels = DEFAULT_NODE_MODAL_LABELS) {
    return links.reduce((related, link) => {
      const sourceId = getLinkSourceId(link);
      const targetId = getLinkTargetId(link);
      const currentId = String(node.id || '');
      const isSource = sourceId === currentId;
      const isTarget = targetId === currentId;
      if (!isSource && !isTarget) return related;
      const relatedId = isSource ? targetId : sourceId;
      const relatedNode = nodes.find((candidate) => String(candidate.id) === String(relatedId));
      if (!relatedNode) return related;
      const relation = getRelationText(link) || (isSource ? labels.node_modal_outgoing_relation : labels.node_modal_incoming_relation);
      const direction = link.direction === 'undirected' ? labels.node_modal_direction_undirected : (link.direction === 'bidirectional' ? labels.node_modal_direction_bidirectional : (isSource ? labels.node_modal_direction_outgoing : labels.node_modal_direction_incoming));
      related.push({
        node: relatedNode,
        relation,
        relationGroup: relation,
        direction,
      });
      return related;
    }, []);
  }

  function appendRelatedNodes(card, container, node, nodes, links, labels = DEFAULT_NODE_MODAL_LABELS) {
    const related = getRelatedNodes(node, nodes, links, labels);
    if (!related.length) return;
    const section = document.createElement('section');
    section.className = 'viswiz-related-nodes';
    const heading = document.createElement('h4');
    heading.textContent = labels.node_modal_related_heading;
    section.appendChild(heading);
    const groups = related.reduce((map, item) => {
      const key = item.relationGroup || labels.node_modal_relation_fallback;
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(item);
      return map;
    }, new Map());
    groups.forEach((items, relationLabel) => {
      const group = document.createElement('section');
      group.className = 'viswiz-related-node-group';
      const groupHeading = document.createElement('h5');
      groupHeading.textContent = relationLabel;
      group.appendChild(groupHeading);
      const grid = document.createElement('div');
      grid.className = 'viswiz-related-node-grid';
      items.forEach((item) => grid.appendChild(createNodePreviewCard(container, item.node, nodes, links, item.direction)));
      group.appendChild(grid);
      section.appendChild(group);
    });
    card.appendChild(section);
  }

  function createNodePreviewCard(container, node, nodes, links, relationLabel = '') {
    const item = document.createElement('article');
    item.className = 'viswiz-related-node-card';
    const media = document.createElement('div');
    media.className = 'viswiz-related-node-media';
    if (node.display_image_url) {
      const image = document.createElement('img');
      image.src = node.display_image_url;
      image.alt = node.title || node.label || '';
      media.appendChild(image);
    } else {
      const placeholder = document.createElement('div');
      placeholder.className = 'viswiz-related-node-placeholder';
      media.appendChild(placeholder);
    }
    appendHtmlTypeBadges(media, node, container, nodes, links);
    item.appendChild(media);
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
    const labels = getNodeModalLabels(container);
    const matching = nodes.filter((node) => filter === 'subtype' ? node.node_subtype === key : (node.node_type || node.entity_type) === key);
    const pseudoNode = { title: label || labels.node_modal_nodes_title_fallback };
    showNodeDetails(container, pseudoNode, nodes, links);
    const card = container.querySelector('.viswiz-graph-node-modal-card');
    if (!card) return;
    card.querySelectorAll('.viswiz-node-detail-list, .viswiz-graph-node-modal-image-frame, .viswiz-graph-node-modal-gallery').forEach((el) => el.remove());
    const heading = card.querySelector('h3');
    if (heading) heading.textContent = applyNodeModalLabelTemplate(labels.node_modal_selected_type_nodes_template, { type: label || labels.node_modal_nodes_title_fallback });
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
        addFullscreenToggle(container);
      })
      .catch(() => {
        container.textContent = 'Unable to load sales data.';
        addFullscreenToggle(container);
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
          addFullscreenToggle(container);
        })
        .catch(() => {
          container.textContent = 'Unable to load sales breakdown.';
          addFullscreenToggle(container);
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
        addFullscreenToggle(container);
      })
      .catch(() => {
        container.textContent = 'Unable to load sales breakdown.';
        addFullscreenToggle(container);
      });
  }

  function loadManualProgress(container, index) {
    const manualOverride = getManualData(container);
    if (manualOverride) {
      if (Array.isArray(manualOverride)) {
        renderProgressList(container, manualOverride, container.dataset.label || 'Manual Progress');
        addFullscreenToggle(container);
        return;
      }
      renderProgress(container, {
        label: manualOverride.label || container.dataset.label || 'Manual Progress',
        value: parseFloat(manualOverride.value || 0),
        target: parseFloat(manualOverride.target || 0),
        targets: manualOverride.targets || [],
      });
      addFullscreenToggle(container);
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
      addFullscreenToggle(container);
    } else {
      container.textContent = 'No manual progress data available.';
      addFullscreenToggle(container);
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
    addFullscreenToggle(container);
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
      addFullscreenToggle(container);
    });
  }

  function initGraph() {
    document.querySelectorAll('.viswiz-graph').forEach((container) => {
      applyFormatting(container);
      const manual = getManualData(container) || VisWizData.graphData || {};
      container.__viswizRerender = () => {
        applyFormatting(container);
        renderGraph(container, manual);
        addFullscreenToggle(container);
      };
      renderGraph(container, manual);
      addFullscreenToggle(container);
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


  function addFullscreenToggle(container) {
    if (!container || container.dataset.showFullscreenToggle === '0' || !container.requestFullscreen) {
      return;
    }
    container.classList.add('viswiz-fullscreen-enabled');
    const directChildren = Array.from(container.children || []);
    let button = directChildren.find((child) => child.classList && child.classList.contains('viswiz-fullscreen-toggle'));
    if (!button) {
      button = document.createElement('button');
      button.type = 'button';
      button.className = 'viswiz-fullscreen-toggle';
      button.addEventListener('click', () => {
        if (document.fullscreenElement === container) {
          document.exitFullscreen();
        } else {
          container.requestFullscreen();
        }
      });
      container.appendChild(button);
    }
    updateFullscreenButton(container);
    if (!container.__viswizFullscreenListener) {
      container.__viswizWasFullscreen = document.fullscreenElement === container;
      container.__viswizFullscreenListener = () => {
        const isActive = document.fullscreenElement === container;
        const wasActive = !!container.__viswizWasFullscreen;
        container.__viswizWasFullscreen = isActive;
        updateFullscreenButton(container);
        if ((isActive || wasActive) && container.classList.contains('viswiz-graph') && typeof container.__viswizRerender === 'function') {
          window.requestAnimationFrame(container.__viswizRerender);
        }
      };
      document.addEventListener('fullscreenchange', container.__viswizFullscreenListener);
    }
  }

  function updateFullscreenButton(container) {
    const button = Array.from(container.children || []).find((child) => child.classList && child.classList.contains('viswiz-fullscreen-toggle'));
    const isActive = document.fullscreenElement === container;
    container.classList.toggle('is-viswiz-fullscreen', isActive);
    if (!button) return;
    button.textContent = isActive ? 'Exit full screen' : 'Full screen';
    button.setAttribute('aria-label', isActive ? 'Exit full screen' : 'View full screen');
    button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
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

  window.VisWiz = {
    ...(window.VisWiz || {}),
    renderGraph,
    renderProgress,
    renderPie,
    renderDiagram,
    applyFormatting,
  };

  document.addEventListener('DOMContentLoaded', () => {
    if (!window.VisWizData || window.VisWizData.skipAutoInit) {
      return;
    }
    initProgress();
    initPie();
    initDiagram();
    initGraph();
  });
})();
