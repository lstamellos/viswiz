# Graph runtime state contract

VisWiz 2.0.15 consolidates the post-render graph enhancement layers into one runtime: `assets/viswiz-graph-runtime.js`.

This file documents the internal contract so future graph changes do not recreate independent compatibility layers.

## One state owner per visualization

Each graph visualization has one `WeakMap` entry owned by the graph runtime. The state contains:

- the current graph `spec`
- the pending spec promise when a fallback fetch is required
- selected type/subtype facets
- connection-focus root UUID and hop depth
- pending node/modal navigation context
- toolbar/binding bookkeeping

Feature code must use `stateFor(container)` rather than create a feature-specific `WeakMap` for graph state.

## Spec ownership

The authoritative graph spec is supplied by the actual VisWiz render call whenever possible. The runtime wraps `window.VisWiz.render()` and stores the graph spec before enhancing the rendered DOM.

This is especially important for the dataset editor preview: `viswiz-admin.js` renders from its current in-memory dataset state after each revision. The runtime must use that live spec rather than re-reading the initial `#viswiz-dataset-payload` script.

Endpoint fetching and parsing the serialized admin payload are fallback paths only for an already-rendered graph whose render call happened before the runtime was able to bridge it.

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
