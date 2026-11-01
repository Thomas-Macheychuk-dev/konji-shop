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

## Rebuild

```bash
python3 scripts/seo/legacy-category-redirects-20261101/build.py \
  --inventory storage/app/private/scrapers/seo/ortezka/matching/legacy-inventory.csv
```

Without `--inventory` it regenerates from the committed `product-sources.json`. After a change, update `EXPECTED_SHA`/`EXPECTED_RULES` in `docker/nginx/verify-legacy-fallback-map.sh` from the printed summary and rerun `nginx-check.sh`.
