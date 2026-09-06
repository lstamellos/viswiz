# VisWiz stabilization and usability backlog

Baseline: repository state at VisWiz 2.0.14, 2026-08-26. Status refreshed through VisWiz 2.0.39, 2026-09-06.

This backlog tracks the remaining work around the product goal: **easy visualization creation and easy dataset editing/import**, while preserving the dataset-first storage model introduced in VisWiz 2.

Priority meanings:

- **P0** — required before the plugin should be treated as reliably usable for routine production work.
- **P1** — strong usability, accessibility, maintainability, performance and observability improvements after the core path is stable.
- **P2** — useful extensions once workflow and architecture have settled.

Pre-production datasets are disposable test fixtures rather than a backwards-compatibility contract. Legacy migration work is retained only when it saves meaningful setup/testing time and must not constrain the current data model.

## Current project status

The original P0 stabilization and editing/import milestones are complete. The visualization-creation workflow and public graph accessibility milestones are also complete through VisWiz 2.0.39. Phase C responsive/theme compatibility has been closed with browser coverage for graph and non-graph renderers, mobile/fullscreen/modal behavior, Gutenberg embedding and multiple visualizations in constrained containers.

The remaining stabilization work is now concentrated in:

1. JavaScript localization consolidation;
2. explicit verification/closure of single payload/state ownership after the recent editor/runtime additions;
3. representative performance budgets;
4. administrator-facing diagnostics.

P2 extensibility work should remain behind those remaining P1 quality milestones.

## P0 — stabilize the current runtime

### 1. Consolidate graph compatibility layers — COMPLETED

Completed in VisWiz 2.0.15 / PR #81.

The stabilized graph surface uses one graph runtime/state model. The obsolete chained compatibility/UX patch stack was removed. This remains a regression requirement: no duplicate filter implementations, no competing graph state owner, no self-triggering observer path.

### 2. Add real browser/end-to-end tests — COMPLETED

Completed in PR #82 with Playwright/Chromium against a clean WordPress + MySQL installation.

Coverage has since expanded across dataset editing, graph editing, public interaction, fullscreen, modals/galleries, multiple instances, responsive layouts, Gutenberg embedding and the visualization editor.

### 3. Verify 1.x → 2.x migration with production-shaped data — DEFERRED / NOT A P0 BLOCKER

The existing datasets are pre-production test data. Preserving 1.x behavior is not a product requirement and must not force compatibility code into the v2 model.

Before any future legacy-table cleanup, perform a focused migration/backup check only if the old tables still contain fixtures worth retaining.

### 4. Replace JSON-only import as the normal import workflow — COMPLETED

Completed in VisWiz 2.0.16 / PR #83.

Guided CSV/TSV/spreadsheet import supports file upload or paste, encoding/delimiter handling, schema-aware mapping, preview/validation, append/upsert/replace, stable import keys and graph endpoint resolution. JSON replacement remains an advanced interchange/backup path.

### 5. Make large dataset editing server-aware — COMPLETED

Completed in VisWiz 2.0.17 / PR #84.

Rows, graph nodes and graph relations use bounded authenticated REST collections with server-side paging/search. Relation endpoints use lazy searchable node lookup. Targeted writes remain revision-checked and refetch only affected bounded collections.

### 6. Fix preview state ownership — RESOLVED BY ARCHITECTURE

Resolved by VisWiz 2.0.17 / PR #84.

The dataset detail editor no longer embeds the complete canonical payload or owns an automatic graph preview. Visualization preview belongs to the visualization editor and now uses the real public renderer/runtime through the shared payload path implemented in #114.

## P0 — make creation/editing usable

### 7. Build a schema-aware dataset editor — COMPLETED

Completed in VisWiz 2.0.18 / PR #86.

Row editing is Registry-driven by schema. Normal forms expose only schema-relevant fields; stable keys/additional metadata remain under Advanced. Server-side RowSchema validation remains authoritative.

### 8. Spreadsheet-like editing and batch paste — COMPLETED

Completed in VisWiz 2.0.19 / PR #87, with post-review fixes and hardening in VisWiz 2.0.20 / PRs #89 and #88.

The schema-aware grid includes keyboard navigation, explicit revisioned batch save, spreadsheet range paste, native multiline textarea paste, Remove/Undo, inline validation, conflict retention/reload and protection against side mutations while drafts are dirty.

### 9. Improve graph node/relation editing — COMPLETED

Completed in VisWiz 2.0.21 / PR #91.

Includes node-context relation creation, incoming/outgoing relation visibility, quick-create missing endpoints without losing relation drafts, type/subtype warnings, duplicate node/relation actions and server-side relation filtering. `viswiz-dataset-editor.js` remains the canonical graph data/mutation owner.

### 10. Use a WordPress-native rich editor for node descriptions — COMPLETED

