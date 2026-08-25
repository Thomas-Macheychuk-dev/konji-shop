# ARmedical production readiness

`armedical:production-preflight` is the read-only gate before enabling a controlled ARmedical production import path.

It performs no product, variant, category, image, document, or filesystem writes. Optional source media probes are fetched into memory only. An evidence JSON is written only when `--save=` is supplied.

## Frozen approved cohort

- priced map SHA-256: `9617b3d1a5d549c7b590ea6c252cd0ded430cf1a31571bb8853c6dbe20a2ad20`
- v3 product-data SHA-256: `05e939acaa6251e8c9e5abfd14383a2b85d5b471db556868b5040b631c434da8`
- supplier XLS SHA-256: `ac97003ad885025e665961d05afe1ed2d74d88a53b4aa9b413896f292a282893`
- 200 mapped source products / 506 planned variants
- 187 fully priced eligible products / 459 variants
- 13 unresolved products / 47 unresolved variants intentionally excluded
- 923 eligible images / 318 eligible documents
- 451 eligible variants at 8% VAT / 8 at 23% VAT
- all production-created products and variants must remain draft; variants remain conservatively out of stock

The retained Soft Cast source conflict and all unresolved pricing stay outside the production cohort. Passing this command does not authorize importing the excluded 13 products.

## Production preflight before first write

Run from the application container after the frozen `import-map-priced.json` has been deployed to the Laravel `local` disk (`storage/app/private/scrapers/armedical/` with the current filesystem configuration):

```bash
php artisan armedical:production-preflight \
  --minimum-free-mib=500 \
  --probe-images=3 \
  --probe-documents=3 \
  --show-checks \
  --show-review
```

For the initial production run, the defaults require zero existing ARmedical products, variants, images, document links, and media files.

The preflight checks frozen fingerprints and cohort counts, supplier pricing metadata, price/VAT reconciliation, unique product/variant identities, draft-only mapping, the excluded unresolved cohort, non-ARmedical collisions for product external IDs/slugs and variant external IDs/SKUs, production DB connectivity, public shared storage configuration/writability/free space, existing ARmedical media-file counts, the production storage symlink configuration, and optional live image/document egress probes.

## Local rehearsal against the completed local cohort

The validated local database already contains 187 products, 459 variants, 923 image rows/files, and 318 local document links/files. Rehearse with:

```bash
php artisan armedical:production-preflight \
  --allow-non-production \
  --expected-existing-products=187 \
  --expected-existing-variants=459 \
  --expected-existing-images=923 \
  --expected-existing-document-links=318 \
  --minimum-free-mib=100 \
  --probe-images=3 \
  --probe-documents=3 \
  --show-checks \
  --show-review
```

A PASS only authorizes building/using the later controlled production execution patch. It does not itself import or publish anything.
