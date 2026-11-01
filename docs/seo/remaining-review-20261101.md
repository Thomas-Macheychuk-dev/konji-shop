# Eight remaining duplicate-title identity reviews

Current verified deployment: 14,618 active products with matching explicit/rendered SEO metadata, eight duplicate-title groups, nine same-host aliases passing origin HTTP checks, four distinct Timago colour offers retained. Prior applied final consolidation backup: `storage/app/private/seo-repairs/consolidation-final-20261101.Cmcdoi`, plan SHA-256 `f94e4f51499cbb2f6b5e920c1248d83fa3b4c9403660b2ee4490c9a9c26727be`.

The earlier complete preservation export covered the ten products from the preceding batch. It did not include these sixteen records. Older review files contain stripped/truncated descriptions, sampled images and incomplete raw media/reference state. They support investigation, not a new write plan. This batch therefore gathers a complete, fresh SELECT-only snapshot of the exact remaining scope and records the preliminary identity findings in `identity-ledger.json`.

| Group | Product IDs | Current finding and next evidence |
|---|---|---|
| SPARTAN | 8869, 8870 | Distinct exact manufacturer post IDs/canonical pages and visibly different frames/hinges. Shared fitting/manual links do not establish generation/configuration equivalence. Retain both pending confirmation; do not invent a year. |
| FP-25 | 9309, 9332 | Identical previous FP-25 copy, both describing M/L, but no sampled images or numeric source IDs. Official FlexPoint page confirms the model family; still need to map the imported source identities. A `-2` suffix is insufficient. |
| AirFit N30i | 10479, 10523 | Previous source records have S/M versus Small option sets and symbol 63880/63816 versus 63880. ResMed identifies 63880 as a complete S-cushion/Small-frame system. Supplier mapping of 63816 and the M option remains necessary; do not call 63880 a cushion-only part or discard an option. |
| AT04702 | 11039, 11040 | Both supplier URLs now resolve to the same `-kopia` canonical page. Strong consolidation candidate, preserving the existing priced product/variant, subject to current full media/category/reference checks. No selling-price or SKU/stock migration is proposed. |
| protect.Ankle air foam | 12021, 12022 | Previous records contain equal active options but prices of 56 versus 200 PLN and SALE versus regular source coding. Retain both until current offer/clearance policy is established. No invented condition/discount statement or automatic price choice. |
| PEPI | 12503, 12562 | Shared model/photos and matching basic measurements are not the whole evidence: manufacturer manual lists seat depth 20 cm; one imported body says 22 cm. Inspect actual linked manual versions/full HTML and reconcile the specification first. |
| Liberty Back | 12588, 12624 | Body code families LBS versus LBC are meaningful. Official historical order forms distinguish a back system including adjustable straps from a cushion-only offer. Confirm package contents; identical pictures are insufficient. |
| Anatomic Back | 12589, 12625 | Different size lists, plus a manufacturer order-form distinction between cushion/cover and cushion-plus-strap-back systems. Confirm package and standard/custom sizes before consolidation. |

## Sources and limitations

The machine-readable ledger gives primary source URLs, facts, blockers and downloaded page hashes where available. Technical manufacturer/distributor documents are used for identity and package semantics, never to import historical prices or assert current stock/availability. The PEPI PDF filename includes 2025 but contains older internal manual text; it is not treated as proof of a 2025 redesign. Its seat-depth discrepancy remains open.

One search-service open request for a Vicair manual incorrectly returned an unrelated equipment PDF. That result was rejected. The Liberty distinction instead uses the official Invacare order form and the Vermeiren-hosted Vicair manual. Third-party shop descriptions and search snippets are not used to declare two physical offers equivalent.

No database edits, archives, winner selections, metadata changes, price/stock changes, redirects, importer changes or runtime patches are included in this batch. The nine existing aliases, prior immutable repair engines/checksums and all 1,070 nginx rules remain unchanged.

## Export and download

`RemainingExport.php` is a separate read-only exporter; the previously pinned preservation exporter remains unchanged. The new review/version is restricted to eight disjoint pairs and the exact sixteen IDs above. It verifies names/slugs/source IDs and active/non-deleted status, refusing missing or changed identities. The entry point verifies the applied consolidation receipt, then reads one consistent catalogue snapshot in a transaction.

Exported data includes complete raw HTML/short descriptions and explicit identity/SEO/default-image/timestamp fields, all variants including priced/active and soft-deleted rows, every gallery/attribute-image/document row, category/product/variant pivots and relevant attribute/category definitions. Source/price differences are preserved as evidence. The existing schema-aware reference audit exports counts only, never commercial/customer/cart payloads. Stored media paths/checksums are exported; the exporter does not independently validate binary file availability/content.

Run the pinned downloader from the local development computer where AWS profile `konji-shop` is configured (region `eu-central-1`, target `i-0ae94208f2d069dbc`). Extract this folder, `scripts/seo/variant-review-20261101`, `scripts/seo/colour-correction-20261101` and `scripts/seo/editorial-review-20261031/download.py` into a temporary directory. Run `python3 .../download.py --output "$PWD/konji-seo-remaining-review.json"`. It uses SSM automatically; no shell copy/paste from nano is needed. Upload that JSON for the guarded repair review.

The downloader verifies the five-file exporter bundle, stages it in private temporary directories, transfers through the existing bounded/checksummed SSM transport and writes a fresh mode-0600 local output. Existing files are not overwritten. The printed checksum confirmation is a transfer-integrity check, not an automatic merge decision.

Integration tests cover exact sixteen-record scope, full long HTML, every media/document row, active priced variants and soft-deleted variants, attribute pivots, commercial count-only references, absence of DML/payload export, missing/archived/deleted/changed identities and overlapping scope. Python checks cover the eight pairs, bundle integrity, shell syntax, payload round-trip and invalid container/extra-file rejection. No application source or configuration deployment is required to run the export.
