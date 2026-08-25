# ARmedical controlled production execution

The production command operates only on the frozen priced ARmedical cohort:

- priced-map SHA-256: `9617b3d1a5d549c7b590ea6c252cd0ded430cf1a31571bb8853c6dbe20a2ad20`
- 200 mapped source products / 506 planned variants
- 187 fully resolved products / 459 variants approved for writes
- 13 unresolved products / 47 variants remain excluded
- 923 approved source images
- 318 approved source documents
- 451 variants at 8% VAT, 8 variants at 23% VAT

All imported products and variants remain `draft`; all imported variants remain conservatively `out_of_stock`.

## Required production artifact

The exact frozen `import-map-priced.json` must be present on Laravel's `local` disk at:

`storage/app/private/scrapers/armedical/import-map-priced.json`

Verify the file inside the app container before any write:

```bash
sha256sum /var/www/html/storage/app/private/scrapers/armedical/import-map-priced.json
```

The result must equal the approved SHA above.

## Clean-production preflight

```bash
php artisan armedical:production-preflight \
  --minimum-free-mib=500 \
  --probe-images=3 \
  --probe-documents=3 \
  --show-checks \
  --show-review
```

A clean production database/storage state expects zero existing ARmedical rows/files.

## Controlled dry-run

```bash
php artisan armedical:production-import \
  --stage=all \
  --minimum-free-mib=500 \
  --probe-images=3 \
  --probe-documents=3 \
  --show-review
```

This performs no writes and prints the confirmation token required by the write path.

## Controlled production write

```bash
php artisan armedical:production-import \
  --stage=all \
  --write \
  --confirm=ARMEDICAL-PRODUCTION-187-459-9617B3D1 \
  --minimum-free-mib=500 \
  --probe-images=3 \
  --probe-documents=3 \
  --attempts=5 \
  --timeout=30 \
  --request-delay-ms=250 \
  --show-review \
  --show-failures
```

The command runs a strict pre-write production preflight before touching the catalogue. The catalogue stage must reach exactly 187 draft products / 459 draft out-of-stock variants before media starts. The final stage must reach exactly 923 image rows/files and 318 localized document links/files. A final production preflight then re-verifies the complete state.

## Resume after a partial failure

The importers are idempotent. Before retrying, determine the exact existing ARmedical counts and pass them to the pre-write gate, for example:

```bash
php artisan armedical:production-import \
  --stage=all \
  --write \
  --confirm=ARMEDICAL-PRODUCTION-187-459-9617B3D1 \
  --expected-existing-products=187 \
  --expected-existing-variants=459 \
  --expected-existing-images=<CURRENT_IMAGE_ROWS> \
  --expected-existing-document-links=<CURRENT_DOCUMENT_LINKS> \
  --show-failures
```

Do not guess resume counts. The preflight requires the database rows, source IDs, media rows and physical storage files to be consistent before it permits a retry.

## Safety boundaries

The production command does not publish products, does not import the 13 unresolved products, does not synthesize missing prices/SKUs, does not use the supplier `Pakiet 5+1` price, and does not delete/recreate the database. Existing non-ARmedical catalogue rows are protected by the collision preflight.
