(function (wp) {
  const { registerBlockType } = wp.blocks;
  const { useEffect, useMemo, useState } = wp.element;
  const { InspectorControls } = wp.blockEditor;
  const { Button, PanelBody, SelectControl, Spinner, TextControl } = wp.components;
  const apiFetch = wp.apiFetch;
  const el = wp.element.createElement;

  registerBlockType('viswiz/visualization', {
    title: 'VisWiz Visualization',
    icon: 'chart-bar',
    category: 'widgets',
    attributes: {
      visualizationId: {
        type: 'number',
        default: 0,
      },
    },
    edit: (props) => {
      const { attributes, setAttributes } = props;
      const [visualizations, setVisualizations] = useState([]);
      const [loading, setLoading] = useState(true);

      useEffect(() => {
        apiFetch({ path: '/viswiz/v1/visualizations' })
          .then((data) => setVisualizations(Array.isArray(data) ? data : []))
          .finally(() => setLoading(false));
      }, []);

      const options = useMemo(() => ([
        { label: 'Select a visualization', value: 0 },
        ...visualizations.map((item) => ({
          label: item.title || `Visualization #${item.id}`,
          value: item.id,
        })),
      ]), [visualizations]);

      const selected = visualizations.find((item) => parseInt(item.id, 10) === parseInt(attributes.visualizationId, 10));
      const shortcode = selected?.shortcode || (attributes.visualizationId ? `[viswiz_visualization id="${attributes.visualizationId}"]` : '');

      return el(
        'div',
        { className: 'viswiz-block-editor' },
        el(
          InspectorControls,
          null,
          el(
            PanelBody,
            { title: 'Visualization', initialOpen: true },
            loading
              ? el(Spinner, null)
              : el(SelectControl, {
                  label: 'Saved visualization',
                  value: attributes.visualizationId,
                  options,
                  onChange: (value) => setAttributes({ visualizationId: parseInt(value, 10) || 0 }),
                }),
            selected && selected.editUrl
              ? el(Button, { href: selected.editUrl, variant: 'secondary', target: '_blank' }, 'Edit visualization')
              : null
          )
        ),
        selected
          ? el(
              'div',
              { className: 'viswiz-block-editor-card' },
              el('strong', null, selected.title || `Visualization #${selected.id}`),
              el('span', { className: 'viswiz-block-editor-meta' }, `${selected.type || 'visualization'} · ${selected.source || 'manual'} data`),
              el(TextControl, { label: 'Shortcode', value: shortcode, readOnly: true }),
              el('p', null, 'This block will render the selected visualization on the front end.')
            )
          : el(
              'div',
              { className: 'viswiz-block-editor-card is-empty' },
              loading ? el(Spinner, null) : el('p', null, 'Select a VisWiz visualization from the block settings.')
            )
      );
    },
    save: () => null,
  });
})(window.wp);
