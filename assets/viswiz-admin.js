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
        const updated = name.replace(/targets\\]\\[(name|value)\\]\\[\\d+\\]\\[/, `targets][$1][${index}][`);
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
})(jQuery);
