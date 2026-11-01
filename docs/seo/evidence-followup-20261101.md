# SEO evidence followup and duplicate identity export — 2026-11-01

This batch updates 23 previously untouched products: 23 SEO descriptions and five SEO titles (four product-code distinctions and the missing space in Spodnie S4034). Nineteen of the 48 basic-description holds receive supported product facts, leaving 29. Two crutch duplicate-title groups receive distinct source product codes, reducing the expected catalogue duplicate-title groups from 61 to 59. There is no product consolidation in this batch.

## Evidence review

Twelve Wojdak descriptions use explicit model-level labels from the manufacturer's 2023 catalogue: waist construction or the labelled double pockets. The parsed catalogue provides the model/label association and printed page recorded per entry. Screenshot requests redirect to the manufacturer's download page; no personal visual inspection is claimed. No fabric, colour, sleeve choice, stock status or dimensional option is inferred from a displayed garment. Copy remains deliberately short where one reliable construction fact is all the source supports.

Five medical descriptions use primary manufacturer material: Reha Fund BioFlote 2000 and HF6001 mattress construction; Schülke Wound Pad's material/structure and matched 12-unit GTIN; Aegis SURGISPON's construction and sterilisation; Convatec Granuflex Bordered's outer border. Pump specifications, manufacturer carton quantities, variant dimensions, refund claims and clinical guarantees are not imported. The two mattress offers explicitly remain without a pump.

The manufacturer's current FootWave MAMA headline confirms the pregnancy audience. LADY VERSA's named introduction confirms its footwear use and profiled construction. Both pages still contain LADY PREGNA text elsewhere; no renaming claim or feature copied from a misheaded section is used. This batch improves their metadata, not those longer source bodies.

Four MedReha crutch records explicitly carry different source codes CM-1010BLU and CM-1011 and different retail pack sizes. Titles preserve that evidence. Their descriptions use the own-body height adjustment and aluminium construction. No load rating or colour is inferred from the contaminated attribute strings. Product codes are evidenced identifiers, not invented database-ID suffixes.

`review.json` freezes exact current identity/metadata and source hashes. Its source-cleanup prerequisite is the applied server backup `storage/app/private/seo-repairs/source-cleanup-20261101.iGilON`, SHA `6d873cfb78350ea580f80d2c763576237cea64af6c9d28e40ff5bae81c29f827`. The existing receipt checker and shared metadata engine provide a fresh private backup, transaction, live source/SEO guards, global title-collision checks, cache invalidation, verification and guarded rollback. No source-description fields, prices, variants, status, publication, categories or redirects change.

The original targeted export plus all five previous manifests reproduces the reviewed current state. `build_review.py SOURCE OUTPUT --prior-root scripts/seo --fixture-output tests/Fixtures/Seo/evidence-followup-20261101.json` rebuilds the manifest and fixtures. Both normalized source fields must match before a target is updated.

## Read-only duplicate review

The original export included only default variant identifiers and collapsed attribute values. That cannot establish whether repeated models, sale/regular listings or partial variant ranges should be consolidated. The new export finds duplicate titles in the **current active catalogue**, then reads every variant of those products, including inactive and soft-deleted variants, their SKUs/external identifiers, prices, stock flags, option values, product attributes and bounded source descriptions.

Exact source-SKU/option signatures and option-only signatures are review signals. Different source SKUs do not prove different physical products; equal option sets do not prove duplicate products. Empty or incomplete option data never becomes a match. No automatic canonical choice, archive, merge, redirect or database mutation is performed. Order/cart/media reference eligibility remains necessary before any future consolidation. No customer/order data is exported.

The downloader reuses the existing AWS CLI SSM transport with backward-compatible optional schema validation. It stages a fixed three-file SELECT-only exporter in a private temporary directory, obtains one transactional snapshot, downloads bounded gzip chunks, checks compressed and uncompressed SHA-256 checksums and creates a new local file with private permissions. It refuses an existing output path. Transport tests for the original export retain their default validation.

## Execution

After merging, extract the pinned commit and run the new `repair.sh apply` **on the server through SSM**. Include the metadata engine folder, Metadata.php and the colour-correction folder for receipt validation. Keep the printed backup path.

Then, **on the local computer with the authenticated AWS profile**, extract the new folder, the existing editorial-review transport folder and Metadata.php from the same commit and run:

```bash
python3 "$seo_export_dir/scripts/seo/evidence-followup-20261101/download.py" \
  --output "$PWD/konji-seo-duplicate-review.json"
```

Upload that local JSON for the next identity review. It records current values and variants; it does not rerun repairs or write to the database. Remaining basic-description holds stay explicit in the review ledger, including the test product, empty plaster source, expired-date wording needing stock confirmation and models without sufficient manufacturer evidence.
