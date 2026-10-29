# Shared new-shop catalogue: data repair, 29 October 2026

> The first repair has been applied and verified on the shared new shop. Do not rerun it. Live verification found 18 mappings and the product-card label that need the separate [follow-up correction](category-data-followup-20261029.md). The original manifest remains unchanged for its receipt and rollback.

This is a data-only repair for the shared application serving staging.ortezka.pl and, after launch, ortezka.pl. The owner authorized repairing this shared catalogue. Importers and scrapers are deliberately unchanged. There are no schema migrations or application image rebuilds in this procedure.

## Frozen source and scope

Source export SHA-256: `3a32ddf54d15c383884c88a440e30957f73983b04bf390426ecf5269ab98990a`.

The reviewed source contains 1,217 category records (60 soft-deleted), 14,627 active products and 45,645 product/category pairs. The runner compares the actual database with every exported category, product identity/status and assignment before applying anything. A different catalogue requires a fresh export and a reviewed replacement plan; do not bypass the drift check.

The plan creates 12 categories, updates 314 existing categories and explicitly changes 475 primary assignments. It rebuilds all category memberships from the retained primary and non-ancestor secondary leaves, adding the repaired ancestor chains. The independently simulated result is 51,616 assignments.

Product rows, product URLs, variants, stock, prices, attributes, images, orders, payments, users, settings and existing legacy product redirect files are not mutated. The active test product (13274) is deliberately not hidden or deleted by this category-only repair.

## Resulting departments

- Ortezy i stabilizatory
- Kompresja medyczna
- Rehabilitacja i ćwiczenia
- Mobilność i transport
- Opieka nad pacjentem
- Higiena i nietrzymanie moczu
- Opatrunki i dezynfekcja
- Sprzęt diagnostyczny i medyczny
- Obuwie i pielęgnacja stóp
- Odzież medyczna i zawodowa
- Peruki i akcesoria
- Zdrowie i pielęgnacja

Existing meaningful category slugs are retained. Existing supplier/collection subcategories remain available inside their appropriate departments; this pass does not merge every synonymous leaf category. Exact duplicate sibling labels introduced by regrouping are resolved using explicit product/context evidence.

The ten `Jesteś w:` breadcrumb categories, the Seni24 supplier wrapper, and the two empty Befado/Befado.pl wrappers are archived. Their old new-shop category URLs return 404 under the existing controller. The real product categories beneath them are reattached to the appropriate active department. This deliberate retirement affects only these 13 wrapper/breadcrumb URLs; product URLs and legacy product redirects are unaffected. Category URL redirects can be added later if required before launch.

## Corrections

- Restore all 197 products without a category: all 187 Eldan products and ten Peruka products. Ten Eldan tunics/T-shirts get new explicit categories. The hospitality waistcoat gets occupational clothing rather than a medical label.
- Break all nine Butterfly graph cycles. Every retained active category and every assigned product has a valid path to one of the 12 departments.
- Separate wig accessories, positioning products, orthosis accessories and rollator accessories from medical clothing. Remove obsolete explicit ancestor assignments, not just old parent links.
- Classify the three FootWave sports-insole products under Wkładki sportowe; split obvious footwear/support/accessory items from the supplier's broad Other category. Unspecified supports stay in the broad support category rather than receiving an invented anatomical indication.
- Resolve broad imported primary categories using explicit product-name evidence where available. Retain broader categories when evidence is insufficient for a narrower target.
- Keep every categorized product's non-ancestor secondary leaf memberships, except retired wrapper nodes and the known shared clothing-accessory contamination.

## Preparation on the existing EC2 host

Use `git archive` to extract only the repair files from the repair branch. Do not switch the deployed checkout, run the production deploy script or rebuild containers for this data-only operation.

```bash
cd /var/www/konji-shop
git fetch origin fix/catalogue-data-repair-20261029
mkdir -p /tmp/konji-catalogue-repair-20261029
git archive 8e4176028f96180ae31d64d3bd500f2ab1c88347 \
  scripts/catalogue/20261029 \
  resources/catalogue/20261029 \
  | tar -x -C /tmp/konji-catalogue-repair-20261029

docker exec konji-shop-prod-app-1 mkdir -p /tmp/konji-catalogue-repair-20261029
docker cp /tmp/konji-catalogue-repair-20261029/scripts/catalogue/20261029/. \
  konji-shop-prod-app-1:/tmp/konji-catalogue-repair-20261029
docker cp /tmp/konji-catalogue-repair-20261029/resources/catalogue/20261029/plan.json \
  konji-shop-prod-app-1:/tmp/konji-catalogue-plan-20261029.json
```

