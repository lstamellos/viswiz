(() => {
  'use strict';
  const { __ } = window.wp.i18n;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  function initVisualizationConfig() {
    const root = $('[data-viswiz-visualization-config]');
    if (!root) return;
    const source = $('[data-viswiz-source]', root);
    const renderer = $('[data-viswiz-renderer]', root);
    const dataset = $('[data-viswiz-dataset-select]', root);
    if (!source || !renderer || !dataset) return;

    const refresh = () => {
      $$('[data-viswiz-source-panel]', root).forEach((panel) => { panel.hidden = panel.dataset.viswizSourcePanel !== source.value; });
      const rendererOption = renderer.selectedOptions[0];
      const allowed = new Set(String(rendererOption?.dataset.schemas || '').split(',').filter(Boolean));
      [...dataset.options].forEach((option, index) => { if (index) option.hidden = !allowed.has(option.dataset.schema); });
      if (dataset.selectedOptions[0]?.hidden) dataset.value = '0';
      const invalidWoo = ['graph', 'flow_diagram', 'org_chart', 'map', 'scatter', 'diagram'].includes(renderer.value);
      const wooOption = [...source.options].find((option) => option.value === 'woo_live');
      if (wooOption) wooOption.disabled = invalidWoo;
      if (invalidWoo && source.value === 'woo_live') source.value = 'dataset';
      $$('[data-viswiz-source-panel]', root).forEach((panel) => { panel.hidden = panel.dataset.viswizSourcePanel !== source.value; });
    };

    source.addEventListener('change', refresh);
    renderer.addEventListener('change', refresh);
    refresh();
  }

  function initConfirmLinks() {
    $$('[data-viswiz-confirm]').forEach((link) => link.addEventListener('click', (event) => {
      if (!window.confirm(__('Delete this dataset and detach its visualizations?', 'viswiz'))) event.preventDefault();
    }));
  }

  initVisualizationConfig();
  initConfirmLinks();
})();
