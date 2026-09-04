(() => {
  'use strict';

  const cfg = window.VisWizWooSourceSelection || {};
  const i18n = cfg.i18n || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const tr = (key, fallback) => i18n[key] || fallback;

  function ids(value) {
    return String(value || '')
      .split(/[\s,]+/)
      .map((item) => Number(item))
      .filter((item) => Number.isInteger(item) && item > 0)
      .filter((item, index, items) => items.indexOf(item) === index);
  }

  function insertDescription(container, key, text) {
    if (!container || container.querySelector(`[data-viswiz-woo-note="${key}"]`)) return;
    const note = document.createElement('p');
    note.className = 'description viswiz-woo-source-note';
    note.dataset.viswizWooNote = key;
    note.textContent = text;
    const heading = container.querySelector('h2, h3');
    if (heading) heading.after(note);
    else container.prepend(note);
  }

  function picker(input, kind) {
    if (!input || input.dataset.viswizWooPickerAdapted === '1' || cfg.searchable !== true) return null;
    const key = input.dataset.viswizWoo || '';
    const label = input.closest('label');
    const labelText = label?.querySelector('span');
    const values = ids(input.value);
    const labels = kind === 'product' ? (cfg.products || {}) : (cfg.categories || {});

    input.type = 'hidden';
    input.dataset.viswizWooPickerAdapted = '1';

    const select = document.createElement('select');
    select.multiple = true;
    select.className = `${kind === 'product' ? 'wc-product-search' : 'wc-category-search'} viswiz-woo-picker`;
    select.dataset.viswizWooPicker = key;
    select.dataset.allowClear = 'true';
    select.dataset.minimumInputLength = '1';
    select.dataset.placeholder = kind === 'product'
      ? tr('searchProducts', 'Search products…')
      : tr('searchCategories', 'Search product categories…');
    if (kind === 'category') select.dataset.returnId = 'true';
    select.style.width = '100%';

    values.forEach((id) => {
      const option = document.createElement('option');
      option.value = String(id);
      option.textContent = labels[String(id)] || `${kind === 'product' ? 'Product' : 'Category'} #${id}`;
      option.selected = true;
      select.appendChild(option);
    });

    if (labelText) {
      labelText.textContent = kind === 'product' ? tr('products', 'Products') : tr('categories', 'Categories');
    }
    input.after(select);

    select.addEventListener('change', () => {
      input.value = [...select.selectedOptions].map((option) => option.value).join(',');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    return select;
  }

  function clarifySourceModes() {
    const source = $('[data-viswiz-source]');
    const liveOption = source ? [...source.options].find((option) => option.value === 'woo_live') : null;
    if (liveOption) liveOption.textContent = tr('liveOption', 'WooCommerce live query');

    const livePanel = $('[data-viswiz-source-panel="woo_live"]');
    if (livePanel) {
      insertDescription(
        livePanel,
        'live',
        cfg.available === true
          ? tr('liveDescription', 'Live query: recalculates from current WooCommerce orders when requested and uses the configured cache/refresh interval. No rows are copied into a dataset.')
          : tr('woocommerceInactive', 'WooCommerce is not active. Existing WooCommerce filter values are preserved, but new live queries or snapshots cannot be run.')
      );
      if (cfg.available === true && cfg.searchable !== true) {
        insertDescription(livePanel, 'manual-ids', tr('manualIdsFallback', 'WooCommerce search pickers are not available for this account. Product and category IDs remain editable manually.'));
      }
    }

    const snapshotButton = $('[data-viswiz-commerce-snapshot]');
    const snapshot = snapshotButton?.closest('section');
    if (snapshot) {
      insertDescription(snapshot, 'snapshot', tr('snapshotDescription', 'Snapshot: runs the WooCommerce query once and replaces this canonical dataset with the current results. The copied rows can then be edited independently and do not stay synchronized with WooCommerce.'));
      snapshotButton.textContent = tr('snapshotButton', 'Replace dataset with current snapshot');
      const dataset = $('#viswiz-dataset-editor');
      if (cfg.available !== true) {
        snapshotButton.disabled = true;
        insertDescription(snapshot, 'unavailable', tr('woocommerceInactive', 'WooCommerce is not active. Existing WooCommerce filter values are preserved, but new live queries or snapshots cannot be run.'));
      } else if (cfg.snapshotAllowed !== true) {
        snapshotButton.disabled = true;
        insertDescription(snapshot, 'permission', tr('snapshotPermission', 'Your account does not have permission to run WooCommerce snapshots.'));
      } else if (dataset?.dataset.schema === 'graph') {
        snapshotButton.disabled = true;
        insertDescription(snapshot, 'graph', tr('graphSnapshotDisabled', 'WooCommerce snapshots require a row-based dataset and cannot replace graph data.'));
      }
      if (cfg.available === true && cfg.searchable !== true) {
        insertDescription(snapshot, 'manual-ids', tr('manualIdsFallback', 'WooCommerce search pickers are not available for this account. Product and category IDs remain editable manually.'));
      }
    }
  }

  function init() {
    const product = picker($('[data-viswiz-woo="product_ids"]'), 'product');
    const category = picker($('[data-viswiz-woo="category_ids"]'), 'category');
    clarifySourceModes();

    if (cfg.searchable === true && (product || category) && window.jQuery) {
      window.jQuery(document.body).trigger('wc-enhanced-select-init');
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
