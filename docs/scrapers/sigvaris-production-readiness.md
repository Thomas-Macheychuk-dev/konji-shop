# Sigvaris production readiness

The read-only production gate is:

```bash
php artisan sigvaris:production-preflight
```

Approved frozen source fingerprints:

- product-data SHA-256: `6d35626f3013e229e60b03910dc9e5a1807d006ad87f366862f36e4759c76df4`
- combinations SHA-256: `25f6bdc91f26cd0eb80e1d9b3146e2958ed28817f78e7747c320836c0f176ba0`
- import-map SHA-256: `7f270865aebbab63c441f82c63d4075451f5c13fdbd49d735f43f00b427635aa`

Approved catalogue shape:

- 226 products;
- 14,991 planned variants;
- 849 deduplicated images;
- 71 category paths;
- 208 documents;
- one stable synthetic default variant for source product 106544;
- 216 products at 8% VAT and 10 products at 23% VAT;
- two non-blocking missing-manufacturer review items.

The command is read-only except when `--save` is explicitly supplied for an evidence JSON report. It validates the frozen fingerprints, catalogue invariants, production database starting counts, approved external IDs, non-Sigvaris slug/SKU collisions, shared public storage, the production storage symlink, free space, and optional live source-image probes.
