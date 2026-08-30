# Node description rich editor — VisWiz 2.0.22

VisWiz 2.0.22 replaces the graph node description's safe-HTML textarea with the WordPress-native dynamic editor while keeping the existing server-aware graph editor as the only graph state and mutation owner.

## Architecture

`NodeRichEditor` is a narrowly scoped admin lifecycle component. It does not fetch datasets, mutate graph data, cache graph state, or replace `viswiz-dataset-editor.js`.

On graph dataset detail screens it:

1. calls `wp_enqueue_editor()` so WordPress loads the classic editor API and default settings;
2. loads `assets/viswiz-node-rich-editor.js` after the primary dataset editor;
3. enhances only node-dialog `textarea[name="description"]` fields after their native `<dialog>` has been inserted and opened.

The textarea remains the fallback and canonical form control. The dynamic WordPress editor adds Visual/Text modes around it.

## Lifecycle contract

TinyMCE instances must not outlive or be moved with their dialogs.

The lifecycle adapter therefore:

- creates a unique editor ID per node-dialog opening;
- initializes with `wp.editor.initialize()` only after the dialog exists and is open in the DOM;
- uses WordPress defaults with `wpautop`, Quicktags, and no extra media-button surface;
- synchronizes `wp.editor.getContent()` to the underlying textarea in capture phase before the primary editor constructs `FormData`;
- runs `wp.editor.remove()` before normal dialog removal on close/cancel paths;
- cleans up bookkeeping for unexpectedly removed dialogs;
- leaves the textarea usable if WordPress editor initialization is unavailable or fails.

This lifecycle also applies to node dialogs opened from relation endpoint quick-create.

## Data and security

There is no database migration and `VISWIZ_DB_VERSION` remains `20000`.

Description persistence still goes through `DatasetRepository::sanitize_node()`, then `Support::sanitize_html()`, which uses `wp_kses_post()`. The normalized node payload exposes the same sanitized value as both `description` and `description_html` for editor/public rendering compatibility.

## Regression requirements

The browser suite verifies that:

- the WordPress editor initializes in a node dialog;
- Visual/Text switching is keyboard operable;
- formatted content survives save/reopen;
- TinyMCE instances are removed after save, Escape, and close-button paths;
- repeated open/close cycles create fresh instances without stale editors.

Existing graph workflow E2E remains authoritative for relation quick-create and nested dialog behavior.
