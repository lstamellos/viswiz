# VisWiz 2.0.20 pre-test hardening

VisWiz 2.0.20 is a hardening-only release prepared after the 2.0.19 spreadsheet-editor milestone and before the next manual/live WordPress testing cycle.

No database migration is introduced. `VISWIZ_DB_VERSION` remains `20000`.

## Why this release exists

A full pre-install audit of 2.0.19 found that the new schema-aware editor paths were stricter than several older/advanced write paths. The spreadsheet editor, targeted editor endpoint and guided import already validated schema-specific requirements, but the legacy row REST route and raw row replacement path could still persist rows that did not satisfy the active dataset schema.

The same audit found two editor-safety gaps: side mutations remained available while spreadsheet drafts were unsaved, and generic REST/server failures were stored in spreadsheet state without a visible message.

## Canonical row write guard

`VisWiz\Domain\RowWriteGuard` is the shared gate for external row writes that previously bypassed `RowSchema`.

It applies `RowSchema::normalize_for_editor()` to each row and returns structured validation issues before any replacement write is attempted.

The following paths are covered:

- legacy authenticated `POST /viswiz/v2/datasets/{id}/rows`
- authenticated full row-dataset replacement `POST /viswiz/v2/datasets/{id}`
- WooCommerce snapshots before they replace a row dataset

Existing validated paths remain unchanged:

- targeted schema-aware editor writes
- spreadsheet batch writes
- guided CSV/TSV/spreadsheet import

Graph payloads continue through `GraphValidator` and are not routed through the row guard.

## WooCommerce snapshot compatibility

A WooCommerce snapshot is accepted only when the query result satisfies the destination dataset schema. A snapshot that lacks required fields for the selected schema returns a 422 `viswiz_snapshot_schema` response instead of replacing the dataset with structurally incompatible rows.

This preserves the distinction between the dataset schema and the source that produced the data.

## Unsaved spreadsheet draft protection

While the spreadsheet contains local drafts or a save is in progress, controls that can mutate the dataset outside the grid are disabled/guarded:

- raw JSON replacement
- WooCommerce snapshot replacement
- revision restore
- dataset metadata submit

The editor instructs the user to save or discard spreadsheet changes first. Normal grid Save/Discard and revision-conflict behavior remain authoritative.

## Visible generic errors

The spreadsheet hardening layer surfaces `serverMessage` as an inline WordPress admin notice. This covers generic network, HTTP and database failures that are not one of the editor's structured validation or revision-conflict cases.

Local drafts remain visible after a failed batch save.

## Regression coverage

Browser coverage now proves that:

- the legacy row REST route rejects a schema-invalid write
- raw full replacement rejects a schema-invalid row payload
- dirty spreadsheet drafts disable side mutations
- controls re-enable after Discard
- a simulated HTTP 500 batch-save failure is visible to the user and does not erase local draft values

Static/architecture tests verify that the external row write paths share `RowWriteGuard` and that the spreadsheet hardening asset is loaded.

## Minimum supported WordPress

CI now includes a dedicated WordPress 6.5 + PHP 8.1 smoke job in addition to the existing latest-WordPress integration/browser jobs. This verifies the declared minimum platform instead of assuming latest WordPress behavior is representative of 6.5.

## Deliberate non-goals

2.0.20 does not:

- add renderer types
- change the database schema
- redesign revision snapshot storage
- reintroduce a dataset graph preview
- change graph node/relation editing
- change the guided import workflow
- address repository branch-protection policy

Those remain separate concerns so the release can be evaluated as a small pre-test hardening step.
