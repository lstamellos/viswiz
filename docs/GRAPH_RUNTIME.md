# Graph runtime state contract

VisWiz 2.0.15 consolidates the post-render graph enhancement layers into one runtime: `assets/viswiz-graph-runtime.js`.

This file documents the internal contract so future graph changes do not recreate independent compatibility layers.

## One state owner per visualization

Each graph visualization has one `WeakMap` entry owned by the graph runtime. The state contains:

- the current graph `spec`
- selected type/subtype facets
- connection-focus root UUID and hop depth
- pending node/modal navigation context
- toolbar/binding bookkeeping

Feature code must use `stateFor(container)` rather than create a feature-specific `WeakMap` for graph state.

## Spec ownership

The authoritative graph spec is supplied by the actual VisWiz render call whenever that call is exposed through `window.VisWiz.render()`. The runtime bridges that API and stores the graph spec before enhancing the rendered DOM.

This is especially important for the dataset editor preview: `viswiz-admin.js` renders from its current in-memory dataset state after each revision. The runtime therefore uses that live spec rather than independently caching the initial `#viswiz-dataset-payload` script.

For a public graph rendered before the bridge can observe its render call, the base frontend renderer exposes its last successfully rendered spec reference through `window.VisWiz.getRenderedSpec(container)`. The graph runtime adopts that same object into its per-container state, without requesting the public visualization REST endpoint again. Public payload fetching belongs only to the base frontend loader. Parsing the serialized admin payload remains a fallback for an already-rendered legacy inline preview. Regardless of acquisition path, all graph features consume the resulting spec from the same per-container runtime state.

## State application order

After a base graph render/redraw:

1. node cards are enhanced from the current visible node set
2. toolbar/header controls are normalized
3. selected property facets are reapplied
4. connection focus is reapplied
5. node modals are enhanced from the full canonical graph spec

Native graph search, node-type and relation-type controls continue to own the base renderer redraw. Facet/focus state survives those redraws in the shared runtime state.

## Observers

The persistent document-level `MutationObserver` watches structural child additions/removals only. It does not observe style or attribute mutations, which prevents the runtime from re-entering because it changed opacity, classes, SVG presentation or accessibility attributes itself.

Short-lived local observers are allowed only for bounded navigation operations such as waiting for a temporarily filtered node to be redrawn or waiting for the new node modal to be inserted.

## Modal context

Node modals may be portaled to `document.body` (or the active fullscreen owner), but retain `__viswizOwner` so they can resolve data and graph state from their originating visualization.

Related-node navigation resolves relations and nodes from the full graph spec. If a target is hidden by text/node-type filters, those base visibility filters are temporarily cleared, the target is opened, and the previous filter values are restored.

## Extension rule

New graph interaction features should be implemented in the consolidated runtime or, once a formal extension API exists, through that documented API. Do not add another chained compatibility script to repair or override graph state owned elsewhere.


## Dynamic overview and dense rendering (October 2026)

The base graph renderer derives its initial SVG `viewBox` from the actual
layout bounds with padding. It no longer uses a fixed 1000 by 1100 footprint.
The **100%** reset restores this fitted overview. Layouts use bounded linear
traversals for directed flow/org data (including cycles), and non-overlapping
grid spacing for free graphs.

Above 240 visible nodes, public graph rendering switches to compact SVG markers
with per-node keyboard interaction and modal access. It omits expensive card
cover images, clip paths, node labels, relation labels and arrows in the
overview. Native search/type filters redraw the reduced result as full node
cards when the filtered count is 240 or less; detail modals still draw their
contents from the full graph spec. The shared graph runtime skips the expensive
node-card enhancement for compact views and resolves facet state from the marker
attributes. It never launches a second REST request.

Large non-graph row datasets are rendered with bounded DOM: categorical/card
renderers use numbered range paging; dense line/area paths use min/max sampling
that preserves extrema; scatter and map plots use resolution-bounded canvas
above 1500 points. The complete underlying `spec.data` remains available in
memory; these client-side measures do **not** reduce REST payload size,
server-side query cost or the CPU spent parsing very large JSON responses.

Limitations: compact overviews deliberately omit in-place card imagery and edge
labels; dense canvas does not expose a keyboard-focusable point per datum.
These are density accommodations, not new visualization types.
See `tests/e2e/public-large-renderers.spec.js` and
`tests/e2e/performance-budgets.spec.js` for regression and scale checks.
