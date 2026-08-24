(function () {
  'use strict';

  function filterRows(input, rowSelector, statusSelector, label) {
    if (!input) return;
    const query = String(input.value || '').trim().toLowerCase();
    const rows = Array.from(document.querySelectorAll(rowSelector));
    let shown = 0;
    rows.forEach((row) => {
      const haystack = String(row.dataset.search || row.textContent || '').toLowerCase();
      const visible = !query || haystack.includes(query);
      row.hidden = !visible;
      if (visible) shown += 1;
    });
    const status = document.querySelector(statusSelector);
    if (status) {
      status.textContent = query
        ? `Showing ${shown} of ${rows.length} ${label}.`
        : `Showing ${rows.length} ${label}.`;
    }
  }

  document.addEventListener('input', function (event) {
    if (event.target.matches('[data-viswiz-dataset-node-search]')) {
      filterRows(event.target, '[data-viswiz-dataset-node-row]', '[data-viswiz-dataset-node-status]', 'nodes');
    }
    if (event.target.matches('[data-viswiz-dataset-relation-search]')) {
      filterRows(event.target, '[data-viswiz-dataset-relation-row]', '[data-viswiz-dataset-relation-status]', 'relations');
    }
  });
})();
