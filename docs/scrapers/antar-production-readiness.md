# Antar production reconciliation readiness

`antar:production-preflight` is the read-only gate for reconciling the approved September 2026 Antar priced cohort into the existing July 2026 production catalogue.

It does **not** enable production writes. The existing `antar:import-priced --execute` command remains restricted to local/testing environments.

## Approved immutable inputs

- Current crawl: `625` products
- Product-data SHA-256: `8c8859c3c795ab811d1e90f2162f18f2d8069ac027458c6ba3a112f49676b76d`
- Approved priced cohort: `519` products
- Reconciliation SHA-256: `a817d425e06771174aad7c7ac1da4179390232a93f05ff07bd08de60279207ee`
- Manual price review retained outside import: `9`
- Excluded/unpriced retained outside import: `97`
- VAT: `507` variants at 8%, `12` variants at 23%

## Frozen production baseline

The reviewed production baseline before any reconciliation write is:

- Antar products: `622`
- Antar variants: `622`
- Antar drafts: `622`
- Unpriced Antar variants: `622`
- Approved current external IDs already present: `514`
- Approved current external IDs absent: `5`
- Current non-approved source products already present: `106`
- Existing rows outside the approved cohort: `108`
- Current source products absent from production: `5`
- Production rows absent from the current source: `2`

The five missing approved current IDs are:

1. `orteza-tulowia-oppo-2356`
2. `wozek-elektryczny-z-funkcja-chodzika-at52334`
3. `wozek-inwalidzki-elektryczny-at52304-3`
4. `wozek-inwalidzki-elektryczny-zewnetrzny-i-skuter-inwalidzki-at52339`
5. `wozek-inwalidzki-elektryczny-zewnetrzny-skuter-inwalidzki-at52338`

Only AT52334 is a reviewed legacy identity migration:

- production product ID: `11447`
- old external ID: `wozek-inwalidzki-elektryczny-at52334`
- current external ID: `wozek-elektryczny-z-funkcja-chodzika-at52334`
- SKU: `AT52334`

The other four missing approved current IDs are expected creates.

The production-only obsolete row is retained untouched as a draft:

- product ID: `11040`
- external ID: `opaska-kompresyjna-na-twarz-at04702`

## SKU resolution baseline

Production contains four cross-source base-SKU collisions with Peruka:

- `6421`
- `6760`
- `6704`
- `6705`

The approved Antar cohort contains two duplicate base-SKU groups:

- `OPTI-COMFORT`
- `AT52304`

Therefore the production plan must resolve exactly:

- cross-source base-SKU collisions: `4`
- duplicate Antar source-SKU groups: `2`
- namespaced Antar storage SKUs: `8`

AT52334 remains `AT52334` because its collision is the reviewed legacy identity row, not a distinct product.

## Read-only preflight

After the approved product-data and reconciliation files are copied to the Laravel local disk and their hashes are independently verified:

```bash
php artisan antar:production-preflight \
  --from=scrapers/antar/product-data.json \
  --reconciliation=scrapers/antar/price-reconciliation-2026-09-01.json \
  --minimum-free-mib=512 \
  --show-checks
```

Expected outcome:

```text
Database writes: NO
Filesystem writes: NO
Network requests: NO
Hard preflight errors: 0
PASS: Antar production reconciliation topology is exactly approved.
```

The preflight also requires the six reviewed media-rescue JPEGs to be present in the deployed application image and verifies minimum free public-storage capacity.

## Write enablement

Do not add a production write path until this preflight has passed on the live production baseline using the approved artifacts. The follow-up production execution patch must preserve the AT52334 product identity, create only four approved products, leave all 106 current non-approved rows and AT04702 untouched, and require a database snapshot plus post-write audit.
