(() => {
  'use strict';

  const editor = document.querySelector('#viswiz-dataset-editor[data-viswiz-server-editor]');
  if (!editor) return;

  let pendingInvoker = null;
  let pendingTimer = 0;
  let dialogSequence = 0;

  function actionName(button) {
    return String(button?.textContent || button?.getAttribute('aria-label') || '')
      .replace(/\s+/g, ' ')
      .trim()
      .toLowerCase();
  }

  function invokerKey(button) {
    if (!(button instanceof HTMLButtonElement)) return '';
    const action = actionName(button);
    const item = button.closest('[data-viswiz-item-uuid]');
    if (item?.dataset.viswizItemUuid) return `item:${item.dataset.viswizItemUuid}:${action}`;
    const relation = button.closest('[data-relation-uuid]');
    if (relation?.dataset.relationUuid) return `connected-relation:${relation.dataset.relationUuid}:${action}`;
    const panel = button.closest('[data-viswiz-node-relations]');
    if (panel?.dataset.viswizNodeRelations) return `node-panel:${panel.dataset.viswizNodeRelations}:${action}`;
    if (button.closest('.viswiz-editor-toolbar')) return `toolbar:${action}`;
    return '';
  }

  function rememberInvoker(event) {
    const button = event.target instanceof Element ? event.target.closest('button') : null;
    if (!(button instanceof HTMLButtonElement)) return;
    pendingInvoker = { element: button, key: invokerKey(button) };
    window.clearTimeout(pendingTimer);
    pendingTimer = window.setTimeout(() => {
      pendingInvoker = null;
    }, 0);
  }

  function matchingInvoker(key) {
    if (!key) return null;
    const candidates = [
      ...editor.querySelectorAll('button'),
      ...document.querySelectorAll('dialog.viswiz-editor-dialog[open] button'),
    ];
    return candidates.find((button) => !button.disabled && invokerKey(button) === key) || null;
  }

  function fallbackFocusTarget() {
    const search = document.querySelector('[data-viswiz-dataset-search]');
    if (search instanceof HTMLElement && !search.matches(':disabled')) return search;
    return editor.querySelector('.viswiz-editor-toolbar button:not(:disabled)');
  }

  function restoreFocus(context) {
    if (!context) return;
    const original = context.element;
    const target = (original?.isConnected && !original.disabled
      ? original
      : matchingInvoker(context.key)) || fallbackFocusTarget();
    if (!(target instanceof HTMLElement)) return;
    window.setTimeout(() => {
      if (target.isConnected && !target.matches(':disabled')) target.focus();
    }, 0);
  }

  function bindDialog(dialog) {
    if (!(dialog instanceof HTMLDialogElement) || dialog.dataset.viswizKeyboardBound === '1') return;
    dialog.dataset.viswizKeyboardBound = '1';

    const context = pendingInvoker;
    pendingInvoker = null;
    window.clearTimeout(pendingTimer);

    const heading = dialog.querySelector('.viswiz-dialog-heading h2');
    if (heading) {
      if (!heading.id) heading.id = `viswiz-editor-dialog-title-${++dialogSequence}`;
      dialog.setAttribute('aria-labelledby', heading.id);
    }
    dialog.setAttribute('aria-modal', 'true');

    dialog.addEventListener('click', rememberInvoker, true);
    dialog.addEventListener('close', () => restoreFocus(context), { once: true });
  }

  function bindNodePicker(picker) {
    if (!(picker instanceof HTMLElement) || picker.dataset.viswizKeyboardBound === '1') return;
    const search = picker.querySelector('input[type="search"][aria-label$=" node search"]');
    const select = picker.querySelector('select');
    if (!(search instanceof HTMLInputElement) || !(select instanceof HTMLSelectElement)) return;

    picker.dataset.viswizKeyboardBound = '1';
    search.addEventListener('keydown', (event) => {
      if (event.key !== 'Enter' || event.isComposing) return;

      // Enter has one unambiguous meaning in the endpoint search: choose the
      // available result. Keep it local so the surrounding form is not
      // submitted before a relation endpoint has been selected.
      event.preventDefault();
      if (!select.options.length) return;
      if (select.selectedIndex < 0) select.selectedIndex = 0;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      select.focus();
    });
  }

  function bindWithin(root) {
    if (!(root instanceof Element)) return;
    if (root.matches('dialog.viswiz-editor-dialog')) bindDialog(root);
    if (root.matches('.viswiz-node-picker')) bindNodePicker(root);
    root.querySelectorAll('dialog.viswiz-editor-dialog').forEach(bindDialog);
    root.querySelectorAll('.viswiz-node-picker').forEach(bindNodePicker);
  }

  editor.addEventListener('click', rememberInvoker, true);
  bindWithin(document.body);

  const observer = new MutationObserver((records) => {
    records.forEach((record) => {
      record.addedNodes.forEach((node) => {
        if (node instanceof Element) bindWithin(node);
      });
    });
  });
  observer.observe(document.body, { childList: true, subtree: true });
})();
