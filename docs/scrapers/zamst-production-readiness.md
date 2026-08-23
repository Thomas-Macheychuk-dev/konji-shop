# Zamst production readiness

`zamst:production-preflight` is the read-only gate before any Zamst production import path is enabled.

It performs **no database writes and no product-image writes**. By default it also writes no report file. Use `--save=` only when an evidence JSON file is required.

The approved initial catalogue expectations are:

- 24 mapped products
- 108 planned variants
- 294 mapped images
- 21 distinct category paths
- 23 PDF/document links
- 29 product-video links
- 24 VAT review products
- 0 existing Zamst products, variants and images in production before the first smoke import

The command also checks:

- exact import-map source/readiness invariants;
- optional import-map SHA-256 fingerprint;
- unique Zamst product IDs, variant IDs and globally unique planned SKUs;
- draft-only product state;
- no YouTube channel/profile links in product-video data;
- production DB connectivity;
- existing Zamst row counts;
- collisions with non-Zamst product slugs;
- collisions with non-Zamst variant SKUs;
- the `public` filesystem disk and writable shared storage volume;
- static production Docker shared-storage/public-link configuration;
- minimum free storage space;
- optional real Zamst image egress probes performed in memory only.

Production catalogue writes must remain disabled if any hard preflight check fails.

## Initial production preflight

```bash
php artisan zamst:production-preflight \
  --from=scrapers/zamst/import-map.json \
  --probe-images=3 \
  --show-checks \
  --show-review
```

To pin the exact locally approved mapping, add:

```bash
--expected-sha256=<approved-import-map-sha256>
```

To persist evidence explicitly:

```bash
--save=scrapers/zamst/production-preflight.json
```

A successful result is only authorization to build/use the later controlled production execution path. It does not itself import or publish products.
