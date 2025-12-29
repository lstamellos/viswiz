(function () {
  const POLL_INTERVAL = 30000;

  function fetchJson(endpoint) {
    return fetch(`${VisWizData.restUrl}${endpoint}`, {
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
    const percent = data.target > 0 ? Math.min(100, (data.value / data.target) * 100) : 0;
    fill.style.width = `${percent}%`;

    const meta = document.createElement('div');
    meta.className = 'viswiz-progress-meta';
    meta.textContent = `${data.value.toFixed(2)} / ${data.target.toFixed(2)} (${percent.toFixed(1)}%)`;

    bar.appendChild(fill);
    container.appendChild(label);
    container.appendChild(bar);
    container.appendChild(meta);
  }

  function renderPie(container, data) {
    container.innerHTML = '';
    const title = document.createElement('div');
    title.className = 'viswiz-pie-title';
    title.textContent = data.title;

    const canvas = document.createElement('canvas');
    canvas.width = 220;
    canvas.height = 220;
    canvas.className = 'viswiz-pie-canvas';
    container.appendChild(title);
    container.appendChild(canvas);

    const ctx = canvas.getContext('2d');
    const total = data.values.reduce((sum, entry) => sum + entry.value, 0);
    let startAngle = -Math.PI / 2;

    data.values.forEach((entry, index) => {
      const slice = total > 0 ? (entry.value / total) * Math.PI * 2 : 0;
      const color = entry.color || defaultColors[index % defaultColors.length];
      ctx.beginPath();
      ctx.moveTo(110, 110);
      ctx.arc(110, 110, 100, startAngle, startAngle + slice);
      ctx.closePath();
      ctx.fillStyle = color;
      ctx.fill();
      startAngle += slice;
    });

    const legend = document.createElement('ul');
    legend.className = 'viswiz-pie-legend';
    data.values.forEach((entry, index) => {
      const item = document.createElement('li');
      const swatch = document.createElement('span');
      swatch.className = 'viswiz-swatch';
      swatch.style.backgroundColor = entry.color || defaultColors[index % defaultColors.length];
      item.appendChild(swatch);
      item.appendChild(document.createTextNode(`${entry.label}: ${entry.value}`));
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
    fetchJson('/sales')
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
    fetchJson('/sales-status')
      .then((data) => {
        renderPie(container, {
          title: container.dataset.title || 'Sales Breakdown',
          values: data.statusCounts || [],
        });
      })
      .catch(() => {
        container.textContent = 'Unable to load sales breakdown.';
      });
  }

  function loadManualProgress(container, index) {
    const manual = VisWizData.manualProgress || [];
    const item = manual[index];
    if (item) {
      renderProgress(container, {
        label: item.label || 'Manual Progress',
        value: parseFloat(item.value || 0),
        target: parseFloat(item.target || 0),
      });
    } else {
      container.textContent = 'No manual progress data available.';
    }
  }

  function loadManualPie(container) {
    const manual = VisWizData.manualPie || [];
    renderPie(container, {
      title: container.dataset.title || 'Manual Pie Chart',
      values: manual,
    });
  }

  function initProgress() {
    document.querySelectorAll('.viswiz-progress').forEach((container, index) => {
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
      renderDiagram(container, VisWizData.diagramData || []);
    });
  }

  function initGraph() {
    document.querySelectorAll('.viswiz-graph').forEach((container) => {
      renderGraph(container, VisWizData.graphData || {});
    });
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
