# Product SEO editorial pass – 31 October 2026

The uploaded, checksummed export confirms 14,627 active products have nonblank SEO fields, 944 still use a basic generated description, and 76 title groups collide. It contains factual evidence for 1,109 selected products. This package is the next editorial batch, not a declaration that the remaining source-data issues are resolved.

## Reviewed changes

- 755 description updates use construction, composition, packaging or compatibility facts from the exported product text.
- Four title updates distinguish Scholl Clog Back Guard navy/white listings and KING toilet-seat risers of 10/15 cm. Two duplicate groups resolve; 74 remain held for identity review.
- No record is merged, archived, redirected or renamed. Pricing, publication dates, descriptions, variants and categories are outside the update whitelist.
- Each manifest entry freezes both existing SEO fields, product identity and hashes of the exported description/short-description evidence. Evidence quotes remain beside the proposed copy.
- The offline builder is a reproduction aid for this frozen export, not an importer or an unattended generator for future products. The original export is not committed.

Examples:

| Product | New description |
| --- | --- |
| MIA blouse, ID 1 | Bluza kosmetyczna damska ze spandexem MIA. Skład: 92% poliester / 8% spandex. Dwie dolne kieszenie. |
| Medisorb G+A, ID 13505 | Opatrunek hydrożelowy Medisorb G+A 1 szt. Tubka do aplikacji punktowej. Postać przezroczystego żelu. |
| FootWave SPORT, ID 14347 | FootWave™ SPORT. Wkładki sportowe z materiałem WavePU i wyściółką CoolMesh. |

Composition lists must total 100%. Generic Seni Care marketing sections, instructions, variant listings and unrelated product mentions are excluded from evidence extraction. No default-variant colour is promoted to a whole-product fact. The proposed copy does not add promised clinical outcomes, stock, prices or delivery claims.

## Held work

`review.json` lists all 189 descriptions held in this batch, including 130 needing further source-specific editorial review. These are not approved rewrites and remain basic descriptions. The other holds include 44 records with little usable source text, eight sterile/non-sterile contradictions, one empty product description, an active test product, mixed FootWave model names, a solution/hydrogel mismatch, and an exported expiry warning dated 2026-05-30. The warning describes source text and does not establish the expiry of current shop stock.

All 76 duplicate groups have a ledger entry. Repeated imports and sale/regular listings must be checked against manufacturer identifiers before consolidation. No artificial product IDs, SKU suffixes, colours or sale claims are appended solely to make a title unique.

## Execute on the server

The package can be extracted with `git archive` from the reviewed commit into a temporary directory. Include both `scripts/seo/editorial-repair-20261031` and `scripts/seo/product-database-20261031/Metadata.php`.

```bash
bash scripts/seo/editorial-repair-20261031/repair.sh dry-run
bash scripts/seo/editorial-repair-20261031/repair.sh apply
```

`apply` makes a new dry-run plan/backup, checks the live source inside a database transaction, writes only `seo_title`, `seo_description` and `updated_at`, writes an applied receipt, bumps the catalogue cache version and verifies all active metadata against controller output. No source-code deployment, FPM reload, queue restart, Redis flush, DNS or nginx change is needed.

The script prints `EDITORIAL_BACKUP` and `EDITORIAL_RUNNER`. The backup includes old and intended SEO values plus original timestamps. The plan and receipt have checksums; rerunning the PHP apply runner after a post-commit interruption can recover verification/cache invalidation.

```bash
bash scripts/seo/editorial-repair-20261031/repair.sh verify PRINTED_PRIVATE_BACKUP_DIRECTORY
bash scripts/seo/editorial-repair-20261031/repair.sh rollback PRINTED_PRIVATE_BACKUP_DIRECTORY
```

Rollback checks all targeted identities, source evidence and applied metadata/timestamps before restoring any field. A later editor change refuses the whole rollback. The original database repair backup remains untouched. Private backup paths must not be placed under the public web root.

## Validation

Python regression cases cover related-product contamination, filler ingredients, packaging materials, invalid fabric totals, negation and variant options. Application tests cover read-only planning, the exact write whitelist, source drift, tampered plans, transaction failure, rollback and later edits. CI also validates the frozen manifest with the production metadata normalizer.
