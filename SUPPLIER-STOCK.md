# KomArena supplier stock module 1.0.0

This patch extends the existing Product Factory. The live baseline is 2.6.1, whereas the repository previously contained 2.5.23. The two existing 2.6.1 migration/guard classes and their bootstrap integration are preserved from the verified live backup. The stock deployment changes only the new supplier module and one require in the existing factory loader. The base plugin version remains 2.6.1 to avoid triggering unrelated activation routines.

## Data and public behavior

`_komarena_pf_supplier_stock` is a registered, protected, non-REST product meta object:

* `quantity`: nonnegative integer, never a physical Woo quantity.
* `status`: `available` for positive quantity, `unavailable` for zero.
* `source_timestamp`: UTC Unix time supplied by the source; `0` explicitly means the legacy model contained no source timestamp.
* `observed_at`: UTC Unix time at which the snapshot was captured.
* `provenance`: `legacy_woo_snapshot` or `supplier_refresh`.

Migration preserves the exact legacy number. It does not infer 21 or more from old text saying `10+`, and does not invent a supplier verification timestamp. Future refreshes require an explicit source timestamp, reject older timestamps, and use an expected-state hash to reject concurrent changes.

Public labels: zero → `Momentálne vypredané u dodávateľa`; 1–20 → `U dodávateľa – X ks`; above 20 → `U dodávateľa – 10+ ks`. Physical managed stock retains `Skladom X ks`. Missing or invalid supplier data prevents purchasing. Existing purchasing restrictions remain effective. Classic cart, cart updates, checkout revalidation, and Store API quantity limits are covered. The module does not claim a live supplier reservation or supplier feed integration.

Supplier-only products have `manage_stock=false`, `stock_quantity=null`, `backorders=no`, and availability-derived `stock_status`. Woo's product lookup row also has a NULL physical stock quantity. Subsequent Woo product saves preserve this separation. Legacy availability strings in descriptions are replaced only when rendered; original stored descriptions, prices, SEO, images, categories, tags and visibility are not rewritten.

No supplier identity, source SKU, cost, URL or sourcing notes are added to public output. Supplier meta is not registered in public REST responses and is stripped from Woo product responses. Authenticated maintenance reads use only the bounded module endpoints.

## Maintenance API

All endpoints are POST under `/wp-json/komarena-pf/v1/supplier-stock/`. They require both administrator and Woo management capabilities and `target: "https://komarena.sk"`. The module verifies both home/site URLs and tag ID 580's exact name and slug before operations.

* `plan`: checks exactly 58 published, simple, tagged products with 42 instock / 16 outofstock and no backorders. Saves full product snapshots in a private, non-autoloaded WordPress option and reads them back before returning `plan_id` and `plan_hash`.
* `apply`: accepts that plan ID/hash, rejects product/cohort drift, obtains a database lock, requires InnoDB tables, locks affected rows, writes and reads back private supplier meta before clearing Woo stock. Verifies all 58 and commits as one transaction. Errors roll back the entire transaction and clear affected caches. Repeated apply verifies the committed result.
* `verify`: accepts the original plan ID/hash and verifies all products, physical stock fields and lookup rows, public availability, zero-stock purchasing, and full protected-data hashes.
* `rollback`: accepts the original plan ID/hash, refuses intervening changes, restores only original stock fields, and verifies exact snapshots. Restore the backed-up factory loader after data rollback to restore legacy runtime behavior. Never disable the module first while migrated supplier products remain unmanaged.
* `read`: accepts `id`, returns private supplier state, validity and its hash for refresh compare-and-swap.
* `update`: accepts `id`, integer `quantity`, integer `source_timestamp`, and `expected_hash`; only existing migrated simple supplier products are eligible. Creates a private before snapshot and transactionally updates stock only.
* `fix-draft-title`: fixed-purpose repair for ID 4312 only. Requires draft status and the exact known malformed title; creates a backup, writes only `post_title`, and verifies every other product field. It neither publishes nor saves or synchronizes variations.

Plans/backups live in private `komarena_pf_supplier_plan_*` options; they are not downloadable public files. Retain the private local deployment backup and its hash manifest as the independent plugin rollback point.

## Validation

`tests/supplier-stock.php` runs isolated PHP contract tests including mid-batch failure rollback, source drift, idempotence, protected-field rollback conflict, hidden visibility, zero-stock purchase rejection, malformed metadata, boundary labels, and future Woo save protection. These do not replace production read-back or a real supplier reservation integration. No real order or payment is created by these tests.
