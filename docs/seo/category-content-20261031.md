# Category content and distinct product titles — 31 October 2026

## Delivered scope

Original Polish category copy covers the 12 repaired top-level departments and 23 principal shopping categories (35 total). Each entry has a dedicated meta description, an introductory paragraph and additional selection information. Copy is keyed by exact slug and expected category name; it is not inherited by unrelated descendants. Existing database descriptions and SEO descriptions take precedence. If a category is renamed, the reviewed fallback no longer applies until reviewed again.

Copy is versioned in `resources/content/category-copy.pl.php`. It is rendered above and below the listing; the additional text appears on page one only. Blade escapes the text. No database records are written, so database-only missing-description counts remain unchanged. This batch supplies storefront content for 35 categories, not all 1,119 active categories. The remaining 1,084 need their own editorial review; no blanket boilerplate is generated for them.

Product SEO titles retain the full cleaned name or authored SEO title rather than cutting at 70 characters. This preserves trailing dimensions and model identifiers. Search results may truncate display independently. The product cache uses v8 so cached truncated titles are not reused. Visible product names, URLs, assignments, prices and publication dates do not change. The 103 exact duplicate title groups still require review; this change does not invent colours, merge products or redirect them.

## Continue the review

`scripts/seo/export-content-review-20261031.php` performs SELECT queries and emits JSON only. It exports all active category names, hierarchy, existing descriptions and active assignment counts, plus the complete exact-duplicate product title groups with product-level attributes and distinct active-variant attribute values. It includes only public catalogue fields and does not hydrate thousands of variant models. Redirects, customer/order data and credentials are excluded.

Run it from `/var/www/html` inside the app container, redirect stdout to a host JSON file and return that file for the remaining category and duplicate-title review. A group of values in variant metadata represents a range of options, not a unique property of the whole product; do not append the default variant's colour or size as though it describes every option. Ambiguous numbered slugs are not sufficient evidence to merge products.

## Deployment

Extract the two controllers, category view, copy file and `scripts/deploy/sync-seo-content-20261031.sh` from the reviewed commit into an isolated directory. The script checks pinned known sources in app/queue/scheduler, backs up each role separately and records absence of the new copy file. It syntax-checks PHP, installs the four files, verifies hashes, compiles views and reloads app FPM. Failure restores the prior source files, removes the new file if originally absent, recompiles views and reloads FPM. It does not alter database records, Nginx, redirect maps or PHP limits. Existing old repair scripts are pinned to earlier hashes and should not be reused after this patch.

Validate a covered department, a covered leaf, a category without reviewed copy, page two, and the repaired Mediven product twice after applying. Staging must retain its noindex response header. The 1,070-rule redirect map is outside this change.

## Validation

Regression tests cover all 35 copy entries, database-field precedence, renamed-category fallback, pagination, long titles and cache serialization after the title change. A filesystem-only deployment test exercises success with an initially absent content file, preflight drift rejection and source rollback, including removal of the newly added file.
