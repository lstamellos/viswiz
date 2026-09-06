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
.viswiz-a11y-status{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.viswiz-visualization .viswiz-graph-toolbar input[type="search"],.viswiz-visualization .viswiz-graph-toolbar select,.viswiz-visualization .viswiz-graph-tool,.viswiz-visualization .viswiz-fullscreen{background:#fff!important;color:#111827!important;border-color:#94a3b8!important}.viswiz-visualization .viswiz-graph-status{padding:4px 7px;border-radius:6px;background:#fff;color:#334155;opacity:1!important}.viswiz-connection-focus-bar{color:#111827!important}.viswiz-selected-facet{color:#111827!important}.viswiz-node-property-link,.viswiz-property-node-link,.viswiz-related-node-link{color:#1d4ed8!important}
.viswiz-visualization .viswiz-graph-toolbar input[type="search"]:focus-visible,.viswiz-visualization .viswiz-graph-toolbar select:focus-visible,.viswiz-visualization .viswiz-graph-tool:focus-visible,.viswiz-visualization .viswiz-fullscreen:focus-visible,.viswiz-selected-facet:focus-visible,.viswiz-node-property-link:focus-visible,.viswiz-property-node-link:focus-visible,.viswiz-related-node-link:focus-visible,.viswiz-property-select-in-graph:focus-visible,.viswiz-connection-hop:focus-visible,.viswiz-connection-focus-clear:focus-visible,.viswiz-focus-connections:focus-visible,.viswiz-modal-close:focus-visible,.viswiz-node-gallery-controls button:focus-visible{outline:3px solid #fff!important;outline-offset:2px!important;box-shadow:0 0 0 5px #111827!important}.viswiz-graph-node:focus-visible>rect:not(.viswiz-node-card-shade):not(.viswiz-node-card-title-panel){stroke:#111827!important;stroke-width:4!important}.viswiz-node-card-tag:focus-visible .viswiz-node-card-tag-bg{stroke:#fff!important;stroke-width:2.5!important}
@media(prefers-reduced-motion:reduce){.viswiz-visualization .viswiz-progress-fill,.viswiz-visualization .viswiz-node-card-tag-bg,.viswiz-visualization .viswiz-graph-node{transition:none!important}.viswiz-visualization{scroll-behavior:auto!important}}
CSS;
    }

    private static function modal_presentation_script(): string {
        return <<<'JS'
(() => {
  'use strict';

  const paragraphizeLegacyDescription = (description) => {
    if (!description || description.querySelector(':scope > p')) return;

    const blockTags = new Set(['UL', 'OL', 'BLOCKQUOTE', 'PRE', 'TABLE', 'FIGURE', 'DIV', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6']);
    const sourceNodes = [...description.childNodes];
    const hasContent = sourceNodes.some((node) => node.nodeType !== Node.TEXT_NODE || node.textContent.trim() !== '');
    if (!hasContent) return;

    const fragment = document.createDocumentFragment();
    let paragraph = null;

    const ensureParagraph = () => {
      if (!paragraph) paragraph = document.createElement('p');
      return paragraph;
    };

    const flushParagraph = () => {
      if (!paragraph) return;
      if (paragraph.textContent.trim() !== '' || paragraph.children.length) fragment.appendChild(paragraph);
      paragraph = null;
    };

    sourceNodes.forEach((node) => {
      if (node.nodeType === Node.ELEMENT_NODE && blockTags.has(node.tagName)) {
        flushParagraph();
        fragment.appendChild(node);
        return;
      }

      if (node.nodeType === Node.TEXT_NODE) {
        const chunks = node.textContent.split(/\n[ \t]*\n+/);
        chunks.forEach((chunk, index) => {
          if (index > 0) flushParagraph();
          if (!paragraph && chunk.trim() === '') return;
          ensureParagraph().appendChild(document.createTextNode(chunk));
        });
        return;
      }

      ensureParagraph().appendChild(node);
    });

    flushParagraph();
    if (fragment.childNodes.length) description.replaceChildren(fragment);
  };

  const normalizeDescriptionBlocks = (overlay) => {
    const description = overlay?.querySelector?.('.viswiz-node-description');
    if (!description) return;
    paragraphizeLegacyDescription(description);
    description.querySelectorAll(':scope > p, :scope > ul, :scope > ol, :scope > blockquote')
      .forEach((block) => block.style.setProperty('display', 'block', 'important'));
  };

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
        item.style.setProperty('text-align', 'left', 'important');
        link.style.setProperty('text-align', 'left', 'important');
        link.style.setProperty('white-space', 'normal');
        link.style.setProperty('max-width', '100%');
        link.style.setProperty('vertical-align', 'top', 'important');
        item.appendChild(link);
        nodes.appendChild(item);
      });
      group.append(heading, nodes);
      fragment.appendChild(group);
    });

    list.replaceChildren(fragment);
    list.dataset.viswizGroupedRelations = '1';
  };

  const visibleModalOverlays = () => [...document.querySelectorAll('.viswiz-modal-overlay[role="dialog"][aria-modal="true"]')]
    .filter((overlay) => overlay.isConnected && !overlay.hidden);

  const topModalOverlay = () => visibleModalOverlays().at(-1) || null;

  const focusableIn = (overlay) => [...(overlay?.querySelectorAll?.('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])') || [])]
    .filter((item) => !item.disabled && !item.hidden && item.getClientRects().length > 0);

  const ensureStatus = (container, className) => {
    if (!container) return null;
    let status = container.querySelector(`:scope > .${className}`);
    if (!status) {
      status = document.createElement('span');
      status.className = `viswiz-a11y-status ${className}`;
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.setAttribute('aria-atomic', 'true');
      container.appendChild(status);
    }
    return status;
  };

  const announce = (container, className, text) => {
    const status = ensureStatus(container, className);
    if (!status || !text) return;
    status.textContent = '';
    window.setTimeout(() => { if (status.isConnected) status.textContent = text; }, 0);
  };

  const enhanceA11y = (container) => {
    if (!container) return;
    const graphStatus = container.querySelector('.viswiz-graph-status');
    if (graphStatus) {
      graphStatus.setAttribute('role', 'status');
      graphStatus.setAttribute('aria-live', 'polite');
      graphStatus.setAttribute('aria-atomic', 'true');
    }
    const facetHost = container.querySelector('.viswiz-selected-facets');
    if (facetHost) {
      facetHost.setAttribute('aria-live', 'polite');
      facetHost.setAttribute('aria-atomic', 'false');
      facetHost.setAttribute('aria-relevant', 'additions removals text');
    }
    const focusBar = container.querySelector('.viswiz-connection-focus-bar');
    if (focusBar) focusBar.setAttribute('aria-atomic', 'true');
    const fullscreen = container.querySelector('.viswiz-fullscreen');
    if (fullscreen) {
      const active = document.fullscreenElement === container;
      fullscreen.setAttribute('aria-pressed', active ? 'true' : 'false');
      fullscreen.setAttribute('aria-label', fullscreen.textContent.trim());
      ensureStatus(container, 'viswiz-fullscreen-status');
    }
    ensureStatus(container, 'viswiz-graph-action-status');
  };

  const enhanceOpenModals = () => {
    document.querySelectorAll('.viswiz-modal-overlay:not(.viswiz-property-overlay)')
      .forEach((overlay) => {
        normalizeDescriptionBlocks(overlay);
        groupRelatedNodes(overlay);
      });
    document.querySelectorAll('.viswiz-visualization').forEach(enhanceA11y);
  };

  let scheduled = 0;
  const schedule = () => {
    window.clearTimeout(scheduled);
    scheduled = window.setTimeout(enhanceOpenModals, 0);
  };

  document.addEventListener('keydown', (event) => {
    const overlay = topModalOverlay();
    if (overlay && event.key === 'Escape') {
      event.preventDefault();
      event.stopImmediatePropagation();
      overlay.querySelector('.viswiz-modal-close')?.click();
      return;
    }
    if (overlay && event.key === 'Tab') {
      const focusable = focusableIn(overlay);
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (!overlay.contains(active)) {
        event.preventDefault();
        event.stopImmediatePropagation();
        first.focus();
      } else if (event.shiftKey && active === first) {
        event.preventDefault();
        event.stopImmediatePropagation();
        last.focus();
      } else if (!event.shiftKey && active === last) {
        event.preventDefault();
        event.stopImmediatePropagation();
        first.focus();
      }
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') schedule();
  }, true);

  document.addEventListener('click', (event) => {
    const propertyNode = event.target.closest?.('.viswiz-property-node-link');
    const propertyOverlay = propertyNode?.closest?.('.viswiz-property-overlay');
    if (propertyOverlay) {
      visibleModalOverlays()
        .filter((overlay) => overlay !== propertyOverlay && !overlay.classList.contains('viswiz-property-overlay'))
        .forEach((overlay) => overlay.querySelector('.viswiz-modal-close')?.click());
      return;
    }

    const facet = event.target.closest?.('.viswiz-selected-facet');
    if (facet) {
      const container = facet.closest('.viswiz-visualization');
      const label = facet.textContent.trim();
      queueMicrotask(() => {
        if (!facet.isConnected) {
          const fallback = container?.querySelector('.viswiz-clear-all-filters,input[type="search"]');
          try { fallback?.focus({ preventScroll: true }); } catch (_) { fallback?.focus(); }
          announce(container, 'viswiz-graph-action-status', label);
        }
      });
      return;
    }

    const clearFocus = event.target.closest?.('.viswiz-connection-focus-clear');
    if (clearFocus) {
      const container = clearFocus.closest('.viswiz-visualization');
      const root = container?.querySelector('.is-viswiz-connection-root');
      const label = clearFocus.getAttribute('aria-label') || clearFocus.title || clearFocus.textContent.trim();
      queueMicrotask(() => {
        const fallback = root?.isConnected ? root : container?.querySelector('input[type="search"],.viswiz-clear-all-filters');
        try { fallback?.focus({ preventScroll: true }); } catch (_) { fallback?.focus(); }
        announce(container, 'viswiz-graph-action-status', label);
      });
      return;
    }

    schedule();
  }, true);

  document.addEventListener('fullscreenchange', () => {
    queueMicrotask(() => {
      document.querySelectorAll('.viswiz-visualization').forEach((container) => {
        const button = container.querySelector('.viswiz-fullscreen');
        if (!button) return;
        const active = document.fullscreenElement === container;
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.setAttribute('aria-label', button.textContent.trim());
        if (active || container.dataset.viswizWasFullscreen === '1') {
          announce(container, 'viswiz-fullscreen-status', button.textContent.trim());
        }
        container.dataset.viswizWasFullscreen = active ? '1' : '0';
      });
    });
  });

  if ('MutationObserver' in window) {
    const observer = new MutationObserver((mutations) => {
      if (mutations.some((mutation) => mutation.addedNodes.length || mutation.removedNodes.length)) schedule();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
  }

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
