# Legacy category and product fallback redirects — 1 November 2026

## What this adds

A second, independently switched nginx redirect map for the old ortezka.pl URLs that the exact SEO-12 product map does not cover. It is generated from the reviewed category decisions in `scripts/seo/legacy-category-map-20261101/decisions.csv` (PR #132).

| Legacy URLs | Rules | Response |
|---|---|---|
| Live `-cat-N` category pages | 232 | 301 to the decided Konji category |
| Empty seasonal `-cat-N` gift pages | 2 | 410 |
| Unmatched live `-id-N` products (8,640 IDs, all known paths) | 14,402 | 301 to the category of the product's old breadcrumb, or of a name rule |
| **Total** | **14,636** | |

279 of the 8,920 unmatched live product IDs resolve to nothing and keep returning 404. They are listed in `resources/seo/ortezka/review/legacy-category-redirects-20261101/unresolved-products.csv` for later manual mapping. Every generated rule is listed with its reason in `fallback-redirects.csv` in the same folder.

## How a product is resolved

1. **Old breadcrumb (7,776 IDs).** The deepest breadcrumb level that is a live legacy category, resolved through the category decisions (including `parent`).
2. **Name rules (864 IDs).** 1,143 unmatched live products had only "Strona główna" as breadcrumb. `keyword-rules.json` holds ordered, first-match rules on accent-folded word prefixes of the old product name, e.g. CPAP masks → `tlenoterapia` before generic masks → `srodki-ochrony-osobistej`. Rules were tuned against samples of every rule's hits; ambiguous names (e.g. "Podpórka pod plecy") deliberately match nothing.
3. **Nothing matched (279 IDs).** No rule; the URL remains a 404 rather than a soft-404 homepage redirect.

A product never inherits a 410 from a gone category. Keys are excluded when they already appear in the SEO-12 product map or staging candidate map, or as a Laravel legacy placeholder path (`config/legacy-product-placeholders.php`). Paths containing characters that could break a quoted nginx key are skipped (none currently).

## Runtime wiring

- `docker/nginx/generated/legacy-seo-category-fallback-map.conf` defines `map $uri $legacy_seo_fallback_target`. The image installs it as `02-legacy-seo-category-fallback-map.conf`. nginx builds map hashes in parse order, so it must load after `00-legacy-seo-product-map.conf`, which sets `map_hash_bucket_size 256`; with the default size the configuration fails.
- `map-hash.conf` raises `map_hash_max_size` from 8,192 to 65,536. At 32,768 nginx still warned that it could not build an optimal hash for ~15,700 keys.
- `legacy-seo/available/30-fallback-redirects-enabled.conf` returns 410 for `"410"` values, otherwise `301 $legacy_seo_redirect_origin$legacy_seo_fallback_target`. Staging redirects stay on staging; apex and www go to the apex.
- `production.conf` includes `runtime/30-fallback-redirects.conf` immediately after every `runtime/10-product-redirects.conf` (five server blocks). nginx runs these `return`s in order, so an exact product redirect always wins.
- `start-production.sh` runs `verify-legacy-fallback-map` (map SHA-256 and rule count, like the SEO-12 gate) and materializes the include only when `LEGACY_SEO_FALLBACK_REDIRECTS_ENABLED` is true. It defaults to false in `docker-compose.prod.yml` and `.env.production.example`.

The SEO-12 map, its two gates, its 1,070 rules and the staging candidate map are unchanged.

## Validation

- `test_build.py` (CI): reproducible map and review files, rule counts, no overlap with exact maps or placeholders, every target an active category, only the two gift pages return 410, the gate pins the committed map, and the nginx/Docker/compose wiring.
- `nginx-check.sh` (local, needs Docker): loads all three maps, both gates and both includes into `nginx:1.27-alpine`, runs `nginx -t` and checks responses: exact product still wins, category and product fallbacks on staging/apex/www, 410, and pass-through for normal and near-miss paths. Passed with no warnings on 1 November.
- Existing PHP suites for Seo, Security, Infrastructure and Deployment pass (175 tests).

## Rollout

1. Merge and deploy as usual (`scripts/deploy/aws-production.sh`). With the flag off nothing changes, but startup now also requires `LEGACY_FALLBACK_MAP_GATE=PASS`.
2. On the server, set `LEGACY_SEO_FALLBACK_REDIRECTS_ENABLED=true` in the environment used by `docker-compose.prod.yml` and recreate `web`. The single web container serves both staging and apex server blocks, as with `LEGACY_SEO_REDIRECTS_ENABLED`; apex traffic only reaches it after DNS cutover.
3. Spot-check on staging: `/ortezy-i-stabilizatory-cat-124` → 301 `/categories/ortezy-i-stabilizatory`, `/na-dzien-babci-i-dziadka-cat-243` → 410, an unmatched product from `fallback-redirects.csv` → 301 to its category, and a mapped SEO-12 product still → its product.
4. Disable by setting the flag back to false and recreating `web`.

## Legacy IDs, aliases and `/index.php` — 2 November

The launch check sent 200 random URLs from the live shop's sitemap to the new server as `ortezka.pl`, and 56 returned 404. Two gaps explained them:

- PrestaShop serves a product under **any** slug ending in `-id-N` (and a category under any `-cat-N`), but the maps above match exact paths only. `/2383-orteza-nadgarstka-miekka-z-dociagiem-id-9554` was a 404 even though `/oppo-2383-…-id-9554` was mapped.
- `/index.php`, with or without `?id_product=`/`?id_category=`/`?controller=`, rendered the homepage with `canonical=/index.php`.

The same generated file now also defines ID maps:

| Map | Rules | Source |
|---|---|---|
| Legacy product ID → target | 9,304 | the decision already made for that ID's known paths (SEO-12 product map, or the category fallback above) |
| Legacy product ID → category | 197 | where the live shop redirects products it has already removed, crawled into `live-redirects-20261102.tsv` |
| Legacy category ID → category or 410 | 234 | the category decisions |
| **Total** | **9,735** | |

The legacy ID is taken from a single-segment `/<slug>-id-N` or `/<slug>-cat-N` path, or from `/index.php?id_product=N` / `?id_category=N`. New URLs are never single-segment with those suffixes, so app routes cannot match. Any other `/index.php` request gets a 301 to `/`. All of this sits in the existing `30-fallback-redirects` include, after the exact path rules, so exact paths keep priority, and it is switched by the same `LEGACY_SEO_FALLBACK_REDIRECTS_ENABLED` flag. The gate now also pins `EXPECTED_ID_RULES`.

`live-redirects-20261102.tsv` covers the 1,850 product IDs in the live sitemap that had no mapping, requested one at a time without following redirects:

- **404 (1,356):** already gone on the live shop; they stay 404.
- **301 to a category (209):** 197 are mapped to that category's decision. The other 12 point to the root category `strona-glowna-cat-2`, which would be a homepage soft-404, so they stay 404.
- **301 to the same ID under another slug (13):** that ID has no decision either, so they stay 404.
- **200 (272):** still-live products. 271 are already in `unresolved-products.csv`; the extra one is `wozek-inwalidzki-o-napedzie-elektrycznym-q5-id-2256`.

## Hand decisions for unresolved products — 2 November

`manual-products.csv` decides the 279 products that neither a breadcrumb nor a name rule resolved. Each was compared with the full new catalogue (all 14,619 product pages exported from staging) and the category list:

| Decision | Products | Target |
|---|---|---|
| Same product in the new shop (same model or code; wording or variant differences only) | 34 | `/products/<slug>` |
| No same product, but a specific category sells that kind of item | 156 | `/categories/<slug>` |
| Nothing fits (Heine/veterinary diagnostics, Aseptica purifiers, Flovac suction liners, cupping and ear-candle sets, cosmetic gadgets …) | 89 | stays 404, listed in `unresolved-products.csv` |

Product matches win over categories only when the item is clearly the same, because a wrong product redirect is worse than a category one. Promotional categories ("Nowości", "Okazje") were never used. The `matched` column holds the new product name or category path for review. Every target returned 200 on staging on 2 November. The calls worth a second look are the Reha Fund PP-11 spacer (matched to "Rozpórka nóg długa" by inference from PP-12 "krótka") and the generic disposable spatulas (matched to the wooden laryngological spatula).

Hand decisions are the only fallback rules whose target can be a product. The gate's path-rule count now accepts `/products/` targets.

## Search Console traffic pass — 2 November

The Search Console Pages report (30 September – 31 October, 1,000 URLs) listed 843 old product URLs. 751 of them went to a category even though many of those products are in the new shop; product → category redirects are usually treated as soft 404s. Each of the 759 that did not land on a product was checked against the full new catalogue, by model code, brand and product line:

| Decision | Products |
|---|---|
| Same product in the new shop → `/products/<slug>` (added to `manual-products.csv`) | 223 |
| Had no redirect at all; a fitting category exists → `/categories/<slug>` | 6 |
| Unchanged: product not sold in the new shop (e.g. Össur, Ottobock, Thuasne, some medi lines), different model code, or too ambiguous | 530 |

Four proposed matches were dropped as too uncertain (2550, 13695, 5686, 5835). Wigs and clothing that the new shop splits by colour go to one colour of the same model when the old page named none. Of the 843 URLs, 307 now land on a product (84 before), 534 on a category, and 2 stay 404 (Kinoki patches, Heine skiaskop).

`manual-paths.csv` maps the 42 old `/brand/N-name` pages with traffic to the category that best covers what the brand sold (medical clothing, orthoses, compression, medical shoes, wheelchairs …); before this they returned 404. These are the only fallback rules keyed by a full path rather than a legacy ID. Every target returned 200 on staging on 2 November.

## Rebuild

```bash
python3 scripts/seo/legacy-category-redirects-20261101/build.py \
  --inventory storage/app/private/scrapers/seo/ortezka/matching/legacy-inventory.csv
```

Without `--inventory` it regenerates from the committed `product-sources.json`. After a change, update `EXPECTED_SHA`/`EXPECTED_RULES`/`EXPECTED_ID_RULES` in `docker/nginx/verify-legacy-fallback-map.sh` from the printed summary and rerun `nginx-check.sh`.
