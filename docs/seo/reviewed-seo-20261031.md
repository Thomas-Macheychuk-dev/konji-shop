# Reviewed SEO follow-up — 31 October 2026

## Input and scope

The user supplied the public-catalogue export dated `2026-10-31T16:28:50+00:00`: 1,119 active categories and 103 exact-title groups containing 231 products. The pasted file was missing its initial opening brace and had terminal text appended to its first line. A working copy restored that JSON wrapper only; all category and product values were retained. Counts match the export summary.

All 103 groups have a decision in `docs/seo/duplicate-title-review-20261031.json`. The supported editorial corrections cover 72 products: 34 groups become distinct within the reviewed group and two groups are partly corrected. Another 67 groups are held. These are reviewed-group counts, not an exhaustive new-title collision scan of the whole catalogue. A distinct resulting title does not establish product identity: the ledger separately lists every unchanged product and flags identity review, including unchanged members of groups whose title collision is removed by correcting a sibling.

Reasons include copied supplier/retailer titles, titles describing a different product, omitted model identifiers and colours explicitly confirmed in product descriptions. Examples: Petermann PM 2070 versus PM 2076 with distinct dimensions; Rehafund BF 2000/M versus HF6001; Levamed active versus protect.Leva; mediven forte tights versus knee-high stockings; WatchBP O3 AFIB versus O3; and the distinct Klé cushion forms.

Held cases include likely duplicate imports and contradictory identities, such as a rubyred URL with a silkgrey product name, a white shoe URL with a black description, and numbered slugs without a reliable distinguishing attribute. No products are merged, canonicalized to another product, archived or redirected by this patch. Dirty multi-value attributes are not treated as a single product colour. In particular, variant size ranges are not appended as though only the default size were sold.

## Rendering and editing

Title corrections are versioned in `resources/content/product-title-copy.pl.php`. They apply only when product ID, name, slug, source, external ID and existing SEO title match the reviewed record. A subsequent edit to any guarded field disables the correction. Titles are changed in rendered metadata/Open Graph, not in product names or database fields. Existing group counts from database-only audits therefore do not measure the corrected rendered titles. Product cache v9 avoids older rendered title entries and retains the scalar-snapshot memory repair.

There are 24 additional original category entries in `resources/content/category-copy.pl.php`, bringing coverage from 35 to 59. These focus on hairpieces, turbans, wigs, care products, footwear and clothing subcategories. The remaining 1,060 active categories are not declared complete. The existing exact-name guards, database-authored copy precedence and first-page-only extra text remain in effect. Twenty-three exported categories have zero assigned active products; that count alone is not a reason to delete or redirect them.

## Apply and verify

`scripts/deploy/sync-reviewed-seo-20261031.sh` installs the product controller and two copy files consistently in app/queue/scheduler. It accepts only pinned prior/candidate sources, records separate role backups (including absence of the new title file), syntax-checks all three PHP files and reloads app FPM after verification. Failures restore prior files and reload FPM. It does not write the database, flush Redis, change Nginx/maps, raise memory limits or change publication state. Its filesystem-only test covers success, source drift rejection and rollback with removal of the originally absent file.

After applying, check representative corrected product titles, a held product, an added category, a previously covered category, and the 2,688-variant Mediven product twice. Preserve staging noindex. A result of `SEO_REVIEW_PATCH=PASS` confirms source installation; HTTP verification is still required.

## Remaining work

Resolve held product identities against richer source evidence before consolidation or canonical decisions. Continue category-specific editorial review for the remaining categories. This work does not repair pricing, publication eligibility, supplier medical claims or scraped third-party shipping/price promises in descriptions; those remain separate catalogue issues.
