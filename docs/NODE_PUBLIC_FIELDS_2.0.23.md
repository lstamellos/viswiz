# VisWiz 2.0.23 — structured node public fields

VisWiz 2.0.23 completes P0 usability backlog item #11 by removing raw node metadata JSON from the normal graph-node workflow.

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

This gives one authoritative editing surface for public fields while preserving unrelated metadata keys.

## Data ownership

The structured-fields adapter does not fetch or save graph data itself. It does not own revision state, REST mutations, node collections, or relation collections. `assets/viswiz-dataset-editor.js` remains the sole graph data/mutation owner.

The adapter only enhances the dynamically created node form and synchronizes the existing `textarea[name="meta"]` in the submit capture phase.

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

`VISWIZ_DB_VERSION` remains `20000`.
