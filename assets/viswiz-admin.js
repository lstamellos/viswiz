(function ($) {
  function updateVisualizationFields() {
    const type = $('[data-viswiz-type]').val();
    if (!type) {
      return;
    }
    $('[data-viswiz-types]').each(function () {
      const supported = $(this).data('viswiz-types').split(',');
      if (supported.includes(type)) {
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

  function addDiagramSection(containerId, namePrefix) {
    const container = document.getElementById(containerId);
    if (!container) {
      return;
    }
    const index = container.children.length;
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

  const addHandlers = {
    progress() {
      addRow('viswiz-progress-rows', ['Label', 'Value', 'Target'], 'viswiz_manual_progress', ['label', 'value', 'target'], {
        typeMap: { value: 'number', target: 'number' },
      });
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
      addRow('viswiz-visual-progress', ['Label', 'Value', 'Target'], 'viswiz_meta[manual_progress]', ['label', 'value', 'target'], {
        typeMap: { value: 'number', target: 'number' },
      });
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
    $(this).closest('.viswiz-row').remove();
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

  $(document).on('change', '[data-viswiz-type]', function () {
    updateVisualizationFields();
  });

  $(document).ready(function () {
    updateVisualizationFields();
  });
})(jQuery);
