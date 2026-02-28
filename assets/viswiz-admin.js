(function ($) {
  function updateVisualizationFields() {
    const type = $('[data-viswiz-type]').val();
    const source = $('[data-viswiz-source]').val() || 'auto';
    const periodMode = $('[data-viswiz-period-mode]').val();
    if (!type) {
      return;
    }
    $('[data-viswiz-types]').each(function () {
      const typeAttr = $(this).attr('data-viswiz-types');
      if (!typeAttr) {
        return;
      }
      const supported = typeAttr.split(',');
      const sourceFilter = $(this).attr('data-viswiz-sources');
      const sources = sourceFilter ? sourceFilter.split(',') : [];
      const matchesType = supported.includes(type);
      const matchesSource = sources.length === 0 || sources.includes(source);
      if (matchesType && matchesSource) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });

    $('[data-viswiz-period]').each(function () {
      const supported = $(this).attr('data-viswiz-period');
      if (!supported || supported === periodMode) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  }

  function addRow(containerId, placeholders, namePrefix, keys, options = {}) {
    const container = document.getElementById(containerId);
    if (!container) {
      return;
    }
    const row = document.createElement('div');
    row.className = 'viswiz-row';
    keys.forEach((key, index) => {
      const input = document.createElement('input');
      const placeholder = placeholders[index];
      input.name = `${namePrefix}[${key}][]`;
      input.className = 'regular-text';
      input.placeholder = placeholder;

      if (options.typeMap && options.typeMap[key]) {
        input.type = options.typeMap[key];
      } else {
        input.type = 'text';
      }

      if (input.type === 'number') {
        input.step = '0.01';
      }

      row.appendChild(input);
    });

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'button viswiz-remove-row';
    remove.textContent = 'Remove';
    row.appendChild(remove);
    container.appendChild(row);
  }

  function addProgressRow(containerId, namePrefix) {
    const container = document.getElementById(containerId);
    if (!container) {
      return;
    }
    const index = getNextProgressIndex(container);
    const row = document.createElement('div');
    row.className = 'viswiz-row';
    row.dataset.progressIndex = index;
    row.innerHTML = `
      <input type="text" name="${namePrefix}[label][]" placeholder="Label" class="regular-text" />
      <input type="number" name="${namePrefix}[value][]" placeholder="Value" step="0.01" />
      <div class="viswiz-targets" data-name-prefix="${namePrefix}">
        <div class="viswiz-target-row">
          <input type="text" name="${namePrefix}[targets][name][${index}][]" placeholder="Target name" class="regular-text" />
          <input type="number" name="${namePrefix}[targets][value][${index}][]" placeholder="Target value" step="0.01" />
          <button type="button" class="button viswiz-remove-target">Remove</button>
        </div>
      </div>
      <button type="button" class="button viswiz-add-target">Add Target</button>
      <button type="button" class="button viswiz-remove-row">Remove</button>
    `;
    container.appendChild(row);
  }

  function getNextProgressIndex(container) {
    const values = Array.from(container.querySelectorAll('.viswiz-row')).map((row) =>
      parseInt(row.dataset.progressIndex, 10)
    );
    const max = values.length ? Math.max(...values) : -1;
    return max + 1;
  }

  function reindexProgressRows(container) {
    Array.from(container.querySelectorAll('.viswiz-row')).forEach((row, index) => {
      row.dataset.progressIndex = index;
      row.querySelectorAll('.viswiz-target-row input').forEach((input) => {
        const name = input.getAttribute('name');
        if (!name) {
          return;
        }
        const updated = name.replace(/targets\]\[(name|value)\]\[\d+\]\[/, `targets][$1][${index}][`);
        input.setAttribute('name', updated);
      });
    });
  }
  function addDiagramSection(containerId, namePrefix) {
    const container = document.getElementById(containerId);
    if (!container) {
      return;
    }
    const index = getNextDiagramIndex(container);
    const section = document.createElement('div');
    section.className = 'viswiz-section';
    section.dataset.sectionIndex = index;
    section.innerHTML = `
      <input type="text" name="${namePrefix}[title][]" placeholder="Section Title" class="regular-text" />
      <div class="viswiz-items">
        <div class="viswiz-item-row">
          <input type="text" name="${namePrefix}[items][${index}][]" placeholder="Item" class="regular-text" />
          <button type="button" class="button viswiz-remove-item">Remove</button>
        </div>
      </div>
      <button type="button" class="button viswiz-add-item">Add Item</button>
      <button type="button" class="button viswiz-remove-section">Remove Section</button>
    `;
    container.appendChild(section);
  }

  function getNextDiagramIndex(container) {
    const values = Array.from(container.querySelectorAll('.viswiz-section')).map((section) =>
      parseInt(section.dataset.sectionIndex, 10)
    );
    const max = values.length ? Math.max(...values) : -1;
    return max + 1;
  }

  const addHandlers = {
    progress() {
      addProgressRow('viswiz-progress-rows', 'viswiz_manual_progress');
    },
    pie() {
      addRow('viswiz-pie-rows', ['Label', 'Value', 'Color'], 'viswiz_manual_pie', ['label', 'value', 'color'], {
        typeMap: { value: 'number', color: 'color' },
      });
    },
    diagram() {
      addDiagramSection('viswiz-diagram-sections', 'viswiz_diagram_data');
    },
    'graph-node': function () {
      addRow('viswiz-graph-nodes', ['Node ID', 'Label'], 'viswiz_graph_data[nodes]', ['id', 'label']);
    },
    'graph-link': function () {
      addRow('viswiz-graph-links', ['From ID', 'To ID', 'Label'], 'viswiz_graph_data[links]', ['from', 'to', 'label']);
    },
    'visual-progress': function () {
      addProgressRow('viswiz-visual-progress', 'viswiz_meta[manual_progress]');
    },
    'visual-pie': function () {
      addRow('viswiz-visual-pie', ['Label', 'Value', 'Color'], 'viswiz_meta[manual_pie]', ['label', 'value', 'color'], {
        typeMap: { value: 'number', color: 'color' },
      });
    },
    'visual-diagram': function () {
      addDiagramSection('viswiz-visual-diagram', 'viswiz_meta[diagram_data]');
    },
    'visual-graph-node': function () {
      addRow('viswiz-visual-graph-nodes', ['Node ID', 'Label'], 'viswiz_meta[graph_data][nodes]', ['id', 'label']);
    },
    'visual-graph-link': function () {
      addRow('viswiz-visual-graph-links', ['From ID', 'To ID', 'Label'], 'viswiz_meta[graph_data][links]', ['from', 'to', 'label']);
    },
  };

  $(document).on('click', '[data-viswiz-add]', function () {
    const key = $(this).data('viswiz-add');
    if (addHandlers[key]) {
      addHandlers[key]();
    }
  });

  $(document).on('click', '.viswiz-remove-row', function () {
    const container = $(this).closest('.viswiz-repeatable');
    $(this).closest('.viswiz-row').remove();
    if (container.length) {
      reindexProgressRows(container.get(0));
    }
  });

  $(document).on('click', '.viswiz-remove-section', function () {
    $(this).closest('.viswiz-section').remove();
  });

  $(document).on('click', '.viswiz-add-item', function () {
    const section = $(this).closest('.viswiz-section');
    const index = section.data('section-index');
    const items = section.find('.viswiz-items');
    const row = $(
      `<div class="viswiz-item-row">
        <input type="text" name="${section.find('input[name$="[title][]"]').attr('name').replace('[title][]', `[items][${index}][]`)}" placeholder="Item" class="regular-text" />
        <button type="button" class="button viswiz-remove-item">Remove</button>
      </div>`
    );
    items.append(row);
  });

  $(document).on('click', '.viswiz-remove-item', function () {
    $(this).closest('.viswiz-item-row').remove();
  });

  $(document).on('click', '.viswiz-add-target', function () {
    const row = $(this).closest('.viswiz-row');
    const index = row.attr('data-progress-index');
    const targets = row.find('.viswiz-targets');
    const namePrefix = targets.data('name-prefix');
    if (!namePrefix) {
      return;
    }
    const rowHtml = `
      <div class="viswiz-target-row">
        <input type="text" name="${namePrefix}[targets][name][${index}][]" placeholder="Target name" class="regular-text" />
        <input type="number" name="${namePrefix}[targets][value][${index}][]" placeholder="Target value" step="0.01" />
        <button type="button" class="button viswiz-remove-target">Remove</button>
      </div>
    `;
    targets.append(rowHtml);
  });

  $(document).on('click', '.viswiz-remove-target', function () {
    $(this).closest('.viswiz-target-row').remove();
  });

  $(document).on('change', '[data-viswiz-type]', function () {
    updateVisualizationFields();
  });

  $(document).on('change', '[data-viswiz-source]', function () {
    updateVisualizationFields();
  });

  $(document).on('change', '[data-viswiz-period-mode]', function () {
    updateVisualizationFields();
  });

  $(document).on('click', '.viswiz-tab-button', function () {
    const tab = $(this).data('viswiz-tab');
    $('.viswiz-tab-button').removeClass('is-active');
    $(this).addClass('is-active');
    $('.viswiz-tab-panel').removeClass('is-active');
    $(`[data-viswiz-panel="${tab}"]`).addClass('is-active');
    updateVisualizationFields();
  });

  $(document).ready(function () {
    updateVisualizationFields();
    updateSalesPeriodVisibility();
    $('.viswiz-tab-button.is-active').trigger('click');
  });

  function updateSalesPeriodVisibility() {
    const mode = $('#viswiz_sales_period_mode').val();
    $('.viswiz-period-group[data-viswiz-period]').each(function () {
      const supported = $(this).data('viswiz-period');
      if (!supported || supported === mode) {
        $(this).show();
      } else {
        $(this).hide();
      }
    });
  }

  $(document).on('change', '#viswiz_sales_period_mode', function () {
    updateSalesPeriodVisibility();
  });

  const defaultColors = ['#4caf50', '#03a9f4', '#ffc107', '#e91e63', '#9c27b0', '#ff5722'];

  function getFormattingColors() {
    return {
      primary: $('#viswiz_color_primary').val() || '#4caf50',
      secondary: $('#viswiz_color_secondary').val() || '#2196f3',
      accent: $('#viswiz_color_accent').val() || '#ffc107',
      background: $('#viswiz_color_background').val() || '#ffffff',
      text: $('#viswiz_color_text').val() || '#333333',
    };
  }

  function getAnimation() {
    return $('#viswiz_animation').val() || 'none';
  }

  function applyPreviewFormatting(container, colors, animation) {
    container.style.setProperty('--viswiz-primary', colors.primary);
    container.style.setProperty('--viswiz-secondary', colors.secondary);
    container.style.setProperty('--viswiz-accent', colors.accent);
    container.style.setProperty('--viswiz-background', colors.background);
    container.style.setProperty('--viswiz-text', colors.text);
    container.className = container.className.replace(/viswiz-animate-\w+/g, '').trim();
    if (animation && animation !== 'none') {
      container.classList.add('viswiz-animate-' + animation);
    }
  }

  function gatherManualProgress() {
    const items = [];
    $('#viswiz-visual-progress .viswiz-row').each(function () {
      const $row = $(this);
      const label = $row.find('input[name$="[label][]"]').val() || '';
      const value = parseFloat($row.find('input[name$="[value][]"]').val()) || 0;
      const targets = [];
      $row.find('.viswiz-target-row').each(function () {
        const name = $(this).find('input[placeholder="Target name"]').val() || '';
        const targetValue = parseFloat($(this).find('input[placeholder="Target value"]').val()) || 0;
        if (name || targetValue) {
          targets.push({ name, value: targetValue });
        }
      });
      if (label || value || targets.length) {
        const maxTarget = targets.reduce((max, t) => Math.max(max, t.value), 0);
        items.push({ label, value, target: maxTarget, targets });
      }
    });
    return items;
  }

  function gatherManualPie() {
    const items = [];
    $('#viswiz-visual-pie .viswiz-row').each(function () {
      const label = $(this).find('input[name$="[label][]"]').val() || '';
      const value = parseFloat($(this).find('input[name$="[value][]"]').val()) || 0;
      const color = $(this).find('input[type="color"]').val() || '';
      if (label || value) {
        items.push({ label, value, color });
      }
    });
    return items;
  }

  function gatherDiagramData() {
    const sections = [];
    $('#viswiz-visual-diagram .viswiz-section').each(function () {
      const title = $(this).find('input[name$="[title][]"]').val() || '';
      const items = [];
      $(this).find('.viswiz-item-row input').each(function () {
        const val = $(this).val();
        if (val) {
          items.push(val);
        }
      });
      if (title || items.length) {
        sections.push({ title, items });
      }
    });
    return sections;
  }

  function gatherGraphData() {
    const nodes = [];
    const links = [];
    $('#viswiz-visual-graph-nodes .viswiz-row').each(function () {
      const id = $(this).find('input[name$="[id][]"]').val() || '';
      const label = $(this).find('input[name$="[label][]"]').val() || '';
      if (id || label) {
        nodes.push({ id, label });
      }
    });
    $('#viswiz-visual-graph-links .viswiz-row').each(function () {
      const from = $(this).find('input[name$="[from][]"]').val() || '';
      const to = $(this).find('input[name$="[to][]"]').val() || '';
      const label = $(this).find('input[name$="[label][]"]').val() || '';
      if (from || to) {
        links.push({ from, to, label });
      }
    });
    return { nodes, links };
  }

  function formatCurrency(value) {
    const amount = parseFloat(value || 0);
    return '$' + amount.toFixed(2);
  }

  function renderPreviewProgress(container, data) {
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
    fill.style.width = percent + '%';

    const meta = document.createElement('div');
    meta.className = 'viswiz-progress-meta';
    meta.textContent = formatCurrency(data.value) + ' / ' + formatCurrency(targetValue) + ' (' + percent.toFixed(1) + '%)';

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
    return targets.reduce(function (max, target) {
      return Math.max(max, parseFloat(target.value || 0));
    }, 0);
  }

  function buildProgressMarkers(targets, maxValue) {
    const wrapper = document.createElement('div');
    wrapper.className = 'viswiz-progress-markers';
    (targets || []).forEach(function (target) {
      const targetValue = parseFloat(target.value || 0);
      if (!targetValue) {
        return;
      }
      const marker = document.createElement('div');
      marker.className = 'viswiz-progress-marker';
      const offset = maxValue > 0 ? Math.min(100, (targetValue / maxValue) * 100) : 0;
      marker.style.left = offset + '%';
      marker.title = (target.name || 'Target') + ': ' + targetValue;
      const markerLabel = document.createElement('span');
      markerLabel.className = 'viswiz-progress-marker-label';
      markerLabel.textContent = target.name || 'Target';
      marker.appendChild(markerLabel);
      wrapper.appendChild(marker);
    });
    return wrapper;
  }

  function renderPreviewProgressList(container, items, fallbackLabel) {
    container.innerHTML = '';
    items.forEach(function (item) {
      const row = document.createElement('div');
      row.className = 'viswiz-progress-item';
      renderPreviewProgress(row, {
        label: item.label || fallbackLabel,
        value: parseFloat(item.value || 0),
        target: parseFloat(item.target || 0),
        targets: item.targets || [],
      });
      container.appendChild(row);
    });
  }

  function renderPreviewPie(container, data) {
    container.innerHTML = '';
    const title = document.createElement('div');
    title.className = 'viswiz-pie-title';
    title.textContent = data.title;

    if (typeof d3 === 'undefined') {
      container.appendChild(title);
      const msg = document.createElement('p');
      msg.textContent = 'D3.js not loaded. Pie chart preview unavailable.';
      container.appendChild(msg);
      return;
    }

    const svg = d3.create('svg').attr('viewBox', '0 0 220 220').attr('class', 'viswiz-pie-chart');
    const pie = d3.pie().value(function (entry) {
      return entry.value || 0;
    });
    const arc = d3.arc().innerRadius(0).outerRadius(100);
    const g = svg.append('g').attr('transform', 'translate(110,110)');

    g.selectAll('path')
      .data(pie(data.values))
      .enter()
      .append('path')
      .attr('d', arc)
      .attr('fill', function (entry, index) {
        return entry.data.color || defaultColors[index % defaultColors.length];
      })
      .attr('stroke', '#fff')
      .attr('stroke-width', 1);

    container.appendChild(title);
    container.appendChild(svg.node());

    const legend = document.createElement('ul');
    legend.className = 'viswiz-pie-legend';
    data.values.forEach(function (entry, index) {
      const item = document.createElement('li');
      const swatch = document.createElement('span');
      swatch.className = 'viswiz-swatch';
      swatch.style.backgroundColor = entry.color || defaultColors[index % defaultColors.length];
      item.appendChild(swatch);
      item.appendChild(document.createTextNode(entry.label + ': ' + formatCurrency(entry.value)));
      legend.appendChild(item);
    });
    container.appendChild(legend);
  }

  function renderPreviewDiagram(container, data) {
    container.innerHTML = '';
    const header = document.createElement('h3');
    header.textContent = 'Diagram';
    container.appendChild(header);

    data.forEach(function (section) {
      const sectionEl = document.createElement('div');
      sectionEl.className = 'viswiz-diagram-section';

      const title = document.createElement('strong');
      title.textContent = section.title || 'Section';
      sectionEl.appendChild(title);

      const list = document.createElement('ul');
      (section.items || []).forEach(function (item) {
        const li = document.createElement('li');
        li.textContent = item;
        list.appendChild(li);
      });
      sectionEl.appendChild(list);
      container.appendChild(sectionEl);
    });
  }

  function getGraphOptions() {
    return {
      nodeRadius: parseInt($('#viswiz_graph_node_radius').val(), 10) || 20,
      linkDistance: parseInt($('#viswiz_graph_link_distance').val(), 10) || 100,
      chargeStrength: parseInt($('#viswiz_graph_charge').val(), 10) || -300,
    };
  }

  function renderPreviewGraph(container, data, options) {
    container.innerHTML = '';

    const graphOptions = options || getGraphOptions();
    const nodes = (data.nodes || []).map(function (n) {
      return { id: n.id, label: n.label || n.id };
    });
    const links = (data.links || []).map(function (l) {
      return { source: l.from, target: l.to, label: l.label || '' };
    });

    if (!nodes.length) {
      container.textContent = 'No graph data entered.';
      return;
    }

    if (typeof d3 === 'undefined') {
      container.textContent = 'D3.js not loaded. Graph preview unavailable.';
      return;
    }

    const width = 400;
    const height = 300;
    const nodeRadius = graphOptions.nodeRadius;
    const linkDistance = graphOptions.linkDistance;
    const chargeStrength = graphOptions.chargeStrength;
    const colors = getFormattingColors();

    const svg = d3.create('svg')
      .attr('viewBox', [0, 0, width, height])
      .attr('class', 'viswiz-graph-svg')
      .attr('width', '100%')
      .attr('height', height);

    const simulation = d3.forceSimulation(nodes)
      .force('link', d3.forceLink(links).id(function (d) { return d.id; }).distance(linkDistance))
      .force('charge', d3.forceManyBody().strength(chargeStrength))
      .force('center', d3.forceCenter(width / 2, height / 2))
      .force('collision', d3.forceCollide().radius(nodeRadius + 10));

    const defs = svg.append('defs');
    defs.append('marker')
      .attr('id', 'viswiz-preview-arrowhead')
      .attr('viewBox', '0 -5 10 10')
      .attr('refX', 20)
      .attr('refY', 0)
      .attr('markerWidth', 6)
      .attr('markerHeight', 6)
      .attr('orient', 'auto')
      .append('path')
      .attr('d', 'M0,-5L10,0L0,5')
      .attr('fill', colors.secondary);

    const link = svg.append('g')
      .attr('class', 'viswiz-graph-links-g')
      .selectAll('line')
      .data(links)
      .join('line')
      .attr('stroke', colors.secondary)
      .attr('stroke-width', 2)
      .attr('stroke-opacity', 0.6)
      .attr('marker-end', 'url(#viswiz-preview-arrowhead)');

    const linkLabels = svg.append('g')
      .attr('class', 'viswiz-graph-link-labels')
      .selectAll('text')
      .data(links)
      .join('text')
      .attr('font-size', 10)
      .attr('fill', colors.text)
      .attr('text-anchor', 'middle')
      .text(function (d) { return d.label; });

    const node = svg.append('g')
      .attr('class', 'viswiz-graph-nodes')
      .selectAll('g')
      .data(nodes)
      .join('g');

    node.append('circle')
      .attr('r', nodeRadius)
      .attr('fill', colors.primary)
      .attr('stroke', '#fff')
      .attr('stroke-width', 2);

    node.append('text')
      .attr('dy', 4)
      .attr('text-anchor', 'middle')
      .attr('font-size', 11)
      .attr('fill', '#fff')
      .attr('pointer-events', 'none')
      .text(function (d) { return d.label; });

    simulation.on('tick', function () {
      link
        .attr('x1', function (d) { return d.source.x; })
        .attr('y1', function (d) { return d.source.y; })
        .attr('x2', function (d) { return d.target.x; })
        .attr('y2', function (d) { return d.target.y; });

      linkLabels
        .attr('x', function (d) { return (d.source.x + d.target.x) / 2; })
        .attr('y', function (d) { return (d.source.y + d.target.y) / 2 - 5; });

      node.attr('transform', function (d) { return 'translate(' + d.x + ',' + d.y + ')'; });
    });

    container.appendChild(svg.node());
  }

  function refreshPreview() {
    const container = document.getElementById('viswiz-preview-container');
    if (!container) {
      return;
    }

    const type = $('[data-viswiz-type]').val();
    const source = $('[data-viswiz-source]').val() || 'auto';
    const label = $('#viswiz_label').val() || 'Visualization';
    const target = parseFloat($('#viswiz_target').val()) || 0;
    const colors = getFormattingColors();
    const animation = getAnimation();

    container.innerHTML = '';

    let vizContainer;
    if (type === 'progress') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-progress';
      applyPreviewFormatting(vizContainer, colors, animation);

      if (source === 'manual') {
        const items = gatherManualProgress();
        if (items.length > 1) {
          renderPreviewProgressList(vizContainer, items, label);
        } else if (items.length === 1) {
          renderPreviewProgress(vizContainer, items[0]);
        } else {
          vizContainer.textContent = 'No manual progress data entered.';
        }
      } else {
        renderPreviewProgress(vizContainer, {
          label: label || 'Sales Progress',
          value: 7500,
          target: target || 10000,
          targets: target ? [{ name: 'Target', value: target }] : [],
        });
      }
      container.appendChild(vizContainer);
    } else if (type === 'pie') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-pie';
      applyPreviewFormatting(vizContainer, colors, animation);

      if (source === 'manual') {
        const items = gatherManualPie();
        if (items.length) {
          renderPreviewPie(vizContainer, { title: label || 'Pie Chart', values: items });
        } else {
          vizContainer.textContent = 'No manual pie data entered.';
        }
      } else {
        renderPreviewPie(vizContainer, {
          title: label || 'Sales Breakdown',
          values: [
            { label: 'Completed', value: 45, color: '#4caf50' },
            { label: 'Processing', value: 30, color: '#2196f3' },
            { label: 'Pending', value: 25, color: '#ffc107' },
          ],
        });
      }
      container.appendChild(vizContainer);
    } else if (type === 'diagram') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-diagram';
      applyPreviewFormatting(vizContainer, colors, animation);

      const data = gatherDiagramData();
      if (data.length) {
        renderPreviewDiagram(vizContainer, data);
      } else {
        vizContainer.textContent = 'No diagram data entered.';
      }
      container.appendChild(vizContainer);
    } else if (type === 'graph') {
      vizContainer = document.createElement('div');
      vizContainer.className = 'viswiz-graph';
      applyPreviewFormatting(vizContainer, colors, animation);

      const data = gatherGraphData();
      if (data.nodes.length || data.links.length) {
        renderPreviewGraph(vizContainer, data);
      } else {
        vizContainer.textContent = 'No graph data entered.';
      }
      container.appendChild(vizContainer);
    } else {
      container.textContent = 'Select a visualization type to see a preview.';
    }
  }

  $(document).on('click', '.viswiz-tab-button[data-viswiz-tab="preview"]', function () {
    refreshPreview();
  });

  $(document).on('click', '#viswiz-refresh-preview', function () {
    refreshPreview();
  });
})(jQuery);
