# JavaScript localization contract

VisWiz user-visible JavaScript copy uses the WordPress `wp.i18n` API with the `viswiz` text domain. Static UI strings live at their call sites as gettext msgids (`__`, `_n`, `sprintf`) rather than in PHP-to-JavaScript translation maps or runtime language dictionaries.

## Rules

- Scripts that author user-visible copy declare `wp-i18n` and call `wp_set_script_translations()` after registration/enqueue.
- PHP-localized globals carry runtime data/configuration only; they do not carry static translation tables.
- REST/API error messages remain server-owned. JavaScript translates only its local fallback message.
- Registry/schema labels, server data, user content, enum/storage keys and import header aliases are data/contracts, not JavaScript msgids.
- Plural or interpolated UI copy uses `_n()`/`sprintf()` rather than English suffix construction.
- The public graph runtime no longer performs locale sniffing or maintains a Greek/English dictionary. Existing Greek public graph labels are retained as normal WordPress Jed JSON catalogs in `languages/`.

## Audited surfaces

The consolidation covers the base admin UI, server-aware dataset editor, spreadsheet editor, guided import, node public fields/rich editor adapters, renderer settings, visualization preview/presets, WooCommerce source selection, public renderer, graph enhancement runtime and Gutenberg block editor.

`tests/JavaScriptLocalizationTest.php` protects the architecture by rejecting the former `cfg.i18n`/`VisWizFrontendV2.i18n`/`tr()` patterns and verifying script translation registration plus the Greek public catalogs.
