# Antar supplier price update — 1 September 2026

The frozen supplier source for the current Antar price update is:

- file: `A CENNIK ANTAR PELNY od 2026 09 01.xlsx`
- worksheet: `Cennik`
- effective from: `2026-09-01`
- catalogue code: column `A`
- net price: column `E`
- VAT: column `F`
- gross price: column `G`
- currency: PLN
- source XLSX SHA-256: `c50f14f66293fe6e91ae4b558413217241acda84cb421a554392f4ddda081ea9`

The normalized reference is stored at `resources/import-data/antar/price-list-2026-09-01.json`. It contains only supplier evidence; it is not an executable database mutation.

## Frozen source summary

- priced rows: 995
- unique normalized catalogue codes: 940
- deterministic/safe price codes: 930
- ambiguous price codes: 10
- consistent duplicate codes: 19
- VAT rows: 4 at 5%, 947 at 8%, 44 at 23%
- net/gross/VAT arithmetic mismatches: 0

The 10 ambiguous codes are deliberately excluded from automatic price matching because the same supplier code has materially different price rows:

- `AKCESORIA`
- `AT04601-24`
- `AT04601-30`
- `AT04602`
- `AT04608-24`
- `AT04608-32`
- `AT52201`
- `AT53050`
- `MODEL-R`
- `MODEL-S`

These require a deterministic option/variant identity before any automated price write. The planner must never choose one of their prices by row order.

## Read-only planning

Run:

```bash
php artisan antar:price-update-plan \
  --save=scrapers/antar/price-update-plan-2026-09-01.json \
  --show-review \
  --show-changes
```

The command:

- performs no database writes;
- performs no network requests;
- matches current Antar products by `products.external_parent_sku` using the same SKU normalization as the Antar importer;
- requires exactly one live default variant per current Antar product;
- compares current net/gross/VAT/currency with the frozen supplier list;
- refuses ambiguous supplier codes;
- saves evidence under `storage/app` when requested.

A controlled write command is intentionally not part of this patch. Build that only after the real database plan has been reviewed and all unresolved current Antar products have been resolved or explicitly excluded.
