# Full preservation evidence for five confirmed duplicate groups

The previous identity review is applied: 14,621 active products, 13 duplicate-title groups, 27 metadata corrections, three name corrections and 16 image-text corrections. Its private applied plan is `storage/app/private/seo-repairs/identity-review-20261101.pOGcEG`, SHA-256 `b3fe38efba39fce3ae697aebb3ebe9bf0a8f8cd553dd414613031605977d7ba3`.

The following five pairs have confirmed model equivalence in the identity ledger. IDs are an unordered review scope, not an instruction to choose a survivor automatically.

| Model | Product IDs |
|---|---|
| Timago RANGER | 9983, 9986 |
| Timago JMC-C 2052 | 10104, 10107 |
| Vicair Active O2 | 12583, 12619 |
| Vicair Allrounder O2 | 12587, 12623 |
| Vicair Multifunctional Back | 12590, 12626 |

The older editorial export sampled at most three images per product and stripped HTML. It cannot support a complete media/document/content preservation plan. Timago pages have different gallery URLs/counts. Allrounder has additional measurement instructions in one body. Full document rows are also needed because document source/external identity has a global unique constraint: blindly copying a document row to another product would violate it.

This SELECT-only exporter gathers exactly these ten identities, refusing changed source IDs/slugs/names, missing, archived or deleted records. It verifies the prior applied receipt, then reads the catalogue in one transaction. It includes complete raw descriptions and short descriptions, explicit identity/SEO/default-image fields, every variant (including soft-deleted rows), every gallery/attribute image/document row, ordering/captions/checksums, category links, attribute/variant pivots and the relevant attribute/category definitions. There are no sampling or truncation limits.

The existing schema-aware reference audit exports counts only for commercial and polymorphic references. It never exports order/customer/cart payloads. Nonzero references and unresolved schema discoveries remain review blockers; exporting is not merge approval. Media paths/stored checksums are evidence, not proof that the underlying files currently exist or match their stored checksum.

`download.py` uses the user's existing AWS CLI profile `konji-shop`, region `eu-central-1`, target `i-0ae94208f2d069dbc`, app container `konji-shop-prod-app-1`. It checks the five-file PHP/review bundle before sending it, stages it in private temporary directories, downloads through the existing bounded/chunked SSM transport and checks compressed and JSON SHA-256 values. It writes a new mode-0600 local JSON file and refuses to overwrite an existing output. Database writes, archive operations, redirects, runtime patches and configuration changes are not part of this step.

Run the pinned downloader from the local development machine where that AWS profile is available. Extract this directory plus `scripts/seo/variant-review-20261101` and `scripts/seo/colour-correction-20261101` into a temporary checkout, then run `python3 .../download.py --output "$PWD/konji-seo-consolidation-review.json"`. Upload that JSON for the preservation review. No new permissions or server credentials are needed.

After reviewing the complete snapshot, prepare the separate guarded consolidation: explicit survivor per pair; preserve useful descriptions, all unique media/documents and category coverage; retain source/history/variants; abort on changed evidence or unreviewed references; install same-host 301 aliases; verify old aliases remain intact; check new source 301s and target 200s/canonicals/sitemap; and retain an exact rollback backup. Five archives would leave 14,616 active products and eight duplicate-title groups if no other catalogue changes occur. Those are projections, not a claim that this exporter changed any data.

Tests cover complete HTML, eight gallery images (including images beyond the former three-image sample), attribute images, documents, all variants, attribute definitions, commercial count-only references, no DML, identity drift and invalid scope. Python tests validate the exact scope, bundle checksums, shell syntax, package round trip and input rejection. Existing source records, aliases, controllers, importers and all 1,070 nginx rules remain untouched.
