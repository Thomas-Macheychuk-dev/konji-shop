# Duplicate identity and variant review — 2026-11-01

This batch updates 12 SEO titles and 10 SEO descriptions. It resolves ten of the 42 remaining duplicate-title groups, leaving an expected 32. The preceding 38-product title repair is a prerequisite: private backup `storage/app/private/seo-repairs/duplicate-codes-20261101.FLNjoA`, plan SHA-256 `6c34bbfad5916164c634761a19b6c753537eff738f8c9646ed10dc3f9b01f758`. The twelve targets were untouched by that batch, so their exact metadata and source evidence still match the full-variant export.

## Reviewed distinctions

Seven medi sale/regular groups have one specific active variant on the narrower offer and a broader configuration range on the other page. Titles now identify the narrower offer's actual size and colour, with a pressure designation where useful. Descriptions state the same configuration without generic brand slogans, clinical promises or a temporary sale label. These remain separate offers; this is not a consolidation decision and does not claim the narrower configuration is absent from the broader range. The protect.Ankle air foam pair has equal options and stays held.

The two Dr Max cleaners have different explicit source index codes (CH-221-S500-N08 and CH-221-S500-Z01). Titles preserve those exact codes without guessing colours from code suffixes. Existing descriptions are retained.

The Matovlies pages have different complete variant ranges. Product 14106 has four-layer options in 5×5, 7.5×7.5 and 10×10 cm. Product 14279 has four-layer 10×20 cm options and a six-layer 10×10 cm option. Titles and descriptions state the range distinction. The second page's explicit paper/foil bag is preserved; the first is not assigned an unsupported packaging material.

Product 7988 has a WINE product name/title, a sangria slug and a body describing a long curly synthetic hairpiece with 35–60 cm strands and a clip. Its new metadata is neutral: only the own body facts, without asserting WINE, SANGRIA, heat resistance or a colour. Its product name, URL and source body remain untouched pending identity verification. This removes an unsupported model claim rather than asserting a model rename.

## Holds and safeguards

All 42 incoming groups have a disposition in `review.json`. Reh4mat's grey/black differences describe recommended OKG accessories, not the main AM-OSN-U-02 product, so they are not used as title distinctions. Wig colour disagreements between names and slugs, similar Vermeiren pages, sale/regular duplicates with equal options, overlapping or incomplete product families and unverified import suffixes remain held. The 29 unresolved basic-description records from earlier work are unchanged.

`VariantGuard.php` wraps the existing immutable-plan/backup repair engine. Exact product identity, source-description hashes and current SEO values still have to match. Every variant ID, SKU, external ID, status, soft-delete marker and linked option ID/name/value is pinned, including inactive/historical variants. Apply and rollback lock and recheck variant rows and linked option rows in the same outer transaction as the existing engine. Price and stock edits do not invalidate copy evidence. Variant additions/removals, changed SKU/status/options and later manual SEO edits block writes. Live global title-collision checks, cache invalidation, all-active rendered metadata verification and guarded rollback remain in place.

No product/variant status, prices, stock, publication, names, source descriptions, categories, URLs, canonicals or nginx redirects change. Existing 1,070 legacy redirect rules are untouched. Tests cover successful apply/rendered verification/exact rollback, changed SKU, changed option, an added variant and later configuration changes before rollback.

## Read-only reference audit

The export reuses the full-variant duplicate exporter against the current remaining duplicate groups and adds aggregate per-product reference counts. It discovers named product/variant columns, declared foreign keys (including nonstandard column names) and recognized Eloquent polymorphic types. Counts include soft-deleted rows and all candidate variants. It records the tables/columns inspected and configured default-image pointers. It never exports customer/order/cart payloads, addresses or personal details. The original product/variant catalogue fields remain in the export.

These counts do not prove consolidation eligibility. Product and variant counts may overlap. Unknown JSON/text references, application business rules, external links and nginx mappings still require review. Composite/non-ID foreign keys are explicitly listed as unresolved limitations. No automatic winner, merge, archive, canonical, redirect or deletion is performed.

After applying the batch, download the new read-only export on the local computer using the existing AWS profile. Extract this folder, `scripts/seo/editorial-review-20261031`, `scripts/seo/evidence-followup-20261101/DuplicateExport.php` and `scripts/seo/product-database-20261031/Metadata.php` from the pinned commit, then run `python3 .../variant-review-20261101/download.py --output "$PWD/konji-seo-reference-review.json"`. The transport uses private temporary files, gzip chunking and compressed/uncompressed checksum validation, and refuses an existing output path. Upload the resulting local JSON for the next consolidation review.

For the repair itself, extract this folder, the shared `editorial-repair-20261031` and `colour-correction-20261101` folders, and `product-database-20261031/Metadata.php` on the server through SSM. Run `repair.sh apply` from this folder; it performs dry-run, apply and verification. Preserve its printed private backup path. `verify` and `rollback` take that backup path as their second argument.