The original export is already inside the app container at `/tmp/category-audit.json`. If it has been lost through container recreation, copy the original exact file back. Do not quietly replace it with a different export.

## Checks and dry run

```bash
docker exec -i konji-shop-prod-app-1 php -l /tmp/konji-catalogue-repair-20261029/run.php
docker exec -i konji-shop-prod-app-1 php /tmp/konji-catalogue-repair-20261029/test.php

docker exec -i konji-shop-prod-app-1 php -d memory_limit=512M \
  /tmp/konji-catalogue-repair-20261029/run.php \
  --mode=dry-run \
  --source=/tmp/category-audit.json \
  --plan=/tmp/konji-catalogue-plan-20261029.json
```

Expected summary: ROOT_DEPARTMENTS=12, NEW_CATEGORIES=12, CATEGORY_UPDATES=314, PRIMARY_REASSIGNMENTS=475, PLANNED_ASSIGNMENTS=51616, LIVE_SNAPSHOT_MATCH=PASS and DRY_RUN=PASS.

## Apply and verify

The database changes are explicitly authorized for this shared new-shop catalogue. Run once after a successful dry run. The apply mode repeats the complete source check while locking catalogue rows, so a stale dry-run result cannot bypass the precondition.

```bash
docker exec -i konji-shop-prod-app-1 php -d memory_limit=512M \
  /tmp/konji-catalogue-repair-20261029/run.php \
  --mode=apply \
  --source=/tmp/category-audit.json \
  --plan=/tmp/konji-catalogue-plan-20261029.json

docker exec -i konji-shop-prod-app-1 php -d memory_limit=512M \
  /tmp/konji-catalogue-repair-20261029/run.php --mode=verify
```

A transaction encloses the database changes. Before the first write, the runner writes an exclusive, checksummed backup of the complete category rows and assignment rows to the persistent application storage volume. It never overwrites an existing backup or receipt. The receipt records the resulting table hashes and the database fingerprint. A failed transaction can leave a backup/receipt file even if the database rolled back; preserve these for diagnosis and do not blindly reapply.

The default persistent backup is `storage/app/private/catalogue-repairs/catalogue-20261029-v1.json`, with a `.receipt.json` companion. No hard deletion of categories occurs. Product-page cache versions change through assignment timestamps; the navigation and catalogue cache namespaces are invalidated after the commit.

If the database commits but cache invalidation fails, output explicitly says DATABASE_ALREADY_COMMITTED=1. Run `--mode=verify` after resolving the cache error; do not rerun apply. Verification also retries namespace invalidation without repeating database writes.

`verify` requires the post-repair category/assignment hashes and product identity projection to remain unchanged. Later imports or admin category edits can invalidate that verification. It is an exact-state receipt check, not a permanent monitoring command.

## Rollback

```bash
docker exec -i konji-shop-prod-app-1 php -d memory_limit=512M \
  /tmp/konji-catalogue-repair-20261029/run.php --mode=rollback
```

Rollback requires the exact post-repair state recorded in the receipt; it refuses to overwrite later category/assignment edits or a different database. It restores original category rows, assignment IDs and timestamps. Newly created categories remain as soft-deleted archived tombstones so IDs are not reused. This restores the original logical catalogue, including its pre-existing category defects. Product rows, variants and orders are not restored or mutated.

## Validation completed before delivery

- PHP syntax check passed using PHP-WASM.
- Standalone PHP graph/snapshot tests passed: stale category/product/assignment rejection, missing parent, cycle, duplicate slug, inactive primary, missing override and wrong product identity.
- PHP independently reproduced every one of the 51,616 planned assignments from the complete 14,627-product frozen snapshot and matched the Python simulation exactly (16 checks).
- Offline simulation verified 12 active roots, zero uncategorized products, one primary per product, root reachability, no cycles, no exact duplicate normalized siblings and no Peruka/Reh4Mat/Mobilex leakage into clothing.
- Actual MySQL transaction execution and live HTTP/browser verification still require running this on the EC2 application. They are not claimed as completed by offline tests.

## Staging acceptance after apply

Check the homepage and sidebar show the 12 departments, with no empty supplier roots. Check clothing contains its actual garments/accessories, wigs include the displaced wig accessories, mobility includes rollator accessories, and sports insoles appear under insoles. Test representatives from Eldan, Peruka, Butterfly, Seni24 and FootWave, plus category pagination and a product page. Record the apply and verify outputs. No importer runs are part of this repair; a later re-import can still recreate bad assignments until the explicitly deferred importer fixes are implemented.
