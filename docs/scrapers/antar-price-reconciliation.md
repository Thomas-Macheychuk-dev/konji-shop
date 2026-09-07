# Antar commercial price reconciliation — 1 September 2026

This stage sits between the Antar catalogue crawl and any database import. It does not create or update products and does not make network requests.

## Source boundary

- Catalogue source: Laravel local disk `scrapers/antar/product-data.json`.
- Commercial source: frozen supplier spreadsheet `A CENNIK ANTAR PELNY od 2026 09 01.xlsx`, effective 2026-09-01, represented by `AntarSupplierPriceList`.
- Database writes: **NO**.
- Fuzzy/name substring matching: **NO**.

The reconciliation command stores the SHA-256 of the exact crawled product-data JSON in its output so a later import stage can fail closed if the catalogue changes.

## Cohorts

`antar:price-reconcile` assigns every scraped product to exactly one cohort:

1. `eligible_priced_products` — an exact deterministic supplier-code match or an explicitly approved reconciliation rule.
2. `manual_price_review` — a known ambiguity, suffix/version question, or multi-code product that cannot safely receive one price automatically.
3. `excluded_unpriced_products` — no approved deterministic price mapping exists.

A product is never moved into the eligible cohort merely because a number or model-like token appears in its name or URL.

## Explicit approved reconciliations

The implementation intentionally keeps a finite manifest. Examples:

- `SNW500 -> SNW-500`
- `SPU370 -> SPU-370`
- `4028 -> AMELIA`
- `HF6001-ALU -> XI-ALU`
- `HF6001-N -> XI-N`
- `HF6002-ALU -> XI-RUROWY-ALU`
- `HF6002-ALU-18R -> XI-RUROWY-ALU-18R`
- `HF6002-EKO -> XI-RUROWY-EKO`
- `AT04702-1 -> AT04702`
- `AT53049-12 -> AT53079`
- `220 -> ECON220`
- `115/125/305 -> S-ERGO-115/125/305`

Exact product URL recoveries are used only for products with missing scraped SKU where the supplier identity was reviewed explicitly (ERGODYNAMIC, ERGOTECH, 2356, 475, AGILE, AT52304, and the AT03107 bag).

### Important AT03107 distinction

The supplier spreadsheet has separate rows:

- `AT03107` — the three-part rehabilitation mattress.
- `Torba AT03107` / normalized `TORBA-AT03107` — the bag for that mattress, 23% VAT.

The no-SKU web product `torba-na-materac-rehabilitacyjny-trojdzielny-at03107` therefore maps to `TORBA-AT03107`, never to `AT03107`.

## Manual-review rules

The following are intentionally not collapsed automatically:

- `AT51112-NH -> AT51112` candidate only.
- `AT04510-PRO -> AT04510` candidate only.
- `AT03552S -> AT03552` candidate only.
- `AT52303-1 -> AT52303` candidate only.
- `AT5141-0-AT51411-AT51412` requires a size/variant split across `AT51410`, `AT51411`, `AT51412`.
- `AT51406-AT51407-AT51408-AT51409` requires a size/variant split; the 85 cm spreadsheet row has no explicit supplier code.
- Any code present in the supplier `ambiguous_index` remains manual review (for the current web catalogue this includes `AT52201`, `AT53050`, and `AT04602`).

## Command

```bash
php artisan antar:price-reconcile \
  --from=scrapers/antar/product-data.json \
  --save=scrapers/antar/price-reconciliation-2026-09-01.json \
  --show-review \
  --show-excluded
```

Expected behavior on the current 625-product crawl is a selective eligible cohort plus manual/excluded cohorts. Full-catalogue readiness remains `NO` until every product has deterministic approved pricing.

Do not run `antar:import` from the full raw crawl as the next commercial step. The following importer patch should consume only the frozen `eligible_priced_products` cohort and verify both the catalogue SHA-256 and supplier price-list fingerprint before database writes.
