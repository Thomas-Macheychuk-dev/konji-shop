# Product page cache memory repair

## Confirmed failure

Product 12003 (`mediven-forte-podkolan-wki-uciskowe-1`) has 2,688 variants. Its direct uncached controller/view render succeeded with a 256 MB limit, producing 3,778,866 bytes and peaking at 162.8 MB. Requests through the deployed HTTP endpoint returned 500 on both hosts. The subsequent web log confirmed `Allowed memory size of 268435456 bytes exhausted` inside Eloquent Model.php. Source inspection found the complete loaded product/variant/attribute graph in the page cache value.

The patch caches plain arrays: product attributes, image attributes, default-variant attributes and the already-built configurator/SEO data. On retrieval it reconstructs only the product, its two image collections and default variant for Blade. It retains every active variant and option in the configurator payload. Admin preview still bypasses the public cache. The v7 cache key prevents retrieval of old v5/v6 model graphs. It does not flush Redis, change PHP memory limits, change publication dates or update catalogue records.

## Verification

The regression fixture has 2,688 active variants with attribute pivots and a configured attribute image. It exercises cache miss and serialized cache hit, recursively rejects objects in the cache snapshot, checks all variants remain, verifies option/image/price/SEO/purchase data, checks the hit does not reload variants and checks invalidation after a variant price change.

The deployment fixture runs success, unreviewed-source rejection and rollback scenarios using a filesystem Docker stub. Run:

```bash
bash -n scripts/deploy/sync-product-page-cache-20261030.sh
python3 tests/Fixtures/Deployment/test-product-page-cache-patch.py
```

## Apply after review

Extract the controller and deployment script from the reviewed commit into an isolated directory and run `scripts/deploy/sync-product-page-cache-20261030.sh` there. The script accepts only the pinned pre-SEO controller, the merged SEO controller or its own candidate in all three app/queue/scheduler containers. Any other hash stops before source changes. It records separate role backups in the shared private storage directory, copies and syntax-checks the candidate, verifies all three hashes, then reloads app FPM to refresh OPcache. Failures trigger source rollback and another FPM reload. It does not rebuild containers, restart workers, touch Nginx or change redirect maps.

After application, request the exact failing product on staging twice to exercise miss and hit, confirm HTTP 200 both times, and recheck the FPM memory/error logs. Confirm staging still sends its noindex header. Runtime memory improvement and resolution of the real HTTP 500 must be verified on the server; a passing CI test alone does not establish that outcome.

Backups are reported as `PRODUCT_PAGE_CACHE_PATCH_BACKUP`. For manual rollback, copy each role's backed-up `app/Http/Controllers/ProductShowController.php` into that same container and reload app FPM with `kill -USR2 1`. Old cache keys were not deleted. The large rendered payload remains a separate performance opportunity.
