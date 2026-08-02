# Novicare scraper

The Novicare catalogue is processed in stages. This first stage discovers the ten body-area product categories and all product detail URLs exposed by those category pages.

## Discover categories

```bash
php artisan novicare:categories \
  --save=scrapers/novicare/categories.json \
  --request-delay-ms=500 \
  --show-failures
```

Expected source categories:

- Tułów
- Nadgarstek
- Stopa
- Bark
- Kolano
- Łokieć
- Szyja
- Akcesoria
- Poduszki
- Palce

## Controlled product-link smoke run

```bash
php artisan novicare:product-links \
  --categories-from=scrapers/novicare/categories.json \
  --category-limit=2 \
  --page-limit=1 \
  --save=scrapers/novicare/product-links-smoke.json \
  --request-delay-ms=500 \
  --show-failures
```

## Full product-link discovery

```bash
php artisan novicare:product-links \
  --categories-from=scrapers/novicare/categories.json \
  --save=scrapers/novicare/product-links.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

The result contains normalized canonical URLs, stable SHA-256 external IDs, category paths, detected product codes when present, visited pages and failed URLs. The command is read-only and does not create database products.

## Crawl product data

Start with a small smoke run:

```bash
php artisan novicare:crawl-product-data \
  --from=scrapers/novicare/product-links.json \
  --limit=3 \
  --save=scrapers/novicare/product-data-smoke.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

The product-data crawler extracts:

- canonical product identity and model code;
- source category paths;
- description and indications;
- the complete size table;
- one stable variant candidate for every source size;
- main, product-detail and fitting images;
- related product links;
- SEO metadata and source diagnostics.

Novicare pages do not expose retail prices, stock quantities, EAN values or combination-level SKUs. Those fields therefore remain `null` or `unknown`; the crawler does not invent commercial data.

After checking the smoke JSON, crawl the complete discovered catalogue:

```bash
php artisan novicare:crawl-product-data \
  --from=scrapers/novicare/product-links.json \
  --save=scrapers/novicare/product-data.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

Use `--limit` and `--offset` for resumable batches. Use `--insecure` only when the runtime cannot verify Novicare's TLS certificate chain.

## Import products

Validate the full product-data file without database writes or image downloads:

```bash
php artisan novicare:import \
  --from=scrapers/novicare/product-data.json \
  --dry-run
```

Run a controlled database smoke import:

```bash
php artisan novicare:import \
  --from=scrapers/novicare/product-data.json \
  --status=draft \
  --limit=3 \
  --image-limit=10 \
  --image-timeout=30 \
  --image-attempts=5 \
  --image-retry-delay-ms=5000 \
  --image-request-delay-ms=250 \
  --show-failures
```

The importer:

- owns products through `external_source=novicare` and the crawler's stable external product ID;
- creates one product variant per explicit source size;
- creates one colour variant per Novicare model/colour option when a colour table exists;
- creates one default variant without an invented `UNI` size when the source exposes no variant table;
- preserves null prices because Novicare does not publish retail prices;
- assigns PLN, medical-device VAT 8% and an in-stock fallback unless the source explicitly states otherwise;
- synchronizes category, product and variant attributes idempotently;
- localizes product images and preserves their source URLs;
- archives variants removed from later source data instead of deleting historical rows.

Use `--limit` and `--offset` for resumable batches. Use `--no-images` when testing database mapping only. An image limit of `0` means no per-product limit.

## Full catalogue runtime

`novicare:full-catalogue` runs category/product-link discovery when requested, then crawls and imports the catalogue in resumable batches. Each batch is saved under the configured runtime directory before its database import starts.

The runtime writes:

- `manifest.json` with the next offset and per-batch status;
- `product-data/batch-*.json` with reusable crawl results;
- `audit.json` after a complete database-import run;
- a JSONL operational log under `storage/logs/novicare`.

A runtime locks its source hash, batch size, mode, product status, image mode, image limit and TLS mode. Keep those options unchanged when resuming. Network timeout, attempts and delays may be increased for a retry.

### Controlled first batch

Use a new runtime directory for every independent catalogue execution:

```bash
php artisan novicare:full-catalogue \
  --from=scrapers/novicare/product-links.json \
  --runtime-dir=scrapers/novicare/runtime \
  --refresh-discovery \
  --batch-size=5 \
  --max-batches=1 \
  --status=draft \
  --image-limit=10 \
  --timeout=120 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=1000 \
  --image-timeout=120 \
  --image-attempts=5 \
  --image-retry-delay-ms=5000 \
  --image-request-delay-ms=250 \
  --show-failures \
  --reset
```

Review:

```bash
cat storage/app/scrapers/novicare/runtime/manifest.json
```

### Resume the remaining catalogue

Do not use `--refresh-discovery` or `--reset` while resuming:

```bash
php artisan novicare:full-catalogue \
  --from=scrapers/novicare/product-links.json \
  --runtime-dir=scrapers/novicare/runtime \
  --batch-size=5 \
  --status=draft \
  --image-limit=10 \
  --timeout=120 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=1000 \
  --image-timeout=120 \
  --image-attempts=5 \
  --image-retry-delay-ms=5000 \
  --image-request-delay-ms=250 \
  --show-failures \
  --resume
```

When a crawl or import batch fails without `--continue-on-failure`, its starting offset is retained. A batch whose crawl completed but import failed reuses its saved JSON on the next `--resume` run instead of requesting Novicare again.

### Crawl-only and dry-run modes

Persist source batches without database imports:

```bash
php artisan novicare:full-catalogue \
  --from=scrapers/novicare/product-links.json \
  --runtime-dir=scrapers/novicare/runtime-crawl-only \
  --batch-size=10 \
  --crawl-only \
  --reset
```

`--dry-run` also skips database writes and image downloads. Neither mode runs the database audit.

### Final audit

A completed database-import runtime automatically writes `audit.json`. The audit checks:

- imported Novicare product ownership against all saved batch products;
- expected versus database variant coverage;
- expected image coverage under the configured image limit;
- products without variants;
- products without images when image import is enabled;
- duplicate Novicare external IDs.

The command returns a failure status when the final audit does not pass.

### Production execution

```bash
cd /var/www/konji-shop
mkdir -p storage/logs/novicare-execution
set -o pipefail

docker compose -f docker-compose.prod.yml exec -T app \
  php artisan novicare:full-catalogue \
  --from=scrapers/novicare/product-links.json \
  --runtime-dir=scrapers/novicare/runtime-production \
  --refresh-discovery \
  --batch-size=5 \
  --max-batches=1 \
  --status=draft \
  --image-limit=10 \
  --timeout=120 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=1000 \
  --image-timeout=120 \
  --image-attempts=5 \
  --image-retry-delay-ms=5000 \
  --image-request-delay-ms=250 \
  --show-failures \
  --reset \
  2>&1 | tee storage/logs/novicare-execution/first-batch.log

STATUS=${PIPESTATUS[0]}
echo "Novicare first-batch status: ${STATUS}"
```

After reviewing the first batch, run the same configuration with `--resume`, without `--refresh-discovery`, `--max-batches` or `--reset`.
