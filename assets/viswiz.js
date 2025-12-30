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
    const header = document.createElement('h3');
    header.textContent = 'Graph Connections';
    container.appendChild(header);

    const nodes = data.nodes || [];
    const links = data.links || [];

    const nodeMap = new Map();
    nodes.forEach((node) => nodeMap.set(node.id, node.label));

    const list = document.createElement('ul');
    list.className = 'viswiz-graph-links';
    links.forEach((link) => {
      const item = document.createElement('li');
      const from = nodeMap.get(link.from) || link.from;
      const to = nodeMap.get(link.to) || link.to;
      item.textContent = `${from} → ${link.label || 'relates to'} → ${to}`;
      list.appendChild(item);
    });
    container.appendChild(list);
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
