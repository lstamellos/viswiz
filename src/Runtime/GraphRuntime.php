<?php
namespace VisWiz\Runtime;

final class GraphRuntime {
    private const SCRIPT_HANDLE = 'viswiz-graph-runtime';

    public static function register(): void {
        add_action( 'init', array( self::class, 'register_assets' ), 30 );
        add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_admin' ), 100 );
        add_action( 'wp_footer', array( self::class, 'enqueue_frontend_footer' ), 1 );
    }

    public static function register_assets(): void {
        /*
         * Graph CSS is attached to the primary VisWiz stylesheet because a
         * dynamic block/shortcode can enqueue the frontend assets after
         * wp_head. Keeping one style handle avoids a late standalone stylesheet.
         */
        if ( wp_style_is( 'viswiz-frontend', 'registered' ) ) {
            $css_file = VISWIZ_DIR . 'assets/viswiz-graph-runtime.css';
            if ( is_readable( $css_file ) ) {
                $css = file_get_contents( $css_file );
                if ( false !== $css && '' !== $css ) {
                    wp_add_inline_style( 'viswiz-frontend', $css );
                }
            }
            wp_add_inline_style( 'viswiz-frontend', self::modal_presentation_css() );
        }

        wp_register_script(
            self::SCRIPT_HANDLE,
            VISWIZ_URL . 'assets/viswiz-graph-runtime.js',
            array( 'viswiz-frontend' ),
            VISWIZ_VERSION,
            true
        );
        wp_add_inline_script( self::SCRIPT_HANDLE, self::modal_presentation_script(), 'after' );
    }

    private static function modal_presentation_css(): string {
        return <<<'CSS'
.viswiz-node-modal .viswiz-node-description>p,.viswiz-node-modal .viswiz-node-description>ul,.viswiz-node-modal .viswiz-node-description>ol,.viswiz-node-modal .viswiz-node-description>blockquote{display:block!important}.viswiz-node-modal .viswiz-node-description>p{margin:0 0 1em}.viswiz-node-modal .viswiz-node-description>p:last-child{margin-bottom:0}.viswiz-node-modal .viswiz-node-description>ul,.viswiz-node-modal .viswiz-node-description>ol{margin:.75em 0 1em;padding-left:1.5em}.viswiz-node-modal .viswiz-node-description>blockquote{margin:.75em 0 1em;padding-left:1em;border-left:3px solid #cbd5e1}.viswiz-related-list>.viswiz-related-group{margin:0 0 1em;list-style:none}.viswiz-related-list>.viswiz-related-group:last-child{margin-bottom:0}.viswiz-related-group-label{display:block;margin:0 0 .35em;font-weight:700}.viswiz-related-group-nodes{margin:0;padding-left:1.25em}
CSS;
    }

    private static function modal_presentation_script(): string {
        return <<<'JS'
(() => {
  'use strict';

  const groupRelatedNodes = (overlay) => {
    const list = overlay?.querySelector?.('.viswiz-related-list');
    if (!list || list.dataset.viswizGroupedRelations === '1') return;

    const groups = new Map();
    [...list.children].forEach((item) => {
      const relation = item.querySelector(':scope > .viswiz-related-relation');
      const link = item.querySelector(':scope > .viswiz-related-node-link');
      if (!relation || !link) return;
      const label = relation.textContent.replace(/:\s*$/, '').trim();
      if (!groups.has(label)) groups.set(label, []);
      groups.get(label).push(link);
    });
    if (!groups.size) return;

    const fragment = document.createDocumentFragment();
    groups.forEach((links, label) => {
      const group = document.createElement('li');
      group.className = 'viswiz-related-group';
      const heading = document.createElement('span');
      heading.className = 'viswiz-related-group-label';
      heading.textContent = label;
      const nodes = document.createElement('ul');
      nodes.className = 'viswiz-related-group-nodes';
      links.forEach((link) => {
        const item = document.createElement('li');
        item.appendChild(link);
        nodes.appendChild(item);
      });
      group.append(heading, nodes);
      fragment.appendChild(group);
    });

    list.replaceChildren(fragment);
    list.dataset.viswizGroupedRelations = '1';
  };

  const groupOpenModals = () => {
    document.querySelectorAll('.viswiz-modal-overlay:not(.viswiz-property-overlay)')
      .forEach(groupRelatedNodes);
  };

  let scheduled = 0;
  const schedule = () => {
    window.clearTimeout(scheduled);
    scheduled = window.setTimeout(groupOpenModals, 0);
  };

  document.addEventListener('click', schedule, true);
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') schedule();
  }, true);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', schedule, { once: true });
  else schedule();
})();
JS;
    }

    private static function enqueue_assets(): void {
        wp_enqueue_script( self::SCRIPT_HANDLE );
    }

    public static function enqueue_admin(): void {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        if ( 'viswiz-datasets' !== $page || ! isset( $_GET['dataset_id'] ) ) {
            return;
        }
        if ( ! wp_script_is( 'viswiz-frontend', 'enqueued' ) ) {
            return;
        }

        self::enqueue_assets();

        /*
         * viswiz-admin.js can execute before viswiz.js because the admin script
         * does not depend on the frontend renderer. Its first preview call then
         * correctly becomes a no-op. Recover that one initial graph preview
         * after the frontend + graph runtime scripts have loaded. Later editor
         * mutations call window.VisWiz.render() directly and are captured by
         * the runtime's shared spec bridge.
         */
        $bootstrap = <<<'JS'
(() => {
  const renderPreview = () => {
    const container = document.querySelector('[data-viswiz-inline-spec]');
    const payload = document.querySelector('#viswiz-dataset-payload');
    const editor = document.querySelector('#viswiz-dataset-editor');
    if (!container || !payload || !editor || editor.dataset.schema !== 'graph' || container.querySelector('.viswiz-graph-frame') || !window.VisWiz?.render) return;
    let data;
    try { data = JSON.parse(payload.textContent || '{}'); } catch (_) { return; }
    const spec = {
      id: `dataset-${Number(editor.dataset.datasetId || 0)}`,
      title: '',
      renderer: 'graph',
      schema: 'graph',
      source_type: 'dataset',
      settings: {
        primary_color: '#2563eb',
        secondary_color: '#64748b',
        text_color: '#111827',
        background_color: '#fff',
        show_graph_toolbar: true,
        show_graph_search: true,
        show_graph_filters: true,
        show_graph_zoom: true,
        show_relation_labels: true,
        show_node_images: true,
        show_type_badges: true,
        full_screen: false
      },
      data,
      meta: {}
    };
    window.VisWizGraphRuntime?.setSpec(container, spec);
    window.VisWiz.render(container, spec);
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', renderPreview, { once: true });
  else queueMicrotask(renderPreview);
})();
JS;
        wp_add_inline_script( self::SCRIPT_HANDLE, $bootstrap, 'after' );
    }

    public static function enqueue_frontend_footer(): void {
        if ( wp_script_is( 'viswiz-frontend', 'enqueued' ) ) {
            self::enqueue_assets();
        }
    }
}