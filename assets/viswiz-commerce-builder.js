(function ($) {
  'use strict';

  const config = window.VisWizCommerceBuilder || {};
  const chartTypes = ['pie', 'bar', 'column', 'line', 'area', 'scatter', 'counter', 'timeline', 'map'];

  function selectedIds(root, selector) {
    const select = root.querySelector(selector);
    if (!select) return [];
    return Array.from(select.selectedOptions || [])
      .map((option) => parseInt(option.value, 10))
      .filter((id) => Number.isInteger(id) && id > 0);
  }

  function setStatus(root, message, type) {
    const status = root.querySelector('[data-viswiz-cb-status]');
    if (!status) return;
    status.hidden = false;
    status.classList.remove('notice-success', 'notice-error', 'notice-warning', 'notice-info');
    status.classList.add(`notice-${type || 'info'}`);
    const p = status.querySelector('p');
    if (p) p.textContent = message;
  }

  function collectManualRows(root) {
    return Array.from(root.querySelectorAll('.viswiz-cb-manual-row')).map((row) => {
      const label = row.querySelector('[data-viswiz-cb-manual-label]');
      const value = row.querySelector('[data-viswiz-cb-manual-value]');
      return {
        label: label ? label.value.trim() : '',
        value: value ? value.value : '',
      };
    }).filter((row) => row.label && row.value !== '' && Number.isFinite(Number(row.value)));
  }

  function addManualRow(root, label, value) {
    const container = root.querySelector('[data-viswiz-cb-manual-rows]');
    if (!container) return;
    const row = document.createElement('p');
    row.className = 'viswiz-cb-manual-row';

    const labelInput = document.createElement('input');
    labelInput.type = 'text';
    labelInput.className = 'regular-text';
    labelInput.setAttribute('data-viswiz-cb-manual-label', '');
    labelInput.placeholder = 'Expenses';
    labelInput.value = label || '';

    const valueInput = document.createElement('input');
    valueInput.type = 'number';
    valueInput.step = '0.01';
    valueInput.setAttribute('data-viswiz-cb-manual-value', '');
    valueInput.placeholder = '-18500';
    valueInput.value = value == null ? '' : value;

    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'button';
    remove.setAttribute('data-viswiz-cb-remove-manual', '');
    remove.textContent = 'Remove';

    row.appendChild(labelInput);
    row.appendChild(document.createTextNode(' '));
    row.appendChild(valueInput);
    row.appendChild(document.createTextNode(' '));
    row.appendChild(remove);
    container.appendChild(row);
  }

  function applyPreset(root) {
    const preset = root.querySelector('[data-viswiz-cb-preset]')?.value || 'custom';
    const metric = root.querySelector('[data-viswiz-cb-metric]');
    const group = root.querySelector('[data-viswiz-cb-group]');
    const subscriptionOnly = root.querySelector('[data-viswiz-cb-subscriptions]');

    if (preset === 'custom') return;
    if (metric) metric.value = 'revenue';
    if (group) group.value = 'total';
    if (subscriptionOnly) subscriptionOnly.checked = preset === 'annual_subscriptions_expenses';

    const manualRows = root.querySelectorAll('.viswiz-cb-manual-row');
    if (manualRows.length === 1) {
      const label = manualRows[0].querySelector('[data-viswiz-cb-manual-label]');
      if (label && !label.value) label.value = 'Expenses';
    }
  }

  function ensureChartVisualization(root) {
    const typeSelect = document.querySelector('[data-viswiz-type]');
    const type = typeSelect ? typeSelect.value : '';
    if (!chartTypes.includes(type)) {
      setStatus(root, config.i18n?.unsupported || 'Choose a chart-like visualization first.', 'warning');
      return false;
    }
    return true;
  }

  function replaceManualRows(rows) {
    const container = document.getElementById('viswiz-visual-pie');
    if (!container) return false;

    container.innerHTML = '';
    rows.forEach((item) => {
      const row = document.createElement('div');
      row.className = 'viswiz-row';

      const label = document.createElement('input');
      label.type = 'text';
      label.name = 'viswiz_meta[manual_pie][label][]';
      label.placeholder = 'Label';
      label.className = 'regular-text';
      label.value = item.label || '';

      const value = document.createElement('input');
      value.type = 'number';
      value.name = 'viswiz_meta[manual_pie][value][]';
      value.placeholder = 'Value';
      value.step = '0.01';
      value.value = Number.isFinite(Number(item.value)) ? String(item.value) : '0';

      const color = document.createElement('input');
      color.type = 'color';
      color.name = 'viswiz_meta[manual_pie][color][]';
      color.value = '#2271b1';

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'button viswiz-remove-row';
      remove.textContent = 'Remove';

      row.append(label, value, color, remove);
      container.appendChild(row);
    });

    const source = document.querySelector('[data-viswiz-source]');
    if (source) {
      source.value = 'manual';
      source.dispatchEvent(new Event('change', { bubbles: true }));
    }

    const labelField = document.getElementById('viswiz_label');
    const year = document.querySelector('[data-viswiz-cb-year]')?.value || '';
    const preset = document.querySelector('[data-viswiz-cb-preset]')?.value || '';
    if (labelField && !labelField.value && preset !== 'custom') {
      labelField.value = preset === 'annual_subscriptions_expenses'
        ? `Subscription revenue and expenses ${year}`
        : `Income and expenses ${year}`;
    }

    if (window.jQuery) {
      window.jQuery(container).find('input').first().trigger('change');
    }
    return true;
  }

  async function build(root) {
    if (!ensureChartVisualization(root)) return;

    const button = root.querySelector('[data-viswiz-cb-build]');
    const spinner = root.querySelector('[data-viswiz-cb-spinner]');
    if (button) {
      button.disabled = true;
      button.textContent = config.i18n?.building || 'Building data…';
    }
    if (spinner) spinner.classList.add('is-active');

    try {
      const payload = {
        year: parseInt(root.querySelector('[data-viswiz-cb-year]')?.value || '', 10),
        metric: root.querySelector('[data-viswiz-cb-metric]')?.value || 'revenue',
        group_by: root.querySelector('[data-viswiz-cb-group]')?.value || 'total',
        product_ids: selectedIds(root, '[data-viswiz-cb-products] select'),
        category_ids: selectedIds(root, '[data-viswiz-cb-categories] select'),
        subscription_only: Boolean(root.querySelector('[data-viswiz-cb-subscriptions]')?.checked),
        manual_rows: collectManualRows(root),
      };

      const response = await window.fetch(config.restUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': config.nonce || '',
        },
        body: JSON.stringify(payload),
      });
      const data = await response.json();
      if (!response.ok) {
        throw new Error(data?.message || config.i18n?.error || 'Could not build WooCommerce data.');
      }
      if (!Array.isArray(data.rows) || !data.rows.length) {
        setStatus(root, config.i18n?.noRows || 'No matching WooCommerce data was found.', 'warning');
        return;
      }

      if (!replaceManualRows(data.rows)) {
        throw new Error('The visualization manual data editor is not available on this screen.');
      }

      const meta = data.meta || {};
      const suffix = meta.orders_matched != null ? ` (${meta.orders_matched} matching orders)` : '';
      setStatus(root, `${config.i18n?.success || 'Visualization data built.'}${suffix}`, 'success');
    } catch (error) {
      setStatus(root, error?.message || config.i18n?.error || 'Could not build WooCommerce data.', 'error');
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = config.i18n?.build || 'Build visualization data';
      }
      if (spinner) spinner.classList.remove('is-active');
    }
  }

  $(document).on('change', '[data-viswiz-cb-preset]', function () {
    const root = this.closest('[data-viswiz-commerce-builder]');
    if (root) applyPreset(root);
  });

  $(document).on('click', '[data-viswiz-cb-add-manual]', function () {
    const root = this.closest('[data-viswiz-commerce-builder]');
    if (root) addManualRow(root, '', '');
  });

  $(document).on('click', '[data-viswiz-cb-remove-manual]', function () {
    const root = this.closest('[data-viswiz-commerce-builder]');
    const row = this.closest('.viswiz-cb-manual-row');
    if (!root || !row) return;
    const rows = root.querySelectorAll('.viswiz-cb-manual-row');
    if (rows.length === 1) {
      row.querySelectorAll('input').forEach((input) => { input.value = ''; });
    } else {
      row.remove();
    }
  });

  $(document).on('click', '[data-viswiz-cb-build]', function () {
    const root = this.closest('[data-viswiz-commerce-builder]');
    if (root) build(root);
  });

  $(function () {
    $(document.body).trigger('wc-enhanced-select-init');
  });
})(jQuery);