Completed in VisWiz 2.0.22 / PR #92.

Node descriptions use the WordPress dynamic editor API with Visual/Text modes, lifecycle-safe initialization/teardown and textarea fallback. Server-side `wp_kses_post` remains authoritative.

### 11. Remove raw metadata JSON from normal workflows — COMPLETED

Completed in VisWiz 2.0.23 / PR #93.

Node public fields have a structured editor using the existing `meta.public_fields` contract. Raw node/relation metadata is relegated to Advanced, while unrelated metadata remains preserved.

## Phase C — live frontend / responsive and theme compatibility

### Public modal and relation-grouping fixes — COMPLETED

Completed across PRs #98–#107 / VisWiz 2.0.26–2.0.30.

The production audit fixed and regression-covered:

- public rich-text paragraph/list semantics under hostile theme CSS;
- legacy paragraph-less rich text normalization;
- grouping related nodes by displayed relation label/type;
- left alignment of long wrapped related-node titles;
- alignment of native list markers with the first line of multiline related-node titles.

### Responsive/theme compatibility matrix — COMPLETED

Closed by PRs #108, #110 and #111, with the production fix released in VisWiz 2.0.31.

Browser coverage includes:

- graph toolbar/mobile containment;
- fullscreen enter/exit;
- node/property modals and galleries;
- long labels/titles;
- all 13 non-graph renderer modes in constrained mobile containers;
- real serialized Gutenberg embedding;
- multiple visualization instances in constrained containers;
- independence of per-instance graph/runtime state;
- no VisWiz-introduced document-level horizontal overflow.

## P1 — visualization creation workflow

### 12. Create visualization from dataset in one action — COMPLETED

Completed in PR #112 and released as VisWiz 2.0.32 / PR #113.

Dataset pages expose **Create visualization** with renderer choices derived from the canonical Registry schema contract. Creation produces a preconnected draft and redirects into the existing visualization editor.

### 13. Add live visualization preview to the visualization editor — COMPLETED

Completed in PR #114 and released as VisWiz 2.0.33 / PR #115.

The editor preview reuses the same `Frontend` payload builder, public `window.VisWiz.render()` implementation and graph runtime as published output. Unsaved preview state is explicitly distinguished from saved state.

### 14. Simplify visualization settings by renderer — COMPLETED

Completed in PR #116 and released as VisWiz 2.0.34 / PR #117.

Renderer capabilities in Registry drive setting applicability and defaults. Controls are grouped into Data/source, Appearance, Labels/content, Interaction and Advanced. Unsupported renderer/source combinations are rejected through the shared contract.

### 15. Improve WooCommerce source selection — COMPLETED

Completed in PR #118 and released as VisWiz 2.0.35 / PR #119.

WooCommerce Product/Category filters use native searchable SelectWoo controls when available and fall back to editable IDs otherwise. Live query vs one-time dataset snapshot semantics and permissions are explicit.

### 16. Add visualization duplication and presets — COMPLETED

Visualization duplication was completed in PR #120 and released as VisWiz 2.0.36 / PR #121.

Reusable personal display presets were completed in PR #123 and released as VisWiz 2.0.37 / PR #124. Presets store sanitized display settings only and never own renderer/source/dataset/canonical data state.

## P1 — accessibility, localization and responsive behavior

### 17. Complete keyboard behavior for admin dialogs — COMPLETED

Completed in PR #126 and released as VisWiz 2.0.38 / PR #127.

The admin dialog contract now includes:

- native Tab/Shift+Tab focus order and native dialog focus containment;
- native Escape cancellation without save;
- local relation endpoint Enter handling instead of a document-level key handler;
- focus restoration after cancel/save and after canonical editor rerenders;
- nested quick-create focus return to the still-open relation dialog;
- accessible dialog naming via `aria-labelledby` and modal semantics;
- stable fallback focus when the invoking row disappears under an active filter;
- explicit confirmation for destructive dataset editor actions.

### 18. Accessibility audit for public graph UI — COMPLETED

Completed in PR #128 and released as VisWiz 2.0.39 / PR #129.

The public graph runtime now has explicit accessibility regression coverage for:

- accessible names, button roles and Enter/Space activation for SVG graph nodes and type/subtype tags;
- visible focus treatment and theme-resilient contrast for plugin-owned controls;
- node/property modal semantics, initial focus, Tab/Shift+Tab containment, topmost Escape handling and focus return;
- replacement rather than stacking of node dialogs during property-to-node navigation;
- stable focus recovery when selected facet or connection-focus controls disappear;
- polite/atomic status semantics for graph results, facet/focus changes and fullscreen state;
- fullscreen `aria-pressed` state plus translated enter/exit announcements;
- `prefers-reduced-motion` handling for VisWiz-owned graph, tag and progress transitions;
- multiple visualization instances without ambiguous/global accessibility IDs.

