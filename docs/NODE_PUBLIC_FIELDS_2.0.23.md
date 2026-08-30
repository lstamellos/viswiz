# VisWiz 2.0.23 — structured node public fields

VisWiz 2.0.23 completes P0 usability backlog item #11 by removing raw metadata JSON from the normal graph editing workflow and giving node public metadata a structured editor.

## Normal node workflow

Node editors now expose a **Public fields** section with ordered structured rows. Each field has:

- label
- type: `short`, `long`, `url`, or `formatted`
- value
- move up / move down controls
- remove control

The order in the editor is the order used by the public node detail view. New fields can be added without editing JSON.

## Advanced metadata

The existing raw node `meta` object remains available under a collapsed **Advanced metadata** section for uncommon integration-specific values.

`meta.public_fields` is deliberately removed from that JSON surface while the dialog is open. On submit, the structured fields are serialized back into `meta.public_fields` before the existing graph editor reads `FormData`.

Relation metadata JSON is also moved into a collapsed **Advanced metadata** section. Its storage contract is unchanged; the change only removes raw JSON from the normal relation-entry path.

This gives one authoritative editing surface for node public fields while preserving unrelated node and relation metadata keys.

## Data ownership

The structured-fields adapter does not fetch or save graph data itself. It does not own revision state, REST mutations, node collections, or relation collections. `assets/viswiz-dataset-editor.js` remains the sole graph data/mutation owner.

The adapter enhances the dynamically created graph forms. For node forms it synchronizes the existing `textarea[name="meta"]` in the submit capture phase; for relation forms it only moves that textarea under Advanced metadata.

## Sanitization and public output

No database or REST schema change is required.

The existing server contract remains authoritative:

- `DatasetRepository::sanitize_node_meta()` accepts only `short`, `long`, `url`, and `formatted`
- URLs use `esc_url_raw()`
- formatted values use `wp_kses_post()`
- other values use text sanitization
- empty values are omitted
- array order is preserved

The existing public payload maps `meta.public_fields` to `node.public_fields` in the same order.

## Regression coverage

Chromium coverage verifies:

- public fields are visible outside Advanced metadata
- add field
- type-specific values
- reorder
- save/reopen persistence
- remove field
- additional metadata preservation
- `public_fields` does not reappear in the Advanced JSON editor

Static architecture coverage also requires relation raw metadata to stay under the Advanced section and verifies that the adapter owns no REST/data state.

`VISWIZ_DB_VERSION` remains `20000`.
