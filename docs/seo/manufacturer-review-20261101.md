# Third product SEO editorial batch — 2026-11-01

The previous two editorial batches are applied and verified: 879 improved descriptions and 21 titles. All 14,627 active products have nonblank database SEO titles and descriptions.

This batch changes 30 previously untouched products: 17 descriptions and 14 titles (Medisorb A changes both). It leaves 48 original basic descriptions and, against the exported catalogue plus the two applied manifests, 61 duplicate-title groups. These are review counts, not a promise that every duplicate represents a distinct product. Real duplicate listings need an identity/variant/usage audit before consolidation.

## Evidence and scope

`review.json` freezes the exact product identity, previous metadata and description hashes. The builder reads the original targeted export and both preceding manifests; it refuses replaying any prior target. Manufacturer sources and short evidence excerpts are attached per product. Web sources were checked on 2026-11-01.

- Wojdak 1062, 2117 and 2091: individual manufacturer pages identify common fastening and pocket construction. Fabric, sleeve and colour options from the displayed variants are not applied across the whole product. The manufacturer PDF screenshot was unavailable; no visual inference was used.
- Eldan KD MED 135: the manufacturer's category card explicitly identifies ecological-leather medical clogs.
- Granugel: Convatec identifies the 15 g product as a sterile hydrocolloid gel dressing.
- Medisorb A: the brand's product page identifies calcium alginate fibres. The imported `algininowy` spelling is corrected in the SEO title only. No pack-size claim is added from the manufacturer's different pack listing.
- Octenilin: Schülke's 350 ml solution GTIN 4032651217014 matches the guarded imported body. Composition is taken from that solution page, not the imported gel paragraph. No opening-life or allergy claim is added.
- Scotchcast Plus: Solventum identifies fiberglass and water-activated resin. Family construction only: dimensions, colour and carton count from the displayed 82001 option are not imported.
- Granudacyn: the manufacturer's solution IFU identifies water, sodium chloride, hypochlorous acid and sodium hypochlorite. No dosage, concentration, clinical outcome or variant volume is added.
- Eight Meditrade products: the guarded body codes MED1137–MED1140 and the exported default SKUs match the manufacturer's sterile, four-layer reference table (2020 catalogue, printed page 69). Material percentages come from each product's own body. Store pack quantities remain as supplied. The build refuses mismatching body/SKU references.
- Thirteen Scholl titles use colours explicitly stated in their own body text. No colour is inferred solely from a slug or image. Remaining identical navy Nautilus records and EVOFLEX records lacking body colour evidence stay held. One Aberdeen record has conflicting short/body colours; only its unambiguous counterpart is distinguished.

## Outstanding source quality issues

The metadata repair does **not** fix imported long descriptions. Ten affected records are explicitly listed under `body_description_followup`: eight Meditrade sterile/non-sterile contradictions, Octenilin gel/solution and sterility/opening-life contradictions, and Scotchcast Plus traditional-gypsum composition. Those visible source descriptions still require a separate reviewed correction. The new metadata does not repeat the false claims.

Held records include older manufacturer models without accessible detailed specifications, an active test product, an empty plaster description, stock/expiry wording requiring inventory confirmation, and unresolved product-family mismatches. No clinical benefit or specification is invented to replace those gaps.

## Runtime protections

The wrapper verifies checksums before and after copying into the app container. The runner verifies **both** prior applied backups, their pinned plan hashes, receipt state, product evidence, values and timestamps before every mode. The existing shared repair engine then checks exact source and metadata under row locks, simulates global title collisions, commits only SEO title/description and `updated_at`, and retains immutable private plan/backup/checksum/receipt files. Verification checks all active metadata against rendered controller values. Rollback refuses subsequent edits.

No change to slugs, product names, long descriptions, variants, prices, publication, status, categories, redirects or web-server configuration. Existing full engine tests cover changed source, changed metadata, collisions, transactions and guarded rollback. New tests cover the frozen batch, both previous dependencies, reference mismatches, held records and manufacturer-option boundaries.

## Apply on the server through SSM

Extract the reviewed commit with all three editorial folders and `scripts/seo/product-database-20261031/Metadata.php`, then run:

```bash
bash "$seo_review_dir/scripts/seo/manufacturer-review-20261101/repair.sh" apply
```

`apply` creates a fresh private backup, runs a write-free dry run, applies transactionally and verifies. Keep the printed backup directory. For verification or rollback use this same reviewed checkout and pass that backup path as the second argument. Both prior backup directories must remain available and unchanged.
