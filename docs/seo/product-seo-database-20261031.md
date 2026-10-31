# Database product SEO repair — 31 October 2026

## Goal

Every active, non-deleted product must have nonblank plain-text `seo_title` and `seo_description` values stored in the database. The admin fields become the source of truth for storefront metadata. This is a metadata repair, not a certification of product identity, medical claims, purchase eligibility or indexing readiness.

## Changes

- Fill missing/markup-only SEO titles from the product name. Preserve identifying suffixes within the 255-character database column.
- Persist the 72 previously reviewed titles only when their identity and existing-title guards still match.
- Normalize markup/entities/spacing and remove copied shop/domain suffixes and generic shop headings from titles. Legitimate manufacturer names remain.
- Preserve existing descriptions unless empty, disclaimer-only, or contaminated with detected foreign-shop references, prices or delivery promises. Valid long descriptions are retained instead of silently truncated in the storefront.
- Generate replacement descriptions from the product identity and up to two clean, single-valued product attributes. No variant-size or default-colour guessing, medical benefits, stock claims, prices, shipping promises, invented identifiers or invented reviews. A neutral product-specific description is used when facts are sparse, and these rows are counted for further editorial improvement.
- Update only `seo_title`, `seo_description` and `updated_at`; never names, URLs, statuses, publication dates, prices, variants or category assignments.
- Retire runtime title overrides in the controller. The historical correction map remains as migration evidence; after this repair it is not the display source. Use product cache v10 to avoid old rendered values.

Foreign-reference and promotional detection is conservative and rule-based. It does not claim to identify every possible supplier phrase or verify the truth of existing medical descriptions. Unresolved duplicate titles remain in the saved plan for identity review; records are not given invented distinctions to make a uniqueness count pass.

## Run

Extract the reviewed product controller, `scripts/deploy/sync-product-seo-database-20261031.sh` and the entire `scripts/seo/product-database-20261031` directory from the pinned commit. Then run:

```bash
bash scripts/seo/product-database-20261031/repair.sh
```

The command checks the engine hashes and all three container source hashes before database changes. It creates a unique private directory, writes a dry-run plan and metadata backup, rechecks the live source under transaction locks, applies the derived values, invalidates the catalogue cache namespace, installs the reviewed controller consistently in app/queue/scheduler and reloads FPM. Finally it verifies zero blank fields across all active products and exact equality between stored values and the controller's SEO values. The normal global shop-name suffix in the HTML title is unchanged. It requests the large Mediven product twice to check cache miss/hit HTTP status.

For inspection without database writes, append `--dry-run-only`. The JSON plan includes all changed values, reasons, source fingerprint, aggregate counts, sparse-description counts and remaining duplicate-title product IDs. No new upload is required to generate the plan: it reads the live catalogue directly, in chunks, without hydrating variant graphs.

The full command performs authorized database writes. Its dry-run stage is read-only with respect to the database. It writes plan/backup files and cache version keys; it does not flush Redis, change Nginx/maps, alter DNS, change memory limits or restart queue workers.

## Backup, recovery and rollback

The output reports `PRODUCT_SEO_REPAIR_DIRECTORY`, `PRODUCT_SEO_REPAIR_ENGINE`, `PRODUCT_SEO_BACKUP` and the source patch backup. `plan-and-backup.json`, its checksum and `receipt.json` are retained in private application storage before/after commit. Dry-run refuses to overwrite an existing plan.

With the same reviewed engine staged in the app container, run:

```bash
docker exec -w /var/www/html konji-shop-prod-app-1 php ENGINE/run.php verify PRIVATE_REPAIR_DIRECTORY
docker exec -w /var/www/html konji-shop-prod-app-1 php ENGINE/run.php rollback PRIVATE_REPAIR_DIRECTORY
```

Replace `ENGINE` and `PRIVATE_REPAIR_DIRECTORY` with the reported paths. If `/tmp` was cleared, restage the engine from this exact Git commit; the database backup does not depend on keeping `/tmp` files. Verification can retry post-commit cache invalidation. Apply recognizes a matching applied receipt and can recover a completed transaction whose receipt writing was interrupted.

Rollback restores only backed-up SEO values and timestamps in one transaction. It refuses to overwrite a product edited, renamed, archived or deleted since application. Concurrent changes between planning and application cause a full abort before metadata updates. A database update failure rolls back the whole transaction.

The source patch has independent per-role backups and rollback. If source installation fails after the database commit, the metadata changes and database receipt are retained; repair output explicitly reports that state. Resolve the source problem and rerun the source patch plus verification, or use guarded database rollback if that is the intended recovery. Do not assume a source rollback has also reverted database metadata.

## Validation and limits

Tests cover read-only planning, active-only scope, valid authored content, long descriptions, the 72 corrections, contaminated text, dirty attributes, transactional failure, stale/tampered plans, rollback and later editor changes. The deployment fixture covers preflight drift, success and source rollback. The existing 2,688-variant serialization regression remains enabled.

Local metadata simulations also exercise the 231 exported products and missing-field fallback against the historical 14,627-product name inventory. These are not live database results. Actual affected-row counts and completion must come from the server command. Future imports can still recreate blank fields until importer/publication validation is addressed separately.
