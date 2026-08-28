# Server-aware dataset editor

This document describes the bounded admin editing model introduced after the 2.0.16 import workflow.

## Goal

Opening or searching a dataset in wp-admin must not require loading its full canonical payload into the browser. The editor should remain usable as datasets grow while preserving the existing UUID identity model, targeted writes and optimistic revision locking.

## Read model

The dataset detail page does not call `DatasetRepository::get_payload()` and does not embed `#viswiz-dataset-payload`.

Editor collections are loaded from authenticated REST endpoints:

- `GET /viswiz/v2/datasets/{id}/rows`
- `GET /viswiz/v2/datasets/{id}/nodes`
- `GET /viswiz/v2/datasets/{id}/relations`
- `GET /viswiz/v2/datasets/{id}/nodes/options`

Rows, nodes and relations support:

- `page`
- `per_page`, capped at 100
- `search`

The response body contains only the requested records. Collection metadata follows the WordPress REST convention:

- `X-WP-Total`
- `X-WP-TotalPages`

VisWiz additionally returns the resolved page and page size in `X-VisWiz-Page` and `X-VisWiz-Per-Page`.

Official references:

- WordPress REST pagination: https://developer.wordpress.org/rest-api/using-the-rest-api/pagination/
- Adding custom REST endpoints: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
- `register_rest_route()`: https://developer.wordpress.org/reference/functions/register_rest_route/

## Write model

The server-aware editor keeps the canonical `DatasetRepository` targeted write methods. The editor-specific REST write routes return only dataset/revision metadata, then the browser reloads the affected collection page.

This means browser memory and network responses no longer receive the full payload after each normal row/node/relation edit.

The canonical repository still stores complete revision snapshots. That is deliberately outside this change: revision storage is a persistence/history concern and should be redesigned separately if measured dataset sizes make full snapshots expensive. It must not be solved by weakening revision safety inside the editor pagination change.

## Graph editing

The node table and relation table are independently paged.

Node degree is calculated server-side for the requested node page using the existing endpoint indexes. Relation rows are joined to their endpoint titles/slugs so the browser does not need a complete node map merely to render a relation row.

Relation endpoint selection uses lazy node lookup rather than a select containing every node in the dataset. Each endpoint picker:

- loads a bounded result set;
- searches title, label, slug, UUID, type and subtype;
- can resolve nodes outside the currently visible node page;
- supports moving from the search box to the native result select with Arrow Down and returning with Escape.

The complete keyboard workflow remains a separate usability item, but new large-dataset controls must not require a mouse.

## Initial page behavior

The detail page intentionally does not render the automatic full graph preview. Loading a full graph merely to edit one record defeats the bounded editor model and also conflicts with the earlier product direction to remove unnecessary editing previews.

Targeted graph writes enforce registered node types/subtypes and valid relation endpoints. Full graph replacement/import continues through canonical graph validation. The detail page performs only a lightweight orphan-endpoint integrity count.

## Search semantics

Search is server-side and resets the relevant collections to page 1. It can therefore find records that were not part of the first page loaded into the browser.

The current SQL search is intentionally simple and bounded to a dataset. Existing dataset/order/type/endpoint indexes are retained, so this release does not require a DB schema bump. If production-scale measurements later show text search to be a bottleneck, indexing/search strategy should be changed based on observed query plans rather than speculative schema changes.

## Regression contract

Chromium E2E coverage should prove at minimum:

1. datasets larger than 100 records render only the first page;
2. next/previous page controls request server pages;
3. search finds an item outside the first page;
4. targeted edits update the revision and refetch only affected collections;
5. graph relation endpoints can select a node outside the visible node page;
6. the detail page contains no embedded full dataset payload;
7. existing CSV/TSV/paste import workflows continue to operate on the same detail page.
