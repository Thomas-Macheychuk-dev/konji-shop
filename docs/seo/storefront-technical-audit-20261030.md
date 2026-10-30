# Storefront SEO foundation — 30 October 2026

## Evidence and changes

Reviewed the shared new-shop catalogue on staging after the catalogue repair and view sync. This review does not authorize the production host cutover.

| Finding | Evidence | Change |
| --- | --- | --- |
| Paginated categories canonicalize to page one | Live walking-aid accessories page two and controller source | Each populated page has its own canonical and page title; tracking parameters are excluded |
| Category paginator cache can retain another host's URLs | Category cache key and cached paginator source | Include scheme and host in the key, clone the paginator and rebuild its public path |
| Missing category descriptions and breadcrumb schema | Sampled live category HTML | Retain authored metadata; otherwise provide a category-specific fallback; emit the visible ancestor trail as BreadcrumbList |
| Retailer is incorrectly declared as product brand | Live Butler product JSON-LD and controller source | Omit unverified brand; retain seller and offer data |
| Homepage WebSite uses the old shop name | Live homepage JSON-LD | Use the configured public application name; retain legal company identity |
| Sitemap omits NFZ and changes static-page lastmod on every request | Sitemap source | Include NFZ; omit dates without recorded evidence; retain category and product record dates |

Regression coverage includes host switching with cache enabled, page-one normalization, page-two canonicals, empty pagination returning 404, authored metadata, hidden ancestors, structured-data identity and sitemap modification dates.

## Runtime checks still needed

`scripts/seo/audit-runtime-20261030.sh` reads catalogue metadata, renders the isolated known failing product, reports the installed legacy map hash/rule count and requests origin response headers for both hosts. Run it from a checked-out copy of these audit files on the host. It copies one temporary diagnostic file into the app container. It does not write catalogue records, change configuration, reload services, modify redirects or change DNS. Rendering can populate normal application caches.

The source Nginx staging server sets `X-Robots-Tag: noindex, nofollow, noarchive`. Verify the deployed response before treating staging protection as confirmed. Keep this protection until an explicit host cutover. Do not block all staging crawling in robots.txt: crawlers need to receive the noindex response.

The known failing URL is `/products/mediven-forte-podkolan-wki-uciskowe-1` (product 12003). It still returned a server-error page during this review. The diagnostic prints the isolated exception and trace without arguments to guide a separate fix.

Metadata counts distinguish missing dedicated SEO fields from missing all descriptions. A missing dedicated SEO field alone is not evidence of an empty rendered tag because the storefront has fallbacks. Duplicate effective title samples and products without active priced variants need editorial/data review before changing eligibility or URLs.

Search Console performance, indexing and query data were unavailable because the connected GSC Wizard account required an active subscription. This review makes no ranking or indexing-performance claims.

## Protected migration scope

Existing approved legacy product maps and the 37 repaired category aliases are unchanged. Earlier migration verification recorded 1,070 approved legacy product rules; read the installed map to establish current runtime state. No redirect expansion, map replacement, database repair or production launch is part of this patch.

The earlier pinned catalogue view-sync script accepts specific source hashes. It is not a deployment mechanism for these new controller versions. Prepare a separate reviewed deployment with app/queue/scheduler source consistency and preservation of the installed redirect configuration.

## Follow-up priorities

1. Diagnose and fix the known product 500 using the isolated runtime report.
2. Verify staging noindex and production-host headers, installed redirect lineage, robots and sitemap responses.
3. Review missing content, duplicate titles and product offer eligibility from the runtime counts; write useful Polish category and product content for priority pages.
4. Obtain Search Console access/data, establish a baseline and validate migration targets before launch.
5. Perform a separate cutover review covering canonical host, staging protection, redirect targets, sitemap submission and post-launch monitoring.

## Primary references

- [Google: pagination and incremental page loading](https://developers.google.com/search/docs/specialty/ecommerce/pagination-and-incremental-page-loading)
- [Google: product structured data](https://developers.google.com/search/docs/appearance/structured-data/product)
- [Google: sitemap lastmod guidance](https://developers.google.com/search/blog/2023/06/sitemaps-lastmod-ping)

## Observed server baseline after PR 111 was merged

The supplied server report records 14,627 active products and 1,119 active categories. All active categories lack both dedicated SEO descriptions and category descriptions. Product counts: 319 lack dedicated SEO titles, 320 lack dedicated SEO descriptions, one lacks all description sources, 1,981 lack an active variant with a positive stored gross or net price, and 13,183 lack a publication date. These are baseline database counts, not a confirmation that PR 111 is deployed. Publication dates affect the storefront's purchase eligibility and require a separate review before mass changes.

There are 269 groups sharing an effective title after the controller's 70-character truncation. Samples include legitimate colour variants and dimensions cut off by truncation. This is not proof that the underlying products should be merged or redirected. The follow-up audit also reports untruncated duplicate groups and importer-level counts.

Origin HTTP checks returned 200 for `/`, `/robots.txt` and `/sitemap.xml` on both hosts. All three staging responses include `X-Robots-Tag: noindex, nofollow, noarchive`; the corresponding apex responses do not. This establishes origin header behaviour, not public DNS cutover or Search Console indexing status.

The installed map contains 1,070 rules with SHA-256 `df806bf23d22c0420fda442811b5bf9137bd5c6ed2e64023898eac496cf58bee`. Its runtime include is present. The diagnostic did not exhaustively verify redirect destinations or compare this hash to an approved release receipt.

The initial direct controller-render probe reported `Undefined variable $errors`. That probe bypassed the web middleware that supplies the validation error bag, so this exception does not diagnose the previously observed browser 500. The revised probe supplies the error bag and a non-persisted session context, labels its result as a controller render, and separately checks the actual product HTTP response on both hosts.
