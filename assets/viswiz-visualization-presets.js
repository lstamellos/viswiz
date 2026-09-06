(() => {
  'use strict';
  const { __ } = window.wp.i18n;

  const cfg = window.VisWizVisualizationPresets || {};
  const adminCfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  let presets = Array.isArray(cfg.presets) ? cfg.presets : [];

  function setStatus(status, message = '', error = false) {
    status.textContent = message;
    status.classList.toggle('is-error', error);
  }

  function rendererLabel(renderer) {
    return adminCfg.renderers?.[renderer]?.label || renderer;
  }

  function renderOptions(select, selectedId = '') {
    select.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = __('Select preset', 'viswiz');
    select.appendChild(placeholder);

    presets.forEach((preset) => {
      const option = document.createElement('option');
      option.value = preset.id || '';
      option.textContent = `${preset.name || ''} — ${rendererLabel(preset.renderer || '')}`;
      select.appendChild(option);
    });

    if (selectedId && presets.some((preset) => preset.id === selectedId)) select.value = selectedId;
  }

  function selectedPreset(select) {
    return presets.find((preset) => preset.id === select.value) || null;
  }

  function updateActionState(select, applyButton, deleteButton) {
    const selected = Boolean(selectedPreset(select));
    applyButton.disabled = !selected;
    deleteButton.disabled = !selected;
  }

  function collectSettings(configRoot) {
    const settings = {};
    $$('[name^="viswiz_settings["]', configRoot).forEach((field) => {
      const match = field.name.match(/^viswiz_settings\[([^\]]+)\]$/);
      if (!match) return;
      settings[match[1]] = field.type === 'checkbox' ? field.checked : field.value;
    });
    return settings;
  }

  async function request(action, payload = {}) {
    const body = new FormData();
    body.append('action', action);
    body.append('nonce', cfg.nonce || '');
    Object.entries(payload).forEach(([key, value]) => body.append(key, String(value)));

    const response = await fetch(cfg.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      body,
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || json?.success !== true) {
      throw new Error(json?.data?.message || __('The display preset change could not be saved.', 'viswiz'));
    }
    return json.data || {};
  }

  function boolValue(value) {
    return value === true || value === 1 || value === '1' || value === 'true' || value === 'yes' || value === 'on';
  }

  function applyPreset(preset, configRoot) {
    const renderer = $('[data-viswiz-renderer]', configRoot)?.value || '';
    const active = new Set(Array.isArray(adminCfg.renderers?.[renderer]?.settings) ? adminCfg.renderers[renderer].settings : []);
    let matched = 0;
    let changed = 0;
    let lastChanged = null;

    Object.entries(preset.settings || {}).forEach(([key, value]) => {
      if (!active.has(key)) return;
      const field = $(`[name="viswiz_settings[${key}]"]`, configRoot);
      if (!field) return;
      matched += 1;

      if (field.type === 'checkbox') {
        const next = boolValue(value);
        if (field.checked === next) return;
        field.checked = next;
      } else {
        const next = String(value ?? '');
        if (field.value === next) return;
        field.value = next;
      }

      changed += 1;
      lastChanged = field;
      field.dispatchEvent(new Event('input', { bubbles: true }));
    });

    if (lastChanged) lastChanged.dispatchEvent(new Event('change', { bubbles: true }));
    return { matched, changed };
  }

  function init() {
    const root = $('[data-viswiz-display-presets]');
    const configRoot = $('[data-viswiz-visualization-config]');
    if (!root || !configRoot || !cfg.ajaxUrl || !cfg.nonce) return;

    const select = $('[data-viswiz-preset-select]', root);
    const applyButton = $('[data-viswiz-preset-apply]', root);
    const deleteButton = $('[data-viswiz-preset-delete]', root);
    const nameInput = $('[data-viswiz-preset-name]', root);
    const saveButton = $('[data-viswiz-preset-save]', root);
    const status = $('[data-viswiz-preset-status]', root);
    if (!select || !applyButton || !deleteButton || !nameInput || !saveButton || !status) return;

    renderOptions(select, select.value);
    updateActionState(select, applyButton, deleteButton);

    select.addEventListener('change', () => updateActionState(select, applyButton, deleteButton));

    applyButton.addEventListener('click', () => {
      const preset = selectedPreset(select);
      if (!preset) return;
      const result = applyPreset(preset, configRoot);
      setStatus(
        status,
        result.matched > 0
          ? __('Preset applied to unsaved display settings.', 'viswiz')
          : __('This preset has no settings supported by the current renderer.', 'viswiz'),
        result.matched === 0
      );
    });

    saveButton.addEventListener('click', async () => {
      const name = nameInput.value.trim();
      if (!name) {
        setStatus(status, __('Enter a preset name.', 'viswiz'), true);
        nameInput.focus();
        return;
      }

      const renderer = $('[data-viswiz-renderer]', configRoot)?.value || '';
      saveButton.disabled = true;
      setStatus(status, __('Saving preset…', 'viswiz'));
      try {
        const data = await request('viswiz_visualization_preset_save', {
          name,
          renderer,
          settings: JSON.stringify(collectSettings(configRoot)),
        });
        presets = Array.isArray(data.presets) ? data.presets : [];
        renderOptions(select, data.preset_id || '');
        updateActionState(select, applyButton, deleteButton);
        nameInput.value = '';
        setStatus(status, __('Display preset saved.', 'viswiz'));
      } catch (error) {
        setStatus(status, error?.message || __('The display preset change could not be saved.', 'viswiz'), true);
      } finally {
        saveButton.disabled = false;
      }
    });

    deleteButton.addEventListener('click', async () => {
      const preset = selectedPreset(select);
      if (!preset || !window.confirm(__('Delete this display preset?', 'viswiz'))) return;

      deleteButton.disabled = true;
      applyButton.disabled = true;
      setStatus(status, __('Deleting preset…', 'viswiz'));
      try {
        const data = await request('viswiz_visualization_preset_delete', { preset_id: preset.id });
        presets = Array.isArray(data.presets) ? data.presets : [];
        renderOptions(select);
        updateActionState(select, applyButton, deleteButton);
        setStatus(status, __('Display preset deleted.', 'viswiz'));
      } catch (error) {
        setStatus(status, error?.message || __('The display preset change could not be saved.', 'viswiz'), true);
        updateActionState(select, applyButton, deleteButton);
      }
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
