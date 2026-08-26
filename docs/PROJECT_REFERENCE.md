# VisWiz project reference

This file is the persistent engineering reference for VisWiz. It records the upstream documentation that should be consulted when implementation choices touch WordPress, WooCommerce, browser APIs, PHP, testing or release infrastructure.

It is deliberately separate from the README. The README describes what VisWiz does; this document records the technical reference baseline used to decide how it should be implemented.

## Reference policy

1. Prefer the official upstream documentation listed here over blog posts, snippets or historical implementation patterns.
2. When WordPress, WooCommerce, PHP or PHPUnit minimum versions change, review the corresponding references and this file in the same pull request.
3. When a VisWiz subsystem adopts a third-party runtime library, add its official documentation, version policy and license here before release.
4. Do not treat compatibility patches in the current codebase as architectural precedent. They are implementation history until consolidated into the primary modules.
5. User-facing documentation should describe stable behavior only. Experimental or rapidly changing behavior belongs in issues/backlog until stabilized.

## Current platform baseline

### WordPress

- Plugin Developer Handbook: https://developer.wordpress.org/plugins/
- REST API Handbook: https://developer.wordpress.org/rest-api/
- Block Editor Handbook: https://developer.wordpress.org/block-editor/
- Block API Reference: https://developer.wordpress.org/block-editor/reference-guides/block-api/
- Block metadata (`block.json`): https://developer.wordpress.org/block-editor/reference-guides/block-api/block-metadata/
- Block registration: https://developer.wordpress.org/block-editor/reference-guides/block-api/block-registration/
- Block API versions: https://developer.wordpress.org/block-editor/reference-guides/block-api/block-api-versions/
- Interactivity API: https://developer.wordpress.org/block-editor/reference-guides/interactivity-api/
- Common APIs / security / REST references: https://developer.wordpress.org/apis/

Project implications:

- The VisWiz block should remain server-registered from `block.json`.
- REST routes must use explicit permission callbacks and WordPress capabilities.
- Admin writes must continue to use WordPress nonces/capability checks or authenticated REST nonces as appropriate.
- WordPress-native UI primitives and editor components should be preferred when they improve accessibility and consistency.
- Because VisWiz requires WordPress 6.5+, the Interactivity API is available as an architectural option for future front-end consolidation, but adoption is not required merely for novelty.

### WooCommerce

- Orders overview: https://developer.woocommerce.com/docs/features/orders/
- `wc_get_orders()` and order queries: https://developer.woocommerce.com/docs/features/orders/wc-get-orders/
- HPOS overview: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/
- HPOS extension recipe book: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/
- HPOS order-query improvements: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/wc-order-query-improvements/
- Extension compatibility/interoperability: https://developer.woocommerce.com/docs/best-practices/compatibility

Project implications:

- Order access should continue through WooCommerce CRUD/query APIs rather than direct `wp_posts`, `wp_postmeta` or HPOS-table SQL.
- `wc_get_orders()` / `WC_Order_Query` is the reference query layer.
- HPOS compatibility must be explicitly declared and tested.
- Queries over potentially large order sets should remain bounded, paginated and cached.
- Product/category selection UX may use WordPress/WooCommerce APIs, but persisted visualization configuration should contain validated IDs rather than trusting arbitrary public query parameters.

### Browser platform APIs

- HTML `<dialog>` element: https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/dialog
- Fullscreen API: https://developer.mozilla.org/en-US/docs/Web/API/Fullscreen_API
- SVG: https://developer.mozilla.org/en-US/docs/Web/SVG
- Pointer events: https://developer.mozilla.org/en-US/docs/Web/API/Pointer_events
- MutationObserver: https://developer.mozilla.org/en-US/docs/Web/API/MutationObserver
- IntersectionObserver: https://developer.mozilla.org/en-US/docs/Web/API/Intersection_Observer_API

Project implications:

- Modal behavior must include reliable focus entry/return, Escape handling and accessible names.
- Graph interactions implemented as SVG must expose keyboard-equivalent actions for interactive elements.
- Mutation observers must observe the smallest practical structural surface and must not re-enter because of their own style/attribute mutations.
- Fullscreen behavior must preserve graph state and recover cleanly on exit or browser-level cancellation.

### PHP

- PHP manual: https://www.php.net/manual/en/
- Supported versions: https://www.php.net/supported-versions.php

VisWiz currently requires PHP 8.1+. New syntax must remain compatible with the minimum declared version unless the requirement is raised deliberately.

### PHPUnit

- PHPUnit 10.5 documentation: https://docs.phpunit.de/en/10.5/

The Composer development dependency is PHPUnit `^10.5`. Unit tests should focus on deterministic domain behavior; WordPress/WooCommerce integration belongs in the real WordPress smoke environment or browser-level tests.

### GitHub releases and CI

- REST API for releases: https://docs.github.com/en/rest/releases/releases
- GitHub Actions documentation: https://docs.github.com/en/actions

Release artifacts must keep the updater contract `viswiz-{version}.zip`. Release automation should validate the package before publication.

## Runtime dependency policy

At the 2.0.14 baseline, VisWiz has no third-party JavaScript visualization runtime and no public CDN dependency. Rendering is implemented locally in repository JavaScript/SVG/CSS.

This is intentional unless a future library provides a substantial, measured benefit in layout quality, accessibility, performance or maintainability. A new dependency should be evaluated for:

- license compatibility
- maintained release cadence
- bundle/runtime cost
- WordPress compatibility
- accessibility model
- graph-size performance
- extensibility
- migration cost from existing datasets/renderers

## Current architecture reference

### Canonical data layer

Datasets are authoritative. Visualizations store renderer/source/display configuration and reference canonical datasets rather than duplicating their data.

Dataset schemas:

- `categorical`
- `time_series`
- `xy`
- `geo`
- `progress`
- `graph`
- `diagram`

Graph node identity is UUID-based. Slugs and titles are editable presentation/domain fields and must not be used as relation identity.

### Persistence

The v2 database layer separates:

- datasets
- generic rows
- graph nodes
- graph edges/relations
- revisions

Targeted edits use optimistic concurrency. Explicit dataset replacement/import/restore is transactional and revisioned.

### Visualization layer

Renderers are separate from schemas. A renderer declares which schemas it supports. This separation should remain the basis of new visualization types.

### WordPress integration

- Visualization is a custom post type.
- Dataset management is a dedicated VisWiz admin surface.
- Publishing uses a dynamic Gutenberg block or shortcode.
- Public payloads are delivered through VisWiz REST routes.
- Administrators manage global node/relation schema and settings; Editors can edit visualizations and datasets.

### WooCommerce integration

WooCommerce is optional. It can provide live saved queries or snapshots for row-based datasets. The public request identifies a saved visualization; it does not expose arbitrary query construction to anonymous visitors.

## Product direction

The primary workflow target is:

> **Create/import data quickly → select a compatible visualization → adjust presentation → publish.**

The graph workflow adds:

> **Create/import nodes and relations quickly → validate → explore/filter/focus → publish.**

Architecture choices should be evaluated against those workflows. A technically flexible feature that makes routine data entry or visualization creation harder is not a product improvement.

## Documentation boundary

This reference is not the full user/developer manual. Full documentation should be produced after these areas stabilize:

- dataset import and merge semantics
- large-dataset editor behavior
- visualization creation workflow and preview
- graph interaction state model
- public/admin accessibility behavior
- extension hooks and renderer/plugin APIs
