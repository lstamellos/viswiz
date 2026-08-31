(() => {
  'use strict';

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter' || event.isComposing) return;

    const search = event.target;
    if (!(search instanceof HTMLInputElement) || search.type !== 'search') return;

    const picker = search.closest('.viswiz-node-picker');
    if (!picker || !search.matches('[aria-label$=" node search"]')) return;

    // Enter in a node search should choose the available result, never submit
    // the surrounding relation form before an endpoint has been selected.
    event.preventDefault();

    const select = picker.querySelector('select');
    if (!(select instanceof HTMLSelectElement) || !select.options.length) return;

    if (select.selectedIndex < 0) select.selectedIndex = 0;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.focus();
  });
})();
