# VisWiz stabilization and usability backlog

Baseline: repository state at VisWiz 2.0.14, 2026-08-26. Status refreshed through VisWiz 2.0.20, 2026-08-30.

This backlog consolidates the remaining work around the product goal: **easy visualization creation and easy dataset editing/import**, without undoing the dataset-first storage model introduced in VisWiz 2.

Priority meanings:

- **P0** — required before the plugin should be treated as reliably usable for routine production work.
- **P1** — strong usability/maintainability improvement after the core path is stable.
- **P2** — useful extension once workflow and architecture have settled.

Pre-production datasets are disposable test fixtures rather than a backwards-compatibility contract. Legacy migration work is retained only when it saves meaningful setup/testing time and must not constrain the current data model.

## P0 — stabilize the current runtime

### 1. Consolidate graph compatibility layers — COMPLETED

Completed in VisWiz 2.0.15 / PR #81.

Current graph behavior was previously spread across the main renderer plus a dependency chain of compatibility/UX scripts. The stabilized behavior now uses one graph runtime/state model and the obsolete patch chain has been removed.

Regression target:

- one documented graph state model
- no duplicate/competing filter implementations
- no compatibility script whose sole purpose is to repair another compatibility script
- no self-triggering observer paths
- graph preview and public graph use the same stable behavior

### 2. Add real browser/end-to-end tests — COMPLETED

Implemented in PR #82 with Playwright/Chromium against a clean WordPress + MySQL installation. The browser suite supplements PHP/integration and source-level tests with actual editor/visitor interaction coverage.

Browser regression coverage includes:

- create/edit/delete row
- create/edit/delete node and relation
- dialog open/close and focus return
- Enter/Escape editor paths
- graph search and clear
- type/subtype facets and native graph filters
- relation filters
- property views
- related-node navigation while filters are active
- 1-hop/2-hop connection focus
- relation-aware connection focus
- zoom/reset/pan
- fullscreen enter/exit
- multiple visualizations on one page
- dynamically inserted visualization containers through the lazy-render path

### 3. Verify 1.x → 2.x migration with production-shaped data — DEFERRED / NOT A P0 BLOCKER

The existing datasets are pre-production test data. Preserving 1.x behavior is therefore not a product requirement and must not force compatibility code into the v2 model.

Migration validation may still be used as a developer convenience when it is faster than recreating useful test fixtures. Before any future legacy-table cleanup, a focused migration/backup check may be performed if those old tables are still worth retaining. This item does **not** block the remaining P0 usability work.

### 4. Replace JSON-only import as the normal import workflow — COMPLETED

Completed in VisWiz 2.0.16 / PR #83.

The normal import path is now a guided CSV/TSV/spreadsheet workflow with file upload or paste, practical encoding/delimiter detection, schema-aware column mapping, validation preview, append/upsert/replace modes, stable import keys, row-level issues and a summary before commit. Graph node/relation imports resolve stable external keys to canonical internal UUIDs. JSON replacement remains available as an advanced interchange/backup path.

Destructive replacement continues through the canonical revisioned dataset write path so the existing revision/snapshot safety model remains authoritative.

### 5. Make large dataset editing server-aware — COMPLETED

Completed in VisWiz 2.0.17 / PR #84.

The dataset detail editor now uses bounded server-backed collections instead of embedding the whole canonical payload in wp-admin. Rows, graph nodes and graph relations are paged and searched through authenticated REST endpoints, with `per_page` capped at 100 and WordPress-style pagination metadata.

Graph relation endpoints use lazy searchable node pickers, so nodes outside the current page can be selected without rendering the complete node set into a `<select>`. Targeted mutations retain the canonical revision/conflict checks and refetch only affected collections. Browser coverage includes 230-row and 130-node datasets, server-side search beyond the first page, targeted edits and lazy relation endpoint lookup.

### 6. Fix preview state ownership — RESOLVED BY ARCHITECTURE

Resolved by VisWiz 2.0.17 / PR #84.

The dataset detail editor no longer renders an automatic graph preview and no longer embeds the initial canonical payload. That removes the competing preview cache/state owner which originally motivated this item. Targeted row/node/relation mutations now update the server-aware editor state and refetch the affected bounded collection only; there is no stale dataset preview to synchronize after a write.

