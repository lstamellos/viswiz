(() => {
  'use strict';

  const config = window.VisWizAccessControl;
  if (!config || !window.wp?.i18n) return;
  const { __ } = window.wp.i18n;

  document.querySelectorAll('[data-viswiz-user-picker]').forEach((root, pickerIndex) => {
    const input = root.querySelector('[data-viswiz-user-search]');
    const suggestions = root.querySelector('[data-viswiz-user-results]');
    const selectedList = root.querySelector('[data-viswiz-selected]');
    const status = root.querySelector('[data-viswiz-user-status]');
    const mode = root.dataset.mode;
    const url = new URL(config.endpoint, window.location.href);
    url.searchParams.set('kind', root.dataset.kind);
    url.searchParams.set('object_id', root.dataset.objectId);

    let pendingTimer;
    let controller;
    let generation = 0;
    let candidates = [];
    let active = -1;

    const ids = () => new Set([...selectedList.querySelectorAll('[data-user-id]')]
      .map((item) => Number(item.dataset.userId)));
    const message = (value) => { status.textContent = value; };
    const hide = () => {
      suggestions.hidden = true;
      suggestions.replaceChildren();
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
      candidates = [];
      active = -1;
    };
    const cancel = () => {
      clearTimeout(pendingTimer);
      controller?.abort();
      controller = undefined;
      generation += 1;
    };
    const markActive = (index) => {
      active = index;
      [...suggestions.children].forEach((item, i) => {
        item.setAttribute('aria-selected', String(i === index));
      });
      if (index < 0) {
        input.removeAttribute('aria-activedescendant');
      } else {
        const item = suggestions.children[index];
        input.setAttribute('aria-activedescendant', item.id);
        item.scrollIntoView({ block: 'nearest' });
      }
    };
    const selected = (user) => {
      const li = document.createElement('li');
      li.dataset.userId = String(user.id);
      const label = document.createElement('span');
      label.textContent = user.name + ' ';
      const username = document.createElement('small');
      username.textContent = '@' + user.username;
      label.appendChild(username);

      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = mode === 'owner' ? 'viswiz_access[owner]' : 'viswiz_access[editors][]';
      hidden.value = String(user.id);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'button-link';
      remove.setAttribute('data-viswiz-remove-user', '');
      remove.setAttribute('aria-label', __('Remove', 'viswiz') + ' ' + user.name);
      remove.textContent = '×';

      li.append(label, hidden, remove);
      return li;
    };
    const choose = (user) => {
      if (!user || ids().has(user.id)) return;
      if (mode === 'owner') selectedList.replaceChildren();
      selectedList.appendChild(selected(user));
      cancel();
      hide();
      input.value = '';
      message('');
      input.focus();
    };

    const draw = (users) => {
      const excluded = ids();
      candidates = users.filter((user) => !excluded.has(Number(user.id)));
      suggestions.replaceChildren();
      if (!candidates.length) {
        hide();
        message(__('No matching users', 'viswiz'));
        return;
      }
      candidates.forEach((user, index) => {
        const option = document.createElement('li');
        option.id = 'viswiz-user-option-' + pickerIndex + '-' + index;
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', 'false');
        option.tabIndex = -1;
        option.textContent = user.name + ' (@' + user.username + ')';
        option.addEventListener('mousedown', (event) => event.preventDefault());
        option.addEventListener('click', () => choose(user));
        suggestions.appendChild(option);
      });
      suggestions.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      markActive(-1);
      message('');
    };

    const lookup = async (term) => {
      controller = new AbortController();
      const request = ++generation;
      const queryUrl = new URL(url);
      queryUrl.searchParams.set('search', term);
      message(__('Searching users…', 'viswiz'));
      try {
        const response = await fetch(queryUrl, {
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': config.nonce },
          signal: controller.signal,
        });
        if (!response.ok) throw new Error('User search failed');
        const result = await response.json();
        if (request !== generation) return;
        draw(Array.isArray(result) ? result : []);
      } catch (error) {
        if (request !== generation || error.name === 'AbortError') return;
        hide();
        message(__('Could not search users. Please try again.', 'viswiz'));
      }
    };

    input.addEventListener('input', () => {
      cancel();
      hide();
      const term = input.value.trim();
      if ([...term].length < 2) {
        message(term ? __('Type at least 2 characters', 'viswiz') : '');
        return;
      }
      pendingTimer = setTimeout(() => lookup(term), 260);
    });

    input.addEventListener('keydown', (event) => {
      if (suggestions.hidden || !candidates.length) {
        if (event.key === 'Escape') hide();
        return;
      }
      if (event.key === 'ArrowDown') {
        event.preventDefault();
        markActive((active + 1) % candidates.length);
      } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        markActive(active <= 0 ? candidates.length - 1 : active - 1);
      } else if (event.key === 'Enter') {
        event.preventDefault();
        if (active >= 0) choose(candidates[active]);
      } else if (event.key === 'Escape' || event.key === 'Tab') {
        hide();
      }
    });

    selectedList.addEventListener('click', (event) => {
      const remove = event.target.closest('[data-viswiz-remove-user]');
      if (!remove) return;
      remove.closest('[data-user-id]')?.remove();
      input.focus();
    });
    document.addEventListener('pointerdown', (event) => {
      if (!root.contains(event.target)) hide();
    });
    root.closest('form')?.addEventListener('submit', () => {
      cancel();
      hide();
    });
  });
})();
