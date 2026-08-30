(() => {
  'use strict';

  let sequence = 0;
  const instances = new Map();

  const editorApi = () => window.wp?.editor;

  function nextEditorId() {
    sequence += 1;
    return `viswiz_node_description_${Date.now()}_${sequence}`;
  }

  function prepareField(textarea) {
    if (!textarea.id) textarea.id = nextEditorId();
    textarea.dataset.viswizRichEditor = '1';

    const field = textarea.closest('label.viswiz-field');
    if (!field) return textarea.id;

    const wrapper = document.createElement('div');
    wrapper.className = `${field.className} viswiz-rich-editor-field`;
    const label = document.createElement('label');
    label.className = 'viswiz-rich-editor-label';
    label.htmlFor = textarea.id;
    const sourceLabel = field.querySelector(':scope > span')?.textContent || 'Description';
    label.textContent = sourceLabel.replace(/\s*\(safe HTML\)\s*/i, '') || 'Description';
    field.parentNode.insertBefore(wrapper, field);
    wrapper.append(label, textarea);
    field.remove();
    return textarea.id;
  }

  function sync(instance) {
    if (!instance || !instance.initialized) return;
    const api = editorApi();
    if (!api?.getContent) return;
    try {
      const content = api.getContent(instance.id);
      if (typeof content === 'string') instance.textarea.value = content;
    } catch (_) {
      // Keep the textarea value as the safe fallback if the core editor is unavailable.
    }
  }

  function cleanup(dialog) {
    const instance = instances.get(dialog);
    if (!instance) return;
    sync(instance);
    if (instance.initialized && editorApi()?.remove) {
      try {
        editorApi().remove(instance.id);
      } catch (_) {
        // The dialog can still close safely; a later removal observer clears bookkeeping.
      }
    }
    instances.delete(dialog);
    dialog.dataset.viswizRichEditorState = 'removed';
  }

  function enhance(dialog) {
    if (!(dialog instanceof HTMLDialogElement) || instances.has(dialog)) return;
    const textarea = dialog.querySelector('form textarea[name="description"]');
    if (!textarea) return;
    if (!dialog.open) {
      window.requestAnimationFrame(() => enhance(dialog));
      return;
    }

    const id = prepareField(textarea);
    const form = textarea.closest('form');
    const instance = { id, textarea, initialized: false };
    instances.set(dialog, instance);
    dialog.dataset.viswizRichEditorId = id;
    dialog.dataset.viswizRichEditorState = 'initializing';

    try {
      const api = editorApi();
      if (!api?.initialize) throw new Error('WordPress editor API unavailable');
      api.initialize(id, {
        tinymce: { wpautop: true },
        quicktags: true,
        mediaButtons: false,
      });
      instance.initialized = true;
      dialog.dataset.viswizRichEditorState = 'ready';
    } catch (_) {
      textarea.closest('.viswiz-rich-editor-field')?.classList.add('is-fallback');
      dialog.dataset.viswizRichEditorState = 'fallback';
    }

    // Capture before the graph editor's submit listener constructs FormData.
    form?.addEventListener('submit', () => sync(instance), true);

    // Capture before makeDialog() removes the dialog from the DOM.
    dialog.addEventListener('close', () => cleanup(dialog), { capture: true, once: true });
    dialog.addEventListener('cancel', () => cleanup(dialog), { capture: true, once: true });
    dialog.addEventListener('click', (event) => {
      if (event.target.closest('[data-cancel],.viswiz-dialog-close')) cleanup(dialog);
    }, true);
  }

  function scan(root = document) {
    if (root.matches?.('dialog.viswiz-editor-dialog')) enhance(root);
    root.querySelectorAll?.('dialog.viswiz-editor-dialog').forEach(enhance);
  }

  const observer = new MutationObserver((records) => {
    records.forEach((record) => {
      record.addedNodes.forEach((node) => {
        if (node.nodeType === Node.ELEMENT_NODE) scan(node);
      });
      record.removedNodes.forEach((node) => {
        if (node.nodeType !== Node.ELEMENT_NODE) return;
        const removedDialogs = [];
        if (node.matches?.('dialog.viswiz-editor-dialog')) removedDialogs.push(node);
        node.querySelectorAll?.('dialog.viswiz-editor-dialog').forEach((dialog) => removedDialogs.push(dialog));
        removedDialogs.forEach((dialog) => cleanup(dialog));
      });
    });
  });

  observer.observe(document.body, { childList: true, subtree: true });
  scan();
})();
