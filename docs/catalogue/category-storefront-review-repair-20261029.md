# Storefront category review repair — 29 October 2026

The final desktop review exposed specific errors left after PR 107: shared Nowości membership put clothing in pressure care, Medi navigation links were treated as product categories, six Seni24 products had unrelated clinical categories, and one elbow crutch was labelled as a walking stick. Nested category pages also omitted their parent breadcrumbs.

## Reviewed scope

- 198 products: all 134 Medi products, 30 Wojdak garments in duplicate categories, 24 Apolonia clothing/hair accessories, three Antar pressure-care accessories, six Seni24 products and one MedReha crutch.
- Rebuild 51,614 memberships into 47,690, keeping one primary for every one of the 14,627 products and all 12 departments.
- Remove the 24 clothing/accessory products from pressure care. The pressure-care branch moves to Opieka nad pacjentem with three relevant products. Clothing has 579 products afterwards.
- Use exact product identities, product-type slugs and existing specific categories for Medi forms. The live descriptions confirm that the misleading forte/plus podkolan-wki slugs can describe tights. Do not infer gender or compression class from scraped navigation. Preserve existing supported secondary leaves; remove the erroneous Medi navigation memberships. Only the two explicitly named travel products retain the flight category.
- Correct Foley catheter, syringe, gauze swabs, herbal ointment, LOVI pacifiers, DermaSculpt cannula and the MedReha elbow crutch.
- Archive 37 duplicate or unsupported navigation categories, preserving each old URL with a direct 301 to an active canonical category. Eight are garment duplicates; the remainder are unsupported Medi landing pages and a duplicate accessories node. No category is deleted and no slug changes.
- Rename the shared pressure-care Nowości node and the compression accessory heading to explain their contents.
- Show full root-to-leaf category breadcrumbs, with links to active ancestors. Avoid exposing deleted/inactive ancestors; traversal has a cycle guard.

The repair does not alter products, variants, prices, stock, orders, importers, scraper code or nginx redirect maps. Additional category consolidation, filter/sort features and a full product deduplication project are outside this targeted correction. Similar names alone do not prove duplicate products.

## Validation

The standalone test replays the original repair and follow-up against the frozen export, then checks this explicit plan. It verifies all primaries, all ancestor memberships, department count, old walker corrections, pressure-care and clothing counts, empty archived categories, alias targets, generated database ID independence and six rejection scenarios. PHP syntax and Bash syntax checks pass. Four Laravel feature tests cover full breadcrumbs, hidden ancestors, cyclic traversal and archive aliases (including missing targets). GitHub Actions runs the application suite on PHP 8.4 and 8.5; local application dependencies are unavailable.

Live review also found a pre-existing 500 at `/products/mediven-forte-podkolan-wki-uciskowe-1` (product 12003). Its existing specific category indicates stockings; this repair keeps that form and removes the unrelated navigation assignments. The server error needs its application log separately and is not claimed fixed by category reassignment.

## Deploy to the current shared new-shop runtime

Extract this commit with git archive, retaining its paths. Include these paths:

- app/Http/Controllers/CategoryProductIndexController.php
- resources/views/pages/categories/show.blade.php
- resources/views/components/storefront/product-card.blade.php
- resources/views/partials/storefront/header.blade.php
- scripts/deploy/sync-catalogue-views-20261030.sh
- scripts/catalogue/20261029
- resources/catalogue/20261029

Run `bash scripts/catalogue/20261029/deploy-review.sh` from the extracted tree on the EC2 host. It targets `konji-shop-prod-app-1` by default, or `KONJI_REVIEW_CONTAINER` when explicitly set. It reconstructs the relevant original export fields from the persistent, checksummed first-repair backup and the unchanged product identities verified against both receipts. This read-only preparation does not depend on an old `/tmp` file surviving container recreation.

Order: verify receipts and reconstruct simulation input without database writes, replay simulation, live data dry-run, checksum-guarded source synchronization across app, queue and scheduler, transactional data apply, receipt verification and versioned storefront cache invalidation. The source patch compiles views and gracefully reloads PHP-FPM for production OPcache. The data runner refuses to archive categories until the exact alias-capable controller and breadcrumb view are installed. A drift failure stops the sequence; do not bypass it. Export/review a fresh catalogue if the previous follow-up receipt no longer matches.

The PHP runner supports `--mode=dry-run|apply|verify|rollback` and `--plan=/absolute/path/review.json`. It requires MySQL and the preceding follow-up receipt's database fingerprint, product identity and full category/assignment state. It verifies the checksummed plan and writes exclusive backups; existing backups are never overwritten. All writes happen in a DB transaction and unrelated categories, assignments and product rows are checked unchanged. Cache invalidation runs after commit; if it fails, retry verify rather than apply.

Persistent backups:

- `storage/app/private/catalogue-repairs/catalogue-20261029-review-v1.json`
- the same path plus `.receipt.json`
- original controller/view under `storage/app/private/ui-backups/catalogue-review-20261029/`

Do not rerun deploy after success. To repeat verification:

```bash
docker exec -w /var/www/html konji-shop-prod-app-1 php \
  /tmp/konji-catalogue-review-20261029/review.php \
  --mode=verify --plan=/tmp/konji-catalogue-review-resources/review.json
```

Guarded data rollback uses the same command with `--mode=rollback`; it refuses to overwrite any edits made after this repair. The controller/view can remain: restored active categories do not redirect, and complete breadcrumbs work with the previous graph. If code rollback is necessary, verify the exact current candidate hashes from apply-review-code.sh, restore both source files from the UI backup, run `php artisan view:cache` and gracefully reload PHP-FPM (`kill -USR2 1`). Never restore the old controller while alias-source categories are still archived.

This patches the current app container. Merge the source PR and include it in the next image so container recreation retains the controller/view changes. The database repair is deliberately not a migration and must not run automatically at startup.

After apply: verify the three remaining pressure-care products; 579 clothing products; Foley, syringe, skin-care and Medi labels; archive redirects; category breadcrumbs; the preserved walker accessory corrections; pagination and narrow-screen navigation. The current browser review was desktop only.

## Shared-view correction after apply — 30 October 2026

The data apply and receipt verification passed. Live product paths reflected the corrections, but several category pages still rendered the old breadcrumb template and generic product-card labels. The production Compose configuration shares the storage volume (including compiled Blade views) between app, queue and scheduler. Queue exits every hour and its entrypoint clears/rebuilds views from its own image. Patching only app source leaves older queue/scheduler templates able to replace the shared compiled files. PHP-FPM has timestamp validation disabled, so rebuilding files without a reload is also insufficient.

Use scripts/deploy/sync-catalogue-views-20261030.sh with the same four reviewed source files. It validates all 12 deployed file hashes before mutation, accepts only the known previous or reviewed versions, saves an exclusive per-role backup in the shared private storage, installs matching source in all three containers, validates the results, rebuilds views once from app and reloads PHP-FPM. It attempts restoration of all source files if applying or rebuilding fails. It does not restart queue/scheduler, run optimize:clear, flush Redis or touch the database. Container recreation still requires images built from merged main; writable-layer patches do not survive replacement with an older image.

After a completed data repair, run only this view-sync script. Do not rerun the data deployment. The primary workflow now uses the multi-container synchronization before any data apply. Success, preflight-drift rejection and simulated-copy-failure rollback are covered by the filesystem-only Docker stub in tests/Fixtures/Deployment/test-catalogue-view-sync.py. The test runs in CI.
