# VisWiz 2.0.21 graph editor workflow

VisWiz 2.0.21 targets P0 usability backlog item #9: make repeated graph node/relation entry efficient without introducing a second graph-editor state owner.

## Existing behavior retained

The server-aware graph editor remains authoritative. Existing capabilities are retained:

- bounded/paginated node and relation collections
- server-side dataset search
- lazy searchable relation endpoint lookup
- targeted revision-checked node/relation writes
- registered node type/subtype validation
- registered relation type validation
- relation-type defaults for label, inverse label, direction and intensity
- graph revision snapshots and conflict protection

## Required workflow additions

### Node context

Each node row must expose a direct **Add relation** action in addition to Edit/Delete.

The node editing dialog for an existing node must show its connected relations using a bounded server query filtered by node UUID. Each relation must be identified as incoming or outgoing relative to the current node, show the other endpoint and relation label/type, and remain editable from the node context.

### Quick-create missing nodes while entering a relation

Both relation endpoint pickers retain lazy server search and gain a **Create node** action. A newly created node must use the canonical targeted node write path, update the dataset revision, and become the selected endpoint without losing the relation draft.

### Relation constraints

Registered relation source/target type and subtype constraints remain warnings, matching `GraphValidator`; targeted editing must not silently reinterpret them as fatal validation rules.

The relation dialog must show a clear warning whenever the selected source/target does not match the selected relation type's registered constraints. The warning must update as the relation type or either endpoint changes.

### Duplication

Node and relation rows gain **Duplicate** actions. Duplication opens the normal canonical editor with a fresh UUID and copied editable values; it must not bypass validation or revision handling.

Node duplication must create a collision-safe draft slug rather than overwrite the source node.

### Server-aware relation filtering

The existing relations collection endpoint gains optional `node_uuid` filtering. Filtering is performed in `DatasetCollectionRepository`, remains paginated, and returns endpoint type/subtype metadata needed for relation-constraint feedback.

No full graph payload is embedded or fetched for node-context relation display.

## Regression coverage

The 2.0.21 browser suite must prove at least:

- a relation can be started from a node row with the source endpoint preselected
- a missing endpoint node can be created from the relation dialog and selected without losing the draft
- existing node dialogs show incoming/outgoing relations through the node-filtered server endpoint
- relation constraint mismatches are visible warnings and do not become fatal errors
- duplicate node uses a fresh UUID/slug and persists independently
- duplicate relation uses a fresh UUID and persists independently
- existing lazy lookup beyond the first node page continues to work

Static/architecture coverage must verify the filtered relation query stays server-side and no companion graph-editor compatibility runtime is introduced.

`VISWIZ_DB_VERSION` remains `20000`; this milestone requires no database migration.
