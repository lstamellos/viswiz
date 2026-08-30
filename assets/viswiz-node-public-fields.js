(() => {
  'use strict';

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const TYPES = ['short', 'long', 'url', 'formatted'];
  const TYPE_LABELS = {
    short: 'Short text',
    long: 'Long text',
    url: 'URL',
    formatted: 'Formatted HTML',
  };
  const enhanced = new WeakSet();

  function parseMeta(textarea) {
    try {
      const value = JSON.parse(textarea.value || '{}');
      return value && !Array.isArray(value) && typeof value === 'object' ? value : {};
    } catch (_) {
      return null;
    }
  }

  function normalizedField(field = {}) {
    const type = TYPES.includes(String(field.type || '')) ? String(field.type) : 'short';
    return {
      label: String(field.label || field.key || ''),
      type,
      value: String(field.value || ''),
    };
  }

  function fieldValueControl(type, value = '') {
    const control = document.createElement(type === 'long' || type === 'formatted' ? 'textarea' : 'input');
    if (control.tagName === 'TEXTAREA') control.rows = type === 'formatted' ? 4 : 3;
    else control.type = type === 'url' ? 'url' : 'text';
    control.value = value;
    control.dataset.viswizPublicFieldValue = '1';
    control.setAttribute('aria-label', 'Public field value');
    return control;
  }

  function updateEmptyState(list) {
    const empty = list.parentElement?.querySelector('[data-viswiz-public-fields-empty]');
    if (empty) empty.hidden = list.children.length > 0;
  }

  function updateRowControls(list) {
    const rows = [...list.children];
    rows.forEach((row, index) => {
      const label = $('[data-viswiz-public-field-label]', row)?.value.trim() || `field ${index + 1}`;
      const up = $('[data-viswiz-public-field-up]', row);
      const down = $('[data-viswiz-public-field-down]', row);
      const remove = $('[data-viswiz-public-field-remove]', row);
      if (up) {
        up.disabled = index === 0;
        up.setAttribute('aria-label', `Move ${label} up`);
      }
      if (down) {
        down.disabled = index === rows.length - 1;
        down.setAttribute('aria-label', `Move ${label} down`);
      }
      if (remove) remove.setAttribute('aria-label', `Remove ${label}`);
    });
    updateEmptyState(list);
  }

  function makeFieldRow(list, field = {}) {
    const current = normalizedField(field);
    const row = document.createElement('div');
    row.className = 'viswiz-public-field-row';
    row.dataset.viswizPublicFieldRow = '1';

    const labelWrap = document.createElement('label');
    labelWrap.innerHTML = '<span>Label</span>';
    const labelInput = document.createElement('input');
    labelInput.type = 'text';
    labelInput.value = current.label;
    labelInput.dataset.viswizPublicFieldLabel = '1';
    labelWrap.appendChild(labelInput);

    const typeWrap = document.createElement('label');
    typeWrap.innerHTML = '<span>Type</span>';
    const select = document.createElement('select');
    select.dataset.viswizPublicFieldType = '1';
    TYPES.forEach((type) => {
      const option = document.createElement('option');
      option.value = type;
      option.textContent = TYPE_LABELS[type];
      option.selected = current.type === type;
      select.appendChild(option);
    });
    typeWrap.appendChild(select);

    const valueWrap = document.createElement('label');
    valueWrap.className = 'viswiz-public-field-value';
    valueWrap.innerHTML = '<span>Value</span>';
    let valueControl = fieldValueControl(current.type, current.value);
    valueWrap.appendChild(valueControl);

    const actions = document.createElement('div');
    actions.className = 'viswiz-public-field-actions';
    const up = document.createElement('button');
    up.type = 'button';
    up.className = 'button button-small';
    up.textContent = '↑';
    up.dataset.viswizPublicFieldUp = '1';
    const down = document.createElement('button');
    down.type = 'button';
    down.className = 'button button-small';
    down.textContent = '↓';
    down.dataset.viswizPublicFieldDown = '1';
    const remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'button button-small';
    remove.textContent = 'Remove';
    remove.dataset.viswizPublicFieldRemove = '1';
    actions.append(up, down, remove);

    row.append(labelWrap, typeWrap, valueWrap, actions);
    list.appendChild(row);

    select.addEventListener('change', () => {
      const previous = valueControl.value;
      const replacement = fieldValueControl(select.value, previous);
      valueControl.replaceWith(replacement);
      valueControl = replacement;
      replacement.focus();
    });
    labelInput.addEventListener('input', () => updateRowControls(list));
    up.addEventListener('click', () => {
      const previous = row.previousElementSibling;
      if (previous) list.insertBefore(row, previous);
      updateRowControls(list);
      labelInput.focus();
    });
    down.addEventListener('click', () => {
      const next = row.nextElementSibling;
      if (next) list.insertBefore(next, row);
      updateRowControls(list);
      labelInput.focus();
    });
    remove.addEventListener('click', () => {
      const focusTarget = row.nextElementSibling || row.previousElementSibling;
      row.remove();
      updateRowControls(list);
      if (focusTarget) $('[data-viswiz-public-field-label]', focusTarget)?.focus();
    });

    updateRowControls(list);
    return row;
  }

  function collectFields(list) {
    return $$('[data-viswiz-public-field-row]', list).map((row) => ({
      label: $('[data-viswiz-public-field-label]', row)?.value || '',
      type: $('[data-viswiz-public-field-type]', row)?.value || 'short',
      value: $('[data-viswiz-public-field-value]', row)?.value || '',
    })).filter((field) => field.value !== '');
  }

  function syncMeta(textarea, list) {
    const meta = parseMeta(textarea);
    if (meta === null) return;
    delete meta.public_fields;
    const fields = collectFields(list);
    if (fields.length) meta.public_fields = fields;
    textarea.value = JSON.stringify(meta, null, 2);
  }

  function makePublicFieldsSection(form, fields) {
    const section = document.createElement('section');
    section.className = 'viswiz-public-fields';
    section.dataset.viswizPublicFields = '1';

    const heading = document.createElement('div');
    heading.className = 'viswiz-section-heading';
    const copy = document.createElement('div');
    copy.innerHTML = '<h3>Public fields</h3><p class="description">Structured details shown in the public node information view. Order here is the public display order.</p>';
    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'button button-small';
    add.textContent = 'Add public field';
    add.dataset.viswizAddPublicField = '1';
    heading.append(copy, add);

    const empty = document.createElement('p');
    empty.className = 'viswiz-public-fields-empty';
    empty.dataset.viswizPublicFieldsEmpty = '1';
    empty.textContent = 'No public fields yet.';

    const list = document.createElement('div');
    list.className = 'viswiz-public-fields-list';
    list.dataset.viswizPublicFieldsList = '1';
    section.append(heading, empty, list);

    fields.forEach((field) => makeFieldRow(list, field));
    updateRowControls(list);
    add.addEventListener('click', () => {
      const row = makeFieldRow(list, { type: 'short' });
      $('[data-viswiz-public-field-label]', row)?.focus();
    });
    return { section, list };
  }

  function moveRawMetadataToAdvanced(textarea) {
    const label = textarea.closest('label');
    if (!label || label.closest('.viswiz-node-meta-advanced')) return label;
    const labelText = $('span', label);
    if (labelText) labelText.textContent = 'Additional metadata JSON';

    const details = document.createElement('details');
    details.className = 'viswiz-editor-advanced viswiz-node-meta-advanced';
    details.dataset.viswizNodeMetaAdvanced = '1';
    const summary = document.createElement('summary');
    summary.textContent = 'Advanced metadata';
    const description = document.createElement('p');
    description.className = 'description';
    description.textContent = 'Reserved for uncommon or integration-specific metadata. Public fields are managed above.';
    label.parentNode.insertBefore(details, label);
    details.append(summary, description, label);
    return details;
  }

  function enhanceDialog(dialog) {
    if (!dialog || enhanced.has(dialog)) return;
    const form = $('form.viswiz-dialog-form', dialog);
    if (!form || !$('[name="node_type"]', form)) return;
    const textarea = $('textarea[name="meta"]', form);
    if (!textarea) return;
    enhanced.add(dialog);

    const meta = parseMeta(textarea);
    const publicFields = Array.isArray(meta?.public_fields) ? meta.public_fields.map(normalizedField) : [];
    if (meta) {
      const advanced = { ...meta };
      delete advanced.public_fields;
      textarea.value = JSON.stringify(advanced, null, 2);
    }

    const advanced = moveRawMetadataToAdvanced(textarea);
    const editor = makePublicFieldsSection(form, publicFields);
    form.insertBefore(editor.section, advanced || $('.viswiz-dialog-actions', form));
    form.addEventListener('submit', () => syncMeta(textarea, editor.list), true);
  }

  function scan(root = document) {
    if (root.matches?.('dialog.viswiz-editor-dialog')) enhanceDialog(root);
    root.querySelectorAll?.('dialog.viswiz-editor-dialog').forEach(enhanceDialog);
  }

  const observer = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => {
      if (node.nodeType === Node.ELEMENT_NODE) scan(node);
    }));
  });

  scan();
  observer.observe(document.body, { childList: true, subtree: true });
})();
