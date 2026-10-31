# Second product SEO editorial batch

The first batch is already applied and verified: 755 descriptions and four titles, with 189 basic descriptions and 74 duplicate-title groups held. Its private backup is `storage/app/private/seo-repairs/editorial-20261031.laIBDn`; the user reported plan checksum `0d90883956719cee4cfbf3805e3de024ea74c8e807d01669e113c54aef8ec762` and a successful 14,627-product rendered metadata verification.

## This batch

- 124 further description updates: 122 supported by the original product export and two by manufacturer model pages.
- 17 title corrections based on explicit colours, dimensions and manufacturer model codes.
- Seven additional title groups resolve; the EVOFLEX group is partly distinguished. Expected remaining totals: 65 basic descriptions and 67 duplicate-title groups.
- Every target is disjoint from the first batch. Both SEO fields and both bounded source text hashes are checked against the original export.

The description review is explicit per product in `reviewed_copy.py`. There is no broad sentence selector or runtime content generation. Clinical efficacy, stock, prices, delivery times and dosing claims are not added. Incidental packaging material is not promoted to whole-product material. No variant colour, fabric percentage or sleeve option is inferred from a default variant.

Title corrections distinguish EVOFLEX green/yellow, BAHIA bubblegum/tangerine/lemon, LISBOA beige/black, Timago TGO-C OSN 407/408 and four ARmedical pillow pairs with different dimensions. Existing manufacturer image references corroborate the ARmedical model dimensions; the explicit description is the writable-title evidence. Existing conflicting Scholl records and same-model repeated imports remain held. A catalogue-wide collision check in the shared engine rejects newly duplicated corrected titles.

## Manufacturer evidence

Two sparse Wojdak records have an exact model match on the manufacturer's current site:

- Model 1071: <https://wojdak.pl/produkt/fartuch-damski-1071-krotki-rekaw-rofinor-11>. The common model description specifies snap fastening and three pockets.
- Model 3002: <https://wojdak.pl/produkt/spodnica-3002-rofinor-279>. The common model description specifies elastic side panels, button/zip fastening and a rear opening.

These pages represent particular fabric options. The new copy uses only common model construction, supported by the general model description, and deliberately omits fabric composition, colour, sleeve and length choices. Evidence URLs, model numbers, check date and a short supporting quote are stored in the manifest.

## Remaining source work

The 65 held descriptions are fully listed in `review.json`. Most lack usable construction detail in the export; others have sterile/non-sterile conflicts, mixed model names, incomplete descriptions or predominantly clinical claims requiring primary-source confirmation. The active test record and the exported past expiry warning remain held. No record is archived, merged or redirected by this package.

The two Dr Max glass-cleaner listings still appear to represent the same product. Both receive the same supported descriptive wording, and the identity collision remains in the ledger; no artificial difference is invented.

## Apply and rollback

The shared `ProductSeoEditorialRepair` engine from the first batch is unchanged. It identifies plans by the full manifest hash. This second manifest also carries the distinct batch label `product-seo-editorial-followup-20261031-v1`; its independent plan/backup/receipt cannot be substituted for the first batch.

Before any mode, the new precondition verifies the exact first backup checksum, its applied receipt and all first-batch live values. It performs SELECT queries only. If a later edit or rollback is detected, execution stops.

Extract all three source paths from the pinned commit:

```text
scripts/seo/editorial-followup-20261031
scripts/seo/editorial-repair-20261031
scripts/seo/product-database-20261031/Metadata.php
```

On the server, `repair.sh dry-run` creates a fresh plan without database changes. `repair.sh apply` creates a fresh backup, performs the transactional source-checked updates, bumps the catalogue cache and verifies every active product's rendered SEO fields. It prints the new `EDITORIAL_BACKUP` and `EDITORIAL_RUNNER`.

```bash
bash scripts/seo/editorial-followup-20261031/repair.sh apply
bash scripts/seo/editorial-followup-20261031/repair.sh verify PRINTED_FOLLOWUP_BACKUP_DIRECTORY
bash scripts/seo/editorial-followup-20261031/repair.sh rollback PRINTED_FOLLOWUP_BACKUP_DIRECTORY
```

Only `seo_title`, `seo_description` and `updated_at` are writable. The first backup remains intact. Rollback refuses later editor changes. No application source deployment, DNS/nginx change, Redis flush, queue restart or importer change is involved.

## Validation

Python tests reject quotes from other products and replaying first-batch entries, verify exact source hashing and guard manufacturer variant assumptions and held records. Application tests exercise the previous-state precondition, tampered checksum, rolled-back receipt, later editor edits, SELECT-only verification and the complete frozen follow-up metadata through the production normalizer. Existing first-batch tests cover transaction failure, source drift, the write whitelist, new title collisions and rollback.
