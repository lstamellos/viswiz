(function (wp) {
  const { registerBlockType } = wp.blocks;
  const { useEffect, useState } = wp.element;
  const { InspectorControls } = wp.blockEditor;
  const { PanelBody, SelectControl, Spinner } = wp.components;
  const apiFetch = wp.apiFetch;

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
      const [options, setOptions] = useState([{ label: 'Select a visualization', value: 0 }]);
      const [loading, setLoading] = useState(true);

      useEffect(() => {
        apiFetch({ path: '/viswiz/v1/visualizations' })
          .then((data) => {
            const mapped = data.map((item) => ({
              label: item.title || `Visualization #${item.id}`,
              value: item.id,
            }));
            setOptions([{ label: 'Select a visualization', value: 0 }, ...mapped]);
          })
          .finally(() => setLoading(false));
      }, []);

      return (
        wp.element.createElement(
          'div',
          { className: 'viswiz-block-editor' },
          wp.element.createElement(
            InspectorControls,
            null,
            wp.element.createElement(
              PanelBody,
              { title: 'Visualization' },
              loading
                ? wp.element.createElement(Spinner, null)
                : wp.element.createElement(SelectControl, {
                    label: 'Saved Visualization',
                    value: attributes.visualizationId,
                    options,
                    onChange: (value) => setAttributes({ visualizationId: parseInt(value, 10) || 0 }),
                  })
            )
          ),
          wp.element.createElement(
            'p',
            null,
            attributes.visualizationId
              ? `Visualization ID: ${attributes.visualizationId}`
              : 'Select a visualization from the block settings.'
          )
        )
      );
    },
    save: () => null,
  });
})(window.wp);
