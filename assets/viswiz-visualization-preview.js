(() => {
  'use strict';

  const cfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  let timer = 0;
  let controller = null;
  let requestId = 0;

  function values(prefix, root) {
    const result = {};
    $$(`[name^="${prefix}["]`, root).forEach((field) => {
      const match = field.name.match(new RegExp(`^${prefix}\\[([^\\]]+)\\]$`));
      if (!match) return;
      if (field.type === 'checkbox') result[match[1]] = field.checked;
      else result[match[1]] = field.value;
    });
    return result;
  }

  function config(root) {
    return {
      id: Number($('#post_ID')?.value || 0),
      title: $('#title')?.value || '',
      renderer: $('[data-viswiz-renderer]', root)?.value || 'pie',
      source_type: $('[data-viswiz-source]', root)?.value || 'dataset',
      dataset_id: Number($('[data-viswiz-dataset-select]', root)?.value || 0),
      settings: values('viswiz_settings', root),
      woo_config: values('viswiz_woo', root),
    };
  }

  function setStatus(status, message = '', error = false) {
    status.textContent = message;
    status.classList.toggle('is-error', error);
  }

  async function refresh(root, canvas, status) {
    const current = ++requestId;
    if (controller) controller.abort();
    controller = new AbortController();
    setStatus(status, 'Updating unsaved preview…');

    try {
      const response = await fetch(`${cfg.restUrl}/visualizations/preview`, {
        credentials: 'same-origin',
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || '',
        },
        body: JSON.stringify({ config: config(root) }),
        signal: controller.signal,
      });
      const spec = await response.json().catch(() => ({}));
      if (current !== requestId) return;
      if (!response.ok || spec?.code) throw new Error(spec?.message || `HTTP ${response.status}`);
      if (!window.VisWiz?.render) throw new Error('The public visualization renderer is unavailable.');

      window.VisWiz.render(canvas, spec);
      setStatus(status, 'Preview updated. These changes are still unsaved.');
    } catch (error) {
      if (error?.name === 'AbortError' || current !== requestId) return;
      canvas.replaceChildren();
      const message = document.createElement('p');
      message.className = 'viswiz-error';
      message.textContent = error?.message || 'Could not update the preview.';
      canvas.appendChild(message);
      setStatus(status, error?.message || 'Could not update the preview.', true);
    }
  }

  function schedule(root, canvas, status, delay = 260) {
    window.clearTimeout(timer);
    timer = window.setTimeout(() => refresh(root, canvas, status), delay);
  }

  function init() {
    const root = $('[data-viswiz-visualization-config]');
    const preview = $('[data-viswiz-live-preview]');
    const canvas = $('[data-viswiz-preview-canvas]', preview || document);
    const status = $('[data-viswiz-preview-status]', preview || document);
    if (!root || !preview || !canvas || !status || !cfg.restUrl) return;

    $$('input, select, textarea', root).forEach((field) => {
      field.addEventListener('input', () => schedule(root, canvas, status));
      field.addEventListener('change', () => schedule(root, canvas, status, 0));
    });
    const title = $('#title');
    if (title) title.addEventListener('input', () => schedule(root, canvas, status));

    schedule(root, canvas, status, 0);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
