# Antar selective priced draft import — 1 September 2026

This stage imports only the deterministic commercial cohort produced by `antar:price-reconcile`. It is intentionally **not** a full-catalogue import.

## Current approved boundary

For the reviewed 625-product Antar crawl:

- eligible priced products: **519**
- manual commercial review: **9**
- excluded unpriced products: **97**
- hard reconciliation errors: **0**
- approved crawl SHA-256: `8c8859c3c795ab811d1e90f2162f18f2d8069ac027458c6ba3a112f49676b76d`

The manual and excluded cohorts are never passed to the product importer by this command.

## Fail-closed evidence checks

Before the first database write, `antar:import-priced` verifies:

1. the exact SHA-256 of `scrapers/antar/product-data.json` is the approved 625-product crawl pinned by this patch and matches the saved reconciliation;
2. the saved reconciliation uses schema `konji.antar.price-reconciliation.v1` and is marked ready for selective priced import;
3. supplier spreadsheet SHA-256, normalized reference JSON SHA-256, effective date and source filename still match the current frozen `AntarSupplierPriceList`;
4. the current deterministic reconciliation is rebuilt from the crawl and must exactly match the saved summary, eligible cohort, manual-review cohort, excluded cohort and hard-error evidence;
5. the live batch invariants remain exactly 625 source / 519 eligible / 9 manual review / 97 excluded;
6. every eligible reconciliation row maps back to exactly one source product;
7. every approved price has positive integer net/gross minor units, a supported VAT rate, PLN currency, and exact net/gross/VAT arithmetic;
8. existing Antar products outside the frozen eligible cohort block execution;
9. scraped SKUs that would collide with variants belonging to another source block execution.

No fuzzy SKU/name matching is added at import time.

## SKU semantics

The importer preserves the SKU scraped from the Antar product page. The reconciliation's `normalized_supplier_code` is the **commercial price-list identity**, not automatically a replacement storefront SKU.

This distinction matters for reviewed aliases such as:

- `SNW500` priced from supplier code `SNW-500`;
- `4028` priced from supplier code `AMELIA`;
- `HF6002-ALU` priced from supplier code `XI-RUROWY-ALU`.

Products recovered by exact URL with no scraped SKU remain nullable-SKU products. Their pricing remains backed by the frozen reconciliation evidence. This avoids silently rewriting supplier/product identity just to make price matching convenient.

## Approved pricing wins over inferred VAT

The legacy raw Antar importer can infer VAT from medical-device metadata when no approved commercial pricing is supplied. The selective priced importer passes an explicit approved commercial payload into `AntarProductImporter`, so the supplier-list net price, gross price and VAT win exactly.

Example: the no-SKU `Torba na materac ... AT03107` uses supplier code `TORBA-AT03107` and must import at 59.00 PLN net / 72.57 PLN gross / 23% VAT even if the product metadata would otherwise imply medical-device VAT.

## Preflight

Read-only:

```bash
php artisan antar:import-priced \
  --from=scrapers/antar/product-data.json \
  --reconciliation=scrapers/antar/price-reconciliation-2026-09-01.json
```

The command prints the current database audit and `Ready for local draft import: YES/NO` and makes no network requests.

## Local smoke import

Execution is restricted to Laravel `local` and `testing` environments. Products and variants are always forced to `draft`.

First import a small cohort without remote assets:

```bash
php artisan antar:import-priced \
  --from=scrapers/antar/product-data.json \
  --reconciliation=scrapers/antar/price-reconciliation-2026-09-01.json \
  --execute \
  --limit=10 \
  --no-images \
  --no-documents \
  --show-failures
```

After database validation, import/update all 519 eligible drafts. Asset downloading can be enabled in a separate controlled pass by omitting `--no-images` and `--no-documents`.

## Production boundary

This command deliberately refuses `--execute` outside local/testing environments. Production requires a separate production preflight/import stage with explicit production database safeguards and rollback evidence.

Do not use the raw `antar:import` command on the full 625-product crawl as a substitute for this commercial boundary.

## SKU identity and collision handling

The supplier catalogue identity and the database variant SKU are deliberately separate concerns.

- `products.external_parent_sku` stores the approved Antar catalogue identity used by the commercial reconciliation.
- The variant `sku` remains globally unique in Konji Shop. If the scraped/source SKU collides with another supplier or is duplicated within the eligible Antar cohort, the preflight assigns a deterministic namespaced storage SKU of the form `ANTAR-<source-sku>-<external-id-hash>`.
- Collision resolution never changes the approved net/gross/VAT evidence.
- The Opti-Comfort replacement handle is a special documented source case: Antar publishes the same `OPTI-COMFORT` catalogue number as the crutch, while the supplier spreadsheet prices the handle on row 805 under the generic `Akcesoria` group. The reconciliation therefore pins that exact product URL to row 805 before direct SKU matching.
