(() => {
  'use strict';

  const cfg = window.VisWizAdminV2 || {};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const GROUPS = {
    appearance: ['primary_color', 'secondary_color', 'text_color', 'background_color'],
    labels: [
      'target',
      'show_legend',
      'node_modal_title_fallback',
      'node_modal_close_label',
      'node_modal_previous_image_label',
      'node_modal_next_image_label',
      'node_modal_related_heading',
      'node_modal_relation_fallback',
      'show_node_images',
      'show_type_badges',
      'show_relation_labels',
    ],
    interaction: ['full_screen', 'show_graph_toolbar', 'show_graph_search', 'show_graph_filters', 'show_graph_zoom'],
    advanced: ['refresh_ms'],
  };

  function section(key, title) {
    const node = document.createElement('section');
    node.className = 'viswiz-config-section viswiz-renderer-settings-section';
    node.dataset.viswizSettingsSection = key;
    const heading = document.createElement('h3');
    heading.textContent = title;
    node.appendChild(heading);
    return node;
  }

  function settingLabel(root, key) {
    const input = $(`[name="viswiz_settings[${key}]"]`, root);
    if (!input) return null;
    const label = input.closest('label');
    if (label) label.dataset.viswizSetting = key;
    return label;
  }

  function settingsGrid(root, keys) {
    const grid = document.createElement('div');
    grid.className = 'viswiz-form-grid';
    keys.forEach((key) => {
      const label = settingLabel(root, key);
      if (label) grid.appendChild(label);
    });
    return grid;
  }

  function settingsChecks(root, keys) {
    const checks = document.createElement('div');
    checks.className = 'viswiz-checks';
    keys.forEach((key) => {
      const label = settingLabel(root, key);
      if (label) checks.appendChild(label);
    });
    return checks;
  }

  function buildSections(root) {
    if (root.dataset.viswizRendererSettingsGrouped === '1') return;

    const firstGrid = $(':scope > .viswiz-form-grid', root);
    const sourcePanels = $$(':scope > [data-viswiz-source-panel]', root);
    const configSections = $$(':scope > .viswiz-config-section', root);
    const displaySection = configSections.find((candidate) => candidate.querySelector('[name^="viswiz_settings["]'));
    const embedSection = configSections.find((candidate) => candidate.querySelector('code'));
    if (!firstGrid || !displaySection) return;

    const dataSection = section('data', 'Data / source');
    dataSection.appendChild(firstGrid);
    sourcePanels.forEach((panel) => dataSection.appendChild(panel));

    const appearance = section('appearance', 'Appearance');
    appearance.appendChild(settingsGrid(root, GROUPS.appearance));

    const labels = section('labels', 'Labels / content');
    labels.appendChild(settingsGrid(root, GROUPS.labels.filter((key) => !key.startsWith('show_'))));
    labels.appendChild(settingsChecks(root, GROUPS.labels.filter((key) => key.startsWith('show_'))));

    const interaction = section('interaction', 'Interaction');
    interaction.appendChild(settingsChecks(root, GROUPS.interaction));

    const advanced = section('advanced', 'Advanced');
    advanced.appendChild(settingsGrid(root, GROUPS.advanced));
    const refresh = settingLabel(root, 'refresh_ms');
    if (refresh) refresh.dataset.viswizSourceSetting = 'woo_live';
    if (embedSection) {
      const code = embedSection.querySelector('code');
      if (code) {
        const embed = document.createElement('p');
        embed.className = 'viswiz-embed-code';
        embed.appendChild(code);
        advanced.appendChild(embed);
      }
    }

    displaySection.before(dataSection, appearance, labels, interaction, advanced);
    displaySection.remove();
    if (embedSection) embedSection.remove();
    root.dataset.viswizRendererSettingsGrouped = '1';
  }

  function refresh(root) {
    const renderer = $('[data-viswiz-renderer]', root);
    const source = $('[data-viswiz-source]', root);
    if (!renderer || !source) return;

    const meta = cfg.renderers?.[renderer.value] || {};
    const active = new Set(Array.isArray(meta.settings) ? meta.settings : []);

    $$('[data-viswiz-setting]', root).forEach((label) => {
      const key = label.dataset.viswizSetting || '';
      if (key === 'refresh_ms') {
        label.hidden = source.value !== 'woo_live';
        return;
      }
      label.hidden = !active.has(key);
    });

    const wooOption = [...source.options].find((option) => option.value === 'woo_live');
    if (wooOption) wooOption.disabled = meta.woo_live !== true;
    if (meta.woo_live !== true && source.value === 'woo_live') source.value = 'dataset';

    $$('[data-viswiz-source-panel]', root).forEach((panel) => {
      panel.hidden = panel.dataset.viswizSourcePanel !== source.value;
    });

    $$('[data-viswiz-settings-section]', root).forEach((group) => {
      if (group.dataset.viswizSettingsSection === 'data') {
        group.hidden = false;
        return;
      }
      const visibleControls = $$('label[data-viswiz-setting]', group).some((label) => !label.hidden);
      const hasEmbed = Boolean(group.querySelector('.viswiz-embed-code'));
      group.hidden = !visibleControls && !hasEmbed;
    });
  }

  function init() {
    const root = $('[data-viswiz-visualization-config]');
    if (!root || !cfg.renderers) return;
    buildSections(root);
    const renderer = $('[data-viswiz-renderer]', root);
    const source = $('[data-viswiz-source]', root);
    renderer?.addEventListener('change', () => refresh(root));
    source?.addEventListener('change', () => refresh(root));
    refresh(root);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
