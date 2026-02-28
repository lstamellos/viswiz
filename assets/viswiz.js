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

    const nodes = (data.nodes || []).map((n) => ({ id: n.id, label: n.label || n.id }));
    const links = (data.links || []).map((l) => ({ source: l.from, target: l.to, label: l.label || '' }));

    if (!nodes.length) {
      container.textContent = 'No graph data available.';
      return;
    }

    const width = container.clientWidth || 400;
    const height = 300;
    const nodeRadius = parseInt(container.dataset.nodeRadius, 10) || 20;
    const linkDistance = parseInt(container.dataset.linkDistance, 10) || 100;
    const chargeStrength = parseInt(container.dataset.chargeStrength, 10) || -300;
    const nodeColor = getComputedStyle(container).getPropertyValue('--viswiz-primary').trim() || '#4caf50';
    const linkColor = getComputedStyle(container).getPropertyValue('--viswiz-secondary').trim() || '#999';
    const textColor = getComputedStyle(container).getPropertyValue('--viswiz-text').trim() || '#333';

    const svg = d3
      .create('svg')
      .attr('viewBox', [0, 0, width, height])
      .attr('class', 'viswiz-graph-svg')
      .attr('width', '100%')
      .attr('height', height);

    const simulation = d3
      .forceSimulation(nodes)
      .force('link', d3.forceLink(links).id((d) => d.id).distance(linkDistance))
      .force('charge', d3.forceManyBody().strength(chargeStrength))
      .force('center', d3.forceCenter(width / 2, height / 2))
      .force('collision', d3.forceCollide().radius(nodeRadius + 10));

    const defs = svg.append('defs');
    defs
      .append('marker')
      .attr('id', 'viswiz-arrowhead')
      .attr('viewBox', '0 -5 10 10')
      .attr('refX', 20)
      .attr('refY', 0)
      .attr('markerWidth', 6)
      .attr('markerHeight', 6)
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
      .attr('stroke-width', 2)
      .attr('marker-end', 'url(#viswiz-arrowhead)');

    const linkLabels = svg
      .append('g')
      .attr('class', 'viswiz-graph-link-labels')
      .selectAll('text')
      .data(links)
      .join('text')
      .attr('font-size', 10)
      .attr('fill', textColor)
      .attr('text-anchor', 'middle')
      .text((d) => d.label);

    const node = svg
      .append('g')
      .attr('class', 'viswiz-graph-nodes')
      .selectAll('g')
      .data(nodes)
      .join('g')
      .call(drag(simulation));

    node
      .append('circle')
      .attr('r', nodeRadius)
      .attr('fill', nodeColor)
      .attr('stroke', '#fff')
      .attr('stroke-width', 2);

    node
      .append('text')
      .attr('dy', 4)
      .attr('text-anchor', 'middle')
      .attr('font-size', 11)
      .attr('fill', '#fff')
      .attr('pointer-events', 'none')
      .text((d) => d.label);

    simulation.on('tick', () => {
      link
        .attr('x1', (d) => d.source.x)
        .attr('y1', (d) => d.source.y)
        .attr('x2', (d) => d.target.x)
        .attr('y2', (d) => d.target.y);

      linkLabels
        .attr('x', (d) => (d.source.x + d.target.x) / 2)
        .attr('y', (d) => (d.source.y + d.target.y) / 2 - 5);

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
