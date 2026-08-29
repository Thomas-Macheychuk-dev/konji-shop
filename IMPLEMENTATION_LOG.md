# Implementation Log

## 2026-08-27 — Paynow v3 payment initiation hardening

- Migrated Paynow payment creation from deprecated `/v1/payments` to stable `/v3/payments`.
- Implemented Paynow v3 canonical request signatures using `Api-Key`, `Idempotency-Key`, query parameters, and the exact request body.
- Added stable per-payment idempotency keys, request timeouts, strict response validation, safe provider failures, and removal of payment redirect tokens/provider payloads from checkout success logs.
- Added Paynow signature contract coverage against Paynow's published example plus gateway tests for `NEW`, `PENDING`, optional missing initial status, `ERROR`, malformed payloads, HTTP failures, production/sandbox endpoints, stable idempotency, connection failures, and missing credentials.
- Persist initial provider status through `StartPaymentService` when the gateway returns it.

## 2026-08-27 — Payment initialization retry and recovery

- Added a customer-safe retry path for orders that were durably created but whose payment initialization did not complete.
- Reuse the same unpaid local payment row for initialization retries so Paynow receives the same stable idempotency key after transport failures/timeouts and cannot create a duplicate provider payment for the retry.
- Added ownership checks for authenticated customers, checkout-session guests, and guest order tracking sessions, plus request throttling on the retry endpoint.
- Added retry UI to checkout return, account order, and guest order pages only while the order remains eligible for initialization recovery.
- Dispatch the order confirmation event as soon as the order is durably created, so a temporary payment-provider outage does not prevent the customer from receiving the order number.
- Added focused recovery coverage for successful retry, duplicate retry prevention, access isolation, order/payment amount integrity, checkout recovery UI, and provider-initialization failure behavior.

## 2026-08-27 — Paynow webhook lifecycle hardening

- Added explicit Paynow v3 notification handling for `NEW`, `PENDING`, `CONFIRMED`, `REJECTED`, `ERROR`, `EXPIRED`, and `ABANDONED`.
- Scope notification lookup by provider plus Paynow `paymentId`, verify the signed payload, and require `externalId` to match the local order before applying any state transition.
- Use Paynow `modifiedAt` to ignore exact replays and stale out-of-order notifications without generating duplicate payment/order events, and prevent paid/refunded local state from regressing on later failure notifications.
- Map terminal Paynow failures back to a retryable unpaid order while preserving the failed payment attempt; the next customer retry creates a fresh local payment row and therefore a fresh Paynow idempotency key.
- Made payment/order pending/paid/failed transitions idempotent and changed the Paynow notification endpoint to return the empty `200 OK` response expected by the provider.
- Added focused coverage for all Paynow statuses, replay/out-of-order delivery, provider isolation, external ID mismatch, signature failure, empty webhook acknowledgement, and retry after provider-declared failure.

## 2026-08-27 — Paynow provider-backed withdrawal refunds

- Replaced the local-only withdrawal refund action with a Paynow v3 refund workflow backed by a durable `payment_refunds` ledger.
- Use a stable refund idempotency key across ambiguous transport retries, persist Paynow `refundId` and provider status, and create a fresh attempt only after Paynow definitively reports `FAILED` or `CANCELLED`.
- Keep withdrawals in `refund_pending` while Paynow reports `NEW`/`PENDING`; payment/order refund state and the customer refund email are finalized only after Paynow reports `SUCCESSFUL`.
- Added provider-status reconciliation through `paynow:reconcile-refunds`, scheduled every fifteen minutes, including recovery of a provider-successful refund whose local finalization was interrupted.
- Preserve prior withdrawal statuses when a provider refund fails, prevent cumulative refunds from exceeding the captured Paynow payment, and require the source Paynow payment to be locally paid with external status `CONFIRMED`.
- Added admin feedback for pending Paynow refunds plus focused coverage for request signing, status polling, immediate success, pending-to-success reconciliation, terminal provider failure/new attempt behavior, ambiguous connection retry/idempotency, and scheduled reconciliation.

## 2026-08-27 — Durable public product media storage boundary

