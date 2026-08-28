# Guided dataset import

VisWiz 2.0.16 adds a guided import workflow for canonical datasets. JSON remains available as an advanced interchange/backup path, but normal data entry should use delimited files or spreadsheet paste.

## Supported sources

- CSV
- TSV
- semicolon-delimited text
- pipe-delimited text
- direct paste from spreadsheet applications

The browser parser supports RFC-style quoted fields, escaped double quotes, CRLF/LF line endings and quoted multiline cells.

## Encoding

File imports expose explicit decoding choices:

- Auto
- UTF-8
- Windows-1253 (Greek)
- Windows-1252
- UTF-16 LE

Auto mode uses BOM detection where available, otherwise validates UTF-8 and falls back to Windows-1253 when the byte stream is not valid UTF-8. Pasted text is already decoded by the browser/operating system and therefore does not need a file encoding step.

Reference: MDN `TextDecoder`: https://developer.mozilla.org/en-US/docs/Web/API/TextDecoder

## Import stages

1. Read/paste source text.
2. Detect or select delimiter.
3. Parse the header and records locally in the browser.
4. Map source columns to VisWiz fields.
5. Send structured records and mapping to the authenticated preview endpoint.
6. Server-side preparation applies schema-aware validation and computes the candidate canonical payload.
7. The UI shows create/update/remove counts, row-level errors, graph integrity errors and destructive-change warnings.
8. Commit repeats server-side preparation and writes through `DatasetRepository::replace_payload()` with the editor revision as an optimistic lock.

Preview never writes data.

## Modes

### Append

Adds imported items and preserves existing items. Stable keys must not collide with existing imported items when they are explicitly supplied.

### Upsert

Rows are matched by `row_key`. Graph nodes and relations are matched by their stable external import key. Existing canonical UUIDs are retained when an item is updated.

### Replace

Replaces the selected item set. For row datasets this replaces rows. For graphs, nodes and relations are imported separately. Replacing graph nodes computes any relations that would lose an endpoint and reports those removals before commit.

## Graph external keys

Graph import deliberately separates external source identity from VisWiz internal identity.

- Internal node/relation identity remains an immutable UUID.
- A mapped `external_key` is stored in item metadata as `_viswiz_import_key`.
- Relation `from_key` / `to_key` values resolve against node import keys, readable slugs or explicit UUIDs.
- Node upsert reuses the existing node UUID, so relations remain connected even when the title, label, type or other mapped fields change.

Source systems therefore do not need to know or generate VisWiz UUIDs.

## Atomic write and revision safety

The importer does not implement its own database transaction layer. It builds a complete candidate payload and delegates the final write to the existing canonical repository replacement path. That path retains:

- dataset existence/schema checks
- optimistic revision conflict detection
- sanitization
- graph integrity validation
- database transaction semantics
- one new dataset revision/snapshot after a successful import

This keeps manual editing, JSON replacement, WooCommerce snapshots and delimited imports on the same canonical storage model.

## REST endpoints

Authenticated editors use:

- `POST /wp-json/viswiz/v2/datasets/{id}/import/preview`
- `POST /wp-json/viswiz/v2/datasets/{id}/import`

Both require `edit_viswiz_datasets`. The commit endpoint also receives `expected_revision`.

WordPress references:

- REST API custom endpoints: https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/
- `register_rest_route()`: https://developer.wordpress.org/reference/functions/register_rest_route/

## Limits

A single guided import is currently capped at 20,000 source records. This is a deliberate guardrail for the current full-payload editor/storage workflow. Large-dataset server-side pagination and incremental editing remain a separate stabilization item.