Do **not** reintroduce an automatic graph preview on the dataset editing surface merely to satisfy this historical item. A future live preview belongs to the visualization editor milestone (#13), where it must use the same public renderer/runtime and one explicit state/data-acquisition path.

## P0 — make creation/editing usable

### 7. Build a schema-aware dataset editor — COMPLETED

Completed in VisWiz 2.0.18 / PR #86.

Row-based dataset editing now follows a schema contract from `Registry::schemas()` rather than exposing every canonical storage column in one generic form. Normal editing surfaces are limited to the fields that belong to the active schema:

- categorical: label/value/color
- time series: date-time/value/label/color
- X/Y: X/Y/label/color
- geographic: latitude/longitude/label/value/color
- progress: label/current value/target/text/color
- diagram: section title/section text/accent color
- graph: remains the dedicated node/relation editor

Stable row keys and metadata outside the structured schema remain available under **Advanced** instead of occupying the normal workflow. Progress and diagram fields map to canonical structured metadata (`meta.target`, `meta.text`). Targeted row writes are independently validated server-side for required/numeric/geo constraints; time-series values derive `x_numeric` in the WordPress site timezone.

The editor remains server-paged and revision-checked. Chromium coverage creates and persists all six row schemas and verifies that invalid targeted writes are rejected even when browser validation is bypassed.

Spreadsheet-style inline editing, direct grid paste and richer cell-level keyboard movement were completed separately in VisWiz 2.0.19 / PR #87 and hardened in VisWiz 2.0.20 / PRs #89 and #88.

### 8. Spreadsheet-like editing and batch paste — COMPLETED

Completed in VisWiz 2.0.19 / PR #87, with post-review fixes and pre-test hardening in VisWiz 2.0.20 / PRs #89 and #88.

Row-based datasets now use a schema-aware editable grid with:

- Tab/Shift+Tab cell navigation
- arrow-key movement where appropriate
- Enter movement/row creation and Ctrl/Cmd+Enter explicit save
- multi-row tab/newline paste from spreadsheet software
- native newline-only paste preserved in textarea cells
- add/remove rows without modal churn, with Undo before save
- validation inline with the affected cell/row
- explicit saved/unsaved/saving/validation/conflict/error states
- one revision-checked atomic batch save instead of a per-cell autosave queue
- conflict discard/reload against the current paginated server revision
- protection against side mutations while spreadsheet drafts are dirty

The 2.0.20 hardening also applies the row schema contract consistently to legacy/raw external writes while preserving previously accepted row aliases (`x`, `y`, `lat`, `lng`) before validation. Full PHP 8.1/8.3, WordPress/WooCommerce, WordPress 6.5 minimum-platform and Chromium CI cover the stabilized path.

### 9. Improve graph node/relation editing

The graph editor should optimize repeated investigative data entry.

Add:

- searchable/autocomplete node selectors in relations
- create relation directly from a node context
- quick creation of a missing node while creating a relation
- relation-type defaults applied predictably
- clear validation when type/subtype constraints do not match
- visible incoming/outgoing relations in the node editor
- optional duplicate node / duplicate relation actions

### 10. Use a WordPress-native rich editor for node descriptions

Replace the safe-HTML textarea with an accessible WordPress-native rich-text editing experience. Preserve sanitized HTML through `wp_kses_post` and avoid reintroducing the previous editor initialization/modal lifecycle problems.

The editor must survive repeated modal open/close cycles and keyboard-only use.

### 11. Remove raw metadata JSON from normal workflows

Raw metadata can remain an advanced/debug interface, but commonly used metadata should have structured fields.

For graph nodes, provide a UI for public fields with:

- label
- type (`short`, `long`, `url`, `formatted`)
- value
- ordering
- add/remove/reorder

Reserve raw JSON for an explicitly marked advanced section.

## P1 — visualization creation workflow

### 12. Create visualization from dataset in one action

From a dataset page, add **Create visualization** and show only compatible renderer choices.

After creation, open a focused visualization editor with the dataset already connected.

### 13. Add live visualization preview to the visualization editor

The current configuration screen should show the actual renderer while display settings change.

Requirements:

- uses the same renderer/runtime as public output
- does not maintain a duplicate rendering implementation
- clearly distinguishes unsaved preview settings from saved state
- works for dataset and supported WooCommerce live sources

### 14. Simplify visualization settings by renderer

Do not show graph-only controls for charts or irrelevant controls for other renderer families.

Group settings into predictable sections such as:

- Data/source
- Appearance
- Labels/content
- Interaction
- Advanced

Use renderer-specific defaults rather than one expanding generic form.

### 15. Improve WooCommerce source selection

Replace raw Product ID / Category ID text fields with searchable product/category pickers.

Clarify the distinction between:

- **Live query** — recalculated/cached data
- **Snapshot** — data copied into a canonical dataset and then edited independently

### 16. Add visualization duplication and presets

Allow a visualization to be duplicated while reusing the same dataset. Later, consider reusable display presets only after settings stabilize.

## P1 — accessibility, localization and responsive behavior

### 17. Complete keyboard behavior for admin dialogs

Target behavior:

- native Tab/Shift+Tab focus order
- arrow keys in native selects/listboxes
- Enter commits the intended action where unambiguous
- Escape closes without saving
- focus returns to the invoking control
- destructive actions require an explicit confirmation path

Avoid global key handlers that override expected browser/form behavior.

### 18. Accessibility audit for public graph UI

Audit:

- accessible names/roles for SVG interaction targets
- keyboard activation of node cards and tags
- focus visibility
- dialog semantics/focus return
- screen-reader status updates for filtering/focus state
- color contrast
- reduced-motion behavior
- fullscreen state announcement

### 19. Centralize JavaScript localization

Compatibility scripts currently contain local English/Greek fallback strings and some hard-coded admin strings. Move user-visible strings into the WordPress translation pipeline and keep one i18n source per runtime.

### 20. Responsive/theme compatibility matrix

Test representative WordPress themes and widths for:

- graph toolbar wrapping
- fullscreen
- modals/galleries
- chart labels
- Gutenberg embedding
- multiple visualizations in constrained containers

## P1 — performance and observability

### 21. Establish performance budgets

Create representative benchmarks for:

- row datasets: 1k / 10k / 50k rows
- graphs: 100 / 500 / 1k / 5k nodes with realistic edge densities

Measure:

- admin page payload
- initial render time
- filter/search latency
- memory use
- layout/interaction responsiveness

Use measurements to decide whether SVG remains appropriate at every graph size or whether Canvas/WebGL/layout workers are needed for larger tiers.

### 22. Avoid duplicate data fetch/state derivation

Every visualization instance should have one payload acquisition path and one state owner. Compatibility/enhancement modules should receive state rather than independently refetching the visualization endpoint.

### 23. Add useful diagnostics

For administrators, expose concise diagnostics for failed visualization loads/imports/migrations without leaking sensitive query data publicly.

## P2 — extensibility after stabilization

### 24. Formalize renderer/schema extension APIs

`Registry` is currently static. Once core renderer behavior stabilizes, define supported WordPress filters/actions or registration APIs for:

- dataset schemas
- renderers
- display settings
- import adapters

Do not expose an extension API before its contracts are stable.

### 25. Additional import adapters

Only after CSV/paste import is mature, consider:

- remote CSV/JSON URL
- scheduled refresh
- Google Sheets or other connectors

These should map into canonical datasets rather than introduce a parallel data model.

### 26. Export/share formats

Potential later additions:

- CSV export for row datasets
- graph CSV pair (nodes/relations)
- SVG/PNG export where renderer semantics permit it

### 27. Legacy-table retirement policy

Legacy tables are not retained to satisfy a backwards-compatibility promise. If they are still useful when cleanup is considered, first verify that any wanted test fixtures/backups can be recovered or migrated. Cleanup must remain an explicit, versioned action rather than a silent routine-update side effect.

## Already implemented / no longer open requirements

The following earlier requirements are substantially present and should be treated as regression requirements rather than new backlog items:

- canonical dataset ownership of graph data
- targeted node/relation writes and revision conflict detection
- graph validation and revision restore
- full node-title wrapping on current graph cards
- node images and node detail modal/gallery
- customizable node-modal labels in visualization display settings
- public fullscreen control
- graph search/type/relation filtering
- multi-select type/subtype facets
- property views
- related-node navigation through filtered graphs
- graph zoom/reset/panning
- 1-hop/2-hop connection focus
- WordPress-native per-plugin auto-update preference with GitHub release package delivery
- automated release ZIP creation and WordPress/WooCommerce smoke testing
- Playwright/Chromium browser regression coverage for core editor and graph workflows

These behaviors remain regression requirements and should stay covered as the core runtime/editor is simplified further.
