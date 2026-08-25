((wp) => {
  'use strict';
  const { registerBlockType } = wp.blocks;
  const { useEffect, useMemo, useState } = wp.element;
  const { InspectorControls } = wp.blockEditor;
  const { PanelBody, SelectControl, Button, Spinner, Notice } = wp.components;
  const { __ } = wp.i18n;
  const apiFetch = wp.apiFetch;
  const el = wp.element.createElement;

  registerBlockType('viswiz/visualization', {
    edit: ({ attributes, setAttributes }) => {
      const [items, setItems] = useState([]);
      const [loading, setLoading] = useState(true);
      const [error, setError] = useState('');
      useEffect(() => {
        apiFetch({ path: '/viswiz/v2/visualizations' })
          .then((data) => setItems(Array.isArray(data) ? data : []))
          .catch((err) => setError(err?.message || __('Could not load visualizations.', 'viswiz')))
          .finally(() => setLoading(false));
      }, []);
      const options = useMemo(() => [
        { label: __('Select visualization', 'viswiz'), value: 0 },
        ...items.map((item) => ({ label: `${item.title || `#${item.id}`} — ${item.renderer}`, value: item.id })),
      ], [items]);
      const selected = items.find((item) => Number(item.id) === Number(attributes.visualizationId));
      return el('div', { className: 'viswiz-block-editor' },
        el(InspectorControls, null,
          el(PanelBody, { title: __('Visualization', 'viswiz'), initialOpen: true },
            loading ? el(Spinner) : el(SelectControl, {
              label: __('Saved visualization', 'viswiz'),
              value: attributes.visualizationId,
              options,
              onChange: (value) => setAttributes({ visualizationId: Number(value) || 0 }),
            }),
            selected?.editUrl ? el(Button, { href: selected.editUrl, target: '_blank', variant: 'secondary' }, __('Edit visualization', 'viswiz')) : null
          )
        ),
        error ? el(Notice, { status: 'error', isDismissible: false }, error) : null,
        selected
          ? el('div', { className: 'viswiz-block-editor-card' },
              el('strong', null, selected.title || `#${selected.id}`),
              el('p', null, `${selected.renderer} · ${selected.status}`),
              el('code', null, selected.shortcode)
            )
          : el('p', null, loading ? __('Loading…', 'viswiz') : __('Choose a visualization in the block settings.', 'viswiz'))
      );
    },
    save: () => null,
  });
})(window.wp);