- Kept the logical Laravel `public` disk stable for existing database rows and supplier importers while allowing production to back that disk with private S3 via `PUBLIC_FILESYSTEM_DRIVER=s3`.
- Added explicit `public-local` and `public-s3` migration endpoints so existing catalogue assets can be copied from the EC2/Docker public volume to S3 without changing product-image disk identifiers or deleting the rollback source.
- Added `shop:migrate-public-media` with dry-run-by-default behavior, streamed writes, post-copy size verification, resumable/idempotent copying, optional legacy description-link rewriting, and a hard requirement for an HTTPS public/CDN URL before database HTML is rewritten.
- Made localized ARmedical/Sigvaris documents, Sigvaris size charts, and Timago inline images use the logical public filesystem URL so the same importer output works locally and behind S3/CloudFront.
- Preserved legacy `/storage/...` URL recognition for already-imported content while allowing the same parsers/audits to recognize future object-storage/CDN URLs.
- Added focused coverage for dry-run/write migration behavior, source retention, description rewriting, HTTPS URL gating, and public URL/path compatibility.

## 2026-08-27 — Private S3 + CloudFront product-media delivery

- Added a dedicated CloudFront product-media distribution backed by the existing private S3 bucket through Origin Access Control with SigV4 signing; the bucket remains fully blocked from public access, CloudFront read permission is limited to `products/*`, and bucket-owner-enforced object ownership is enabled.
- Use AWS managed `CachingOptimized`, HTTPS redirects, compression, HTTP/2+HTTP/3, and the cost-conscious `PriceClass_100` default rather than enabling a global edge footprint before production traffic requires it.
- Added configurable catalogue object `Cache-Control` metadata with a one-day browser TTL and seven-day shared-cache TTL; intentionally avoided `immutable`/one-year browser caching until product object names are content-addressed or versioned.
- Added `shop:check-public-media` for configuration validation and an explicit `--probe` mode that writes one temporary private S3 object, reads it through the configured CDN URL, validates the content, and cleans up the origin object.
- Added Terraform outputs for the CloudFront domain/public media URL plus a guarded cutover runbook covering Terraform deployment, CDN probe, dry-run/copy, real-object verification, description rewrite, live disk switch, and rollback.
- Added focused application and infrastructure coverage for direct-S3 URL rejection, private visibility/cache TTL requirements, successful/failed CDN probes, OAC signing, bucket public-access blocking, and the PriceClass_100 MVP default.

## 2026-08-27 — Storefront database/query cost baseline

- Collapsed product-page cache-version discovery from many independent aggregate queries into one correlated aggregate query, so cached product-page requests no longer pay repeated RDS round trips merely to validate the cache key.
- Replaced depth-by-depth category descendant discovery with one recursive CTE while preserving the active-only subtree semantics used by public category listings.
- Removed the redundant `mainImage` eager-load query from home, category and product-page catalogue reads; when `images` are already loaded, the product now resolves the main image directly from that collection without an extra query.
- Added composite indexes aligned with the actual storefront predicates/orderings for active category trees/navigation, product listing/featured reads, active/default variants and ordered product media.
- Added focused performance regression coverage for one-query cache-version discovery, one-query category subtree resolution, zero-query loaded-image selection and presence of the storefront composite indexes.

## 2026-08-27 — Storefront catalogue/application cache baseline

- Added cache namespaces with event-driven invalidation for public catalogue and navigation data.
- Cached homepage category/featured-product datasets, category result pages, category subtrees, and navigation trees while leaving search, carts, checkout, payment, stock mutations, and admin responses uncached.
- Removed the per-request product cache-version database lookup from the live product-page path; model mutations now invalidate the catalogue namespace immediately, with TTLs as a bounded fallback.
- Cached editable shop configuration as one dataset instead of querying individual settings repeatedly during SEO/legal rendering and application boot.
- Kept the existing local Redis container as the recommended MVP cache store; no managed ElastiCache dependency is introduced.
- Added regression coverage for namespace reuse/invalidation, category subtree cache hits, product invalidation, and shop-configuration cache behavior.

## 2026-08-27 — Laravel production runtime efficiency baseline

- Added a dedicated immutable production PHP/OPcache profile and bounded on-demand PHP-FPM pool.
- Production images now use authoritative Composer autoloading after application sources are present.
- Laravel deployment and long-running worker containers build the framework optimization caches.
- Added a blocking `shop:check-runtime` gate for production cache/OPcache/Redis/logging invariants.
- Reduced idle queue/scheduler CPU work and protected non-expiring Redis queue keys from cache eviction.
- Added low-risk Nginx file/FastCGI runtime tuning without caching personalized or SEO-sensitive HTML.