### 19. Centralize JavaScript localization — OPEN / NEXT

Audit all current frontend/admin JavaScript user-visible strings. Move remaining hard-coded/fallback strings into the WordPress translation pipeline and keep one authoritative i18n source per runtime/adapter.

Do not introduce new compatibility-layer-local translation tables.

### 20. Responsive/theme compatibility matrix — COMPLETED

Closed by Phase C PRs #108, #110 and #111. Keep the established constrained-mobile/Gutenberg/multiple-instance browser matrix as regression coverage.

## P1 — performance and observability

### 21. Establish performance budgets — OPEN

Create representative benchmarks for:

- row datasets: 1k / 10k / 50k rows;
- graphs: 100 / 500 / 1k / 5k nodes with realistic edge densities.

Measure:

- admin page payload;
- initial render time;
- filter/search latency;
- memory use;
- layout/interaction responsiveness.

Use the measurements to decide whether SVG remains appropriate at every graph tier or whether larger tiers need Canvas/WebGL/layout workers or explicit supported-size limits.

### 22. Avoid duplicate data fetch/state derivation — IMPLEMENTED ARCHITECTURALLY / VERIFY AND CLOSE

The major architecture is already in place:

- #81 consolidated graph runtime state into one per-visualization owner;
- #84 removed competing dataset-editor preview state;
- #114 made visualization preview share the public payload builder and renderer/runtime;
- #116 kept renderer applicability in Registry rather than a second JavaScript capability map;
- #126 kept the keyboard layer lifecycle/event-only rather than a second editor state owner.

Before marking this item fully complete, perform one explicit source/architecture audit of the current 2.0.39 tree to verify that no newer adapter independently refetches visualization payloads or derives persistent competing state.

### 23. Add useful diagnostics — OPEN / PARTIALLY IMPLEMENTED

Current editors already surface validation, mutation, request and conflict failures in-context, including graph modal mutation errors from #94 and spreadsheet request/conflict states from #88/#89.

Remaining work: define a concise administrator diagnostics surface for failed public visualization loads, import failures and migration/legacy maintenance tasks without exposing sensitive query/data details publicly.

## P2 — extensibility after stabilization

### 24. Formalize renderer/schema extension APIs — OPEN

`Registry` is currently static. Once the remaining P1 quality milestones are closed, define supported WordPress filters/actions or registration APIs for dataset schemas, renderers, display settings and import adapters.

Do not expose an extension API before its contracts are stable.

### 25. Additional import adapters — OPEN

Only after the remaining stabilization work, consider remote CSV/JSON URL, scheduled refresh, Google Sheets or other connectors. These must map into canonical datasets rather than introduce a parallel data model.

### 26. Export/share formats — OPEN

Potential additions include CSV export for row datasets, graph CSV node/relation pairs and SVG/PNG export where renderer semantics permit it.

### 27. Legacy-table retirement policy — OPEN / DEFERRED

Legacy tables are not retained for a backwards-compatibility promise. If cleanup is later performed, first verify any wanted fixtures/backups and make retirement an explicit, versioned action rather than a silent routine-update side effect.

## Recommended next sequence

1. **#19 JavaScript localization consolidation**.
2. **#22 Explicit single-state/payload ownership verification and closure**.
3. **#21 Performance budgets and representative scale benchmarks**.
4. **#23 Administrator diagnostics**.
5. Reassess P2 #24–#27 only after the above are closed.

## Permanent regression requirements

The following are implemented behavior and must remain covered while the runtime/editor evolves:

- canonical dataset ownership of graph data;
- targeted writes, revision conflict detection and revision restore;
- guided import and schema-aware spreadsheet editing;
- lazy relation endpoint lookup and graph editor node/relation workflows;
- WordPress-native node rich editing and structured public fields;
- full node-title wrapping, images and node detail gallery;
- rich-text semantics under hostile theme CSS;
- grouped related-node navigation with correct long-title/bullet alignment;
- customizable node-modal labels;
- graph search/type/relation filters and multi-select type/subtype facets;
- property views and related-node navigation through filtered graphs;
- graph zoom/reset/panning and 1-hop/2-hop connection focus;
- fullscreen across graph and non-graph renderers;
- public graph accessibility semantics, modal/focus lifecycle, live status, reduced-motion behavior and fullscreen state announcements;
- constrained-mobile and Gutenberg embedding behavior;
- multiple visualization instances with independent state;
- dataset → visualization one-action creation;
- live visualization preview using the public renderer/runtime;
- renderer-specific settings contract;
- WooCommerce live-query/snapshot UX;
- visualization duplication and personal display presets;
- admin dialog keyboard/focus behavior;
- WordPress-native per-plugin auto-update preference with GitHub release package delivery;
- automated release ZIP creation and WordPress/WooCommerce/minimum-platform/Chromium CI.
