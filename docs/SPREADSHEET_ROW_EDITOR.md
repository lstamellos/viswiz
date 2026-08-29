# Spreadsheet-like row dataset editing

VisWiz 2.0.19 replaces modal churn for routine row-dataset editing with an inline spreadsheet-style grid while keeping the server-aware collection model introduced in 2.0.17 and the schema-aware fields introduced in 2.0.18.

## Scope

The spreadsheet surface applies only to row-based schemas:

- categorical
- time series
- X/Y
- geographic
- progress
- diagram

Graph datasets keep the dedicated node/relation editor. The spreadsheet asset is loaded as an enhancement after the existing dataset editor only for non-graph datasets, so graph behavior and its E2E contract are not changed by this milestone.

## Draft and save model

Grid edits are local drafts. Typing into a cell, adding a row, pasting multiple rows, or marking a row for removal does **not** write to WordPress immediately.

The toolbar exposes an explicit **Save changes** action. One save sends the changed rows and pending deletions to `/viswiz/v2/datasets/{id}/editor/rows/batch` together with the expected dataset revision.

The batch writer:

1. validates every changed row against the active schema before opening a transaction;
2. locks the dataset row with `SELECT ... FOR UPDATE`;
3. rejects stale revisions with `viswiz_revision_conflict`;
4. applies all row upserts and removals in one database transaction;
5. increments the dataset revision exactly once;
6. stores one canonical full revision snapshot;
7. commits only if the complete operation succeeds.

A single batch is capped at 500 changed rows. Server collection pages remain capped at 100 rows.

This deliberately avoids a background/autosave queue. The existing revision model remains the concurrency and recovery boundary.

## Spreadsheet interaction

The normal schema fields are editable directly in table cells. Advanced stable keys and additional metadata remain behind an **Advanced** row action rather than occupying routine grid columns.

Keyboard behavior:

- **Tab / Shift+Tab** — move to the next/previous editable grid cell.
- **Enter** — commit the current single-line cell to the local draft and move down in the same column; on the last row it creates a new draft row.
- **Arrow Up / Arrow Down** — move vertically between ordinary text cells. Native arrow behavior is preserved for numeric controls and multiline text.
- **Ctrl/Cmd+Enter** — save the current draft batch.

The implementation intentionally does not override left/right caret movement in text fields.

## Spreadsheet paste

Pasting tab/newline-delimited clipboard text into any grid cell fills a rectangular range beginning at that cell. This matches the plain-text clipboard format produced by common spreadsheet applications.

If the pasted range extends beyond the rows currently shown, new draft rows are created automatically. Values are mapped only to the visible schema-specific columns; stable keys and advanced metadata are not populated implicitly by paste.

Pasted data is still only a draft until **Save changes** is used.

## Validation and conflicts

Required fields, numeric constraints, geographic ranges, date/time values and color syntax are checked in the browser and shown inline on the affected cells. The save button is disabled while client-visible row errors remain.

Browser validation is not authoritative. `SpreadsheetEditorApi` runs every submitted row through `RowSchema::normalize_for_editor()` again before the transactional writer is called.

If the server revision has advanced since the grid was loaded, the batch is rejected. The spreadsheet keeps the user's local draft visible, switches to an explicit conflict state, disables search/pagination, and offers **Reload server version** rather than silently overwriting newer work.

## Paging and search

The grid continues to fetch bounded pages from the authenticated `/rows` collection endpoint. Search and paging are disabled while unsaved drafts exist so a user cannot accidentally navigate away from the active draft set. **Save changes** or **Discard changes** re-enables them.

No full canonical dataset payload is embedded into the dataset editor.

## Persistence boundary

As in 2.0.17 and 2.0.18, revision snapshots are still full-dataset snapshots. Browser/network editing is bounded; persistence-layer snapshot scalability remains a separate future concern. `VISWIZ_DB_VERSION` remains `20000`.