## 2026-08-27 — AWS production MVP cost baseline

- Preserved the single-EC2/private-RDS topology with no NAT Gateway, ALB, ElastiCache, or OpenSearch requirement and changed the default x86 compute class from `t3.small` to lower-cost `t3a.small`.
- Added a no-additional-charge S3 Gateway VPC endpoint so application S3 traffic has a direct AWS-network route without introducing paid interface endpoints or NAT.
- Made RDS CloudWatch exports configurable, defaulted to the error log only, and Terraform-manages a 14-day retention policy instead of indefinite log storage.
- Tightened S3 lifecycle cost controls to keep noncurrent versions for 30 days, abort incomplete multipart uploads after 7 days, and remove expired delete markers.
- Added optional monthly AWS Budget notifications at 80%, 100%, and forecasted 100% thresholds plus a cost-conscious production tfvars example.
- Added bounded Docker json-file log rotation to protect the EC2 EBS volume from unbounded container logs.
- Documented production scale triggers and the explicit rule that additional managed AWS services are introduced only when metrics justify their recurring cost.

## 2026-08-29 — NeoxMed catalogue crawler and scraper

- Added a crawl/scrape-only NeoxMed supplier integration; no product/database import path is enabled in this patch.
- Discover the seven public NeoxMed catalogue category pages from the WordPress navigation and normalize `www`/query/fragment URL variants to canonical `https://neoxmed.com/.../` URLs.
- Scrape products directly from category-page `h2` sections because NeoxMed does not expose dedicated product-detail URLs; use the published Neox product code as the stable external identity/SKU.
- Deduplicate repeated responsive product blocks and merge the same product code when it appears in multiple NeoxMed categories while preserving all source category paths.
- Extract descriptions, NFZ codes, matching product images and visual size-chart images; leave price/currency/availability unset because the manufacturer catalogue does not publish retail commercial data.
- Preserve visual-only size information as review-required metadata rather than synthesizing variants, and stop early on HTTP 429 after bounded retries/delays.
- Added `neoxmed:categories` and `neoxmed:crawl-product-data`, scraper documentation, command persistence support and focused category/product/crawler regression coverage.

## 2026-08-29 — NeoxMed scraper source-identity and media quality hardening

- Preserve whitespace across HTML line-break/inline markup in NeoxMed product headings so titles such as `Stabilizator nadgarstka i kciuka` and `Kołnierz ortopedyczny typ Schanza` are stored correctly.
- Stop collapsing genuinely distinct NeoxMed products that reuse a base source code: derive stable external IDs/SKUs for `(21)/(24)/(30)` P-30 heights and `Short` wrist-brace models while retaining the original manufacturer code in `source_code`.
- Keep responsive duplicate blocks deduplicated by the derived product identity and propagate only shared NFZ codes across sibling products that reuse the same source code.
- Treat NeoxMed `*_resize` neck-brace assets as product images instead of generic underscore-based size-chart images.
- Added focused regression coverage for reused source codes, P-30 height separation, heading whitespace normalization, shared NFZ metadata, and SZ-01/SZ-02 product-image classification.

## 2026-08-29 - NeoxMed read-only import mapping

- Added `neoxmed:import-map` for the frozen 75-product NeoxMed catalogue.
- Mapping is explicitly `database_writes=false` and `images_downloaded=false`.
- Preserves NeoxMed source identities including SHORT products and qualified P-30 heights.
- Plans one safe draft/out-of-stock placeholder variant per distinct source product; visual/textual size information is retained but never converted into inferred variants.
- Preserves NFZ codes, descriptions, product images and size-chart images.
- Canonicalizes legacy NeoxMed-owned HTTP media URLs to HTTPS during mapping so valid frozen catalogue images are not discarded; non-NeoxMed HTTP media remains rejected.
- Import-map summaries report source and successfully mapped media counts separately to prevent source-count checks from masking mapping loss.
- Does not invent selling prices, VAT or source availability; price and VAT remain blocking review prerequisites.
- Added a read-only database audit for source-scoped external-ID overlaps, global slug/SKU collisions and exact category slug matches.
- Added frozen-count gates for 75 products, 76 product images and 80 size-chart images.
