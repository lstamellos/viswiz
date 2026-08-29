(() => {
  'use strict';

  const root = document.querySelector('#viswiz-dataset-editor[data-viswiz-server-editor]');
  if (!root || root.dataset.schema === 'graph') return;

  const guardedSelectors = [
    '[data-viswiz-import-button]',
    '[data-viswiz-commerce-snapshot]',
    '[data-viswiz-restore-revision]',
  ];

  const metadataForm = document.querySelector('form input[name="action"][value="viswiz_dataset_update"]')?.closest('form') || null;
  let state = null;
  let lastServerMessage = '';

  const isDirty = () => Boolean(state && (state.saving || state.drafts?.size || state.deletes?.size));

  function notice(message, kind = 'warning') {
    if (!message) return;
    let box = root.querySelector('[data-viswiz-spreadsheet-hardening-notice]');
    if (!box) {
      box = document.createElement('div');
      box.dataset.viswizSpreadsheetHardeningNotice = '1';
      root.prepend(box);
    }
    box.className = `notice notice-${kind} inline viswiz-spreadsheet-hardening-notice`;
    box.innerHTML = '';
    const paragraph = document.createElement('p');
    paragraph.textContent = message;
    box.appendChild(paragraph);
  }

  function externalControls() {
    return guardedSelectors.flatMap((selector) => [...document.querySelectorAll(selector)]);
  }

  function syncControls() {
    if (!state) return;
    const dirty = isDirty();
    externalControls().forEach((control) => {
      if ('disabled' in control) control.disabled = dirty;
      control.setAttribute('aria-disabled', dirty ? 'true' : 'false');
      control.title = dirty ? 'Save or discard spreadsheet changes first.' : '';
    });
    if (metadataForm) {
      metadataForm.querySelectorAll('button[type="submit"],input[type="submit"]').forEach((control) => {
        control.disabled = dirty;
        control.title = dirty ? 'Save or discard spreadsheet changes first.' : '';
      });
    }

    const message = String(state.serverMessage || '').trim();
    if (message && message !== lastServerMessage) {
      lastServerMessage = message;
      notice(message, 'error');
    } else if (!message) {
      lastServerMessage = '';
      root.querySelector('[data-viswiz-spreadsheet-hardening-notice]')?.remove();
    }
  }

  function blockDirtyMutation(event) {
    if (!isDirty()) return false;
    event.preventDefault();
    event.stopImmediatePropagation();
    notice('Save or discard spreadsheet changes before changing the dataset from another control.', 'warning');
    syncControls();
    return true;
  }

  document.addEventListener('click', (event) => {
    const target = event.target.closest?.(guardedSelectors.join(','));
    if (target) blockDirtyMutation(event);
  }, true);

  if (metadataForm) {
    metadataForm.addEventListener('submit', (event) => {
      blockDirtyMutation(event);
    }, true);
  }

  const observer = new MutationObserver(() => queueMicrotask(syncControls));
  observer.observe(root, { childList: true, subtree: true });
  root.addEventListener('input', () => queueMicrotask(syncControls), true);
  root.addEventListener('click', () => queueMicrotask(syncControls), true);

  async function connect() {
    for (let attempt = 0; attempt < 200; attempt += 1) {
      state = root.__viswizSpreadsheetState || null;
      if (state) {
        syncControls();
        return;
      }
      await new Promise((resolve) => window.setTimeout(resolve, 25));
    }
  }

  connect();
})();
