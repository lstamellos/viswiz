(() => {
  'use strict';

  const greek = (document.documentElement.lang || '').toLowerCase().startsWith('el');
  const labels = greek ? {
    clearSearch: 'Καθαρισμός αναζήτησης',
    clearAllFilters: 'Καθαρισμός όλων των φίλτρων',
  } : {
    clearSearch: 'Clear search',
    clearAllFilters: 'Clear all filters',
  };

  const enhanced = new WeakSet();
  const queued = new WeakSet();

  function nativeFilterSelects(toolbar) {
    return [...toolbar.querySelectorAll('select:not(.viswiz-property-filter-mode)')];
  }

  function lockFacetModeToFade(toolbar) {
    const mode = toolbar.querySelector('.viswiz-property-filter-mode');
    if (!mode) return;
    if (mode.value !== 'fade') {
      mode.value = 'fade';
      mode.dispatchEvent(new Event('change', { bubbles: true }));
    }
    mode.hidden = true;
    mode.disabled = true;
    mode.tabIndex = -1;
    mode.setAttribute('aria-hidden', 'true');
  }

  function ensureSearchControl(toolbar) {
    const search = toolbar.querySelector('input[type="search"]');
    if (!search) return;

    let group = search.closest('.viswiz-search-group');
    if (!group) {
      group = document.createElement('div');
      group.className = 'viswiz-search-group';
      search.parentNode.insertBefore(group, search);
      group.appendChild(search);
    }

    let clear = group.querySelector('.viswiz-clear-search');
    if (!clear) {
      clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'viswiz-graph-tool viswiz-clear-search';
      clear.textContent = labels.clearSearch;
      clear.setAttribute('aria-label', labels.clearSearch);
      clear.title = labels.clearSearch;
      group.appendChild(clear);

      clear.addEventListener('click', () => {
        if (!search.value) return;
        search.value = '';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        try { search.focus({ preventScroll: true }); } catch (_) { search.focus(); }
      });
      search.addEventListener('input', () => {
        clear.disabled = !search.value;
      });
    }
    clear.disabled = !search.value;
  }

  function ensureClearFiltersControl(container, toolbar) {
    const selects = nativeFilterSelects(toolbar);
    const relationSelect = selects[1] || null;
    if (!relationSelect) return;

    let clear = toolbar.querySelector('.viswiz-clear-all-filters');
    if (!clear) {
      clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'viswiz-graph-tool viswiz-clear-all-filters';
      clear.textContent = labels.clearAllFilters;
      clear.setAttribute('aria-label', labels.clearAllFilters);
      clear.title = labels.clearAllFilters;
      relationSelect.after(clear);

      clear.addEventListener('click', () => {
        nativeFilterSelects(toolbar).forEach((select) => {
          if (!select.value) return;
          select.value = '';
          select.dispatchEvent(new Event('change', { bubbles: true }));
        });
        container.dispatchEvent(new CustomEvent('viswiz:clear-property-filter', { bubbles: false }));
      });
    }
  }

  function enhance(container) {
    const toolbar = container.querySelector('.viswiz-graph-toolbar');
    if (!toolbar) return;

    lockFacetModeToFade(toolbar);
    ensureSearchControl(toolbar);
    ensureClearFiltersControl(container, toolbar);
    enhanced.add(toolbar);
  }

  function queue(container) {
    if (!container || queued.has(container)) return;
    queued.add(container);
    Promise.resolve().then(() => {
      queued.delete(container);
      enhance(container);
    });
  }

  function scan(root = document) {
    if (root instanceof Element && root.matches('.viswiz-visualization')) queue(root);
    root.querySelectorAll?.('.viswiz-visualization').forEach(queue);
  }

  function processMutation(mutation) {
    mutation.addedNodes.forEach((node) => {
      if (!(node instanceof Element)) return;
      const owner = node.closest('.viswiz-visualization');
      if (owner && (node.matches('.viswiz-graph-toolbar,.viswiz-property-filter-mode') || node.querySelector?.('.viswiz-graph-toolbar,.viswiz-property-filter-mode'))) {
        queue(owner);
      }
      scan(node);
    });
  }

  function start() {
    scan(document);
    if (!('MutationObserver' in window)) return;
    const observer = new MutationObserver((mutations) => mutations.forEach(processMutation));
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
