# Schema-aware dataset editor

VisWiz 2.0.18 makes the row-based dataset editor follow the dataset schema instead of exposing every canonical storage column in one generic form.

## Editor contract

`VisWiz\Domain\Registry::schemas()` is the internal source of truth for row-editor fields. Each row schema may define an `editor` contract with a singular/plural noun and field definitions. The same contract is localized to `VisWizAdminV2.schemas` and drives the server-aware browser table and edit dialog.

The normal editing surfaces are:

- **Categorical** — label, value, color.
- **Time series** — date/time, value, label, color.
- **X/Y** — X, Y, label, color.
- **Geographic** — latitude, longitude, label, value, color.
- **Progress** — label, current value, target, text, color.
- **Diagram** — section title, section text, accent color.
- **Graph** — remains the dedicated node/relation editor rather than using the row contract.

Stable row keys and metadata not represented by a structured schema field remain available under **Advanced**. Structured fields such as progress target/text and diagram text are stored in canonical row metadata (`meta.target`, `meta.text`) without requiring users to edit JSON in the normal workflow.

## Validation and normalization

Browser inputs use native required/type/range constraints, but these are not treated as the security or integrity boundary. Targeted row writes pass through `VisWiz\Domain\RowSchema` before the canonical `DatasetRepository` write.

The server validates required fields for the active schema, numeric fields and geographic ranges. Time-series date/time values are interpreted in the WordPress site timezone and normalized to the canonical `x_numeric` timestamp for renderer ordering while preserving `x_value`. Structured progress/diagram text is sanitized before the repository performs its normal row sanitization and revision-checked write.

Guided import continues to use its existing schema validation path. Raw JSON replacement remains an advanced interchange path.

## State and scale

This change does not alter the server-aware paging model from 2.0.17. The editor still fetches only bounded row pages, performs server-side search, sends targeted writes with expected revisions, and refetches the affected page after a mutation.

`VISWIZ_DB_VERSION` remains `20000`; no table migration is required.

## Scope boundary

This milestone provides schema-specific fields and removes irrelevant generic fields from routine row editing. Spreadsheet-style inline cell editing, multi-row paste directly into the editor grid, and richer keyboard movement are the next P0 milestone rather than being implemented as a second competing editor here.
