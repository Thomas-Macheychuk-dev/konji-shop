# Microlife scraper

The Microlife source has two separate Polish catalogue branches:

- `Produkty` for consumer and home-use medical devices;
- `Produkty profesjonalne` for WatchBP devices, professional cuffs and equipment.

The discovery commands process both branches by default and preserve `catalogue_type` as either `consumer` or `professional` in every category and product-link record.

Software, support content, product-finder pages, clinical validations and research articles are excluded. Legacy `/professional-products/...` links are normalized to canonical `/produkty-profesjonalne/...` URLs before deduplication.

Microlife's `Opieka nad dzieckiem` page mixes direct product cards with the nested `Akcesoria` category. Discovery therefore keeps the parent page as a product-scraping category, excludes the direct BC product pages from category records, and lets product-link discovery collect those products from the parent page.

## Discover all categories

```bash
php artisan microlife:categories \
  --save=scrapers/microlife/categories.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

The result contains:

- both catalogue roots;
- consumer parent and leaf categories;
- professional product categories;
- stable external category IDs;
- category paths and parent relationships;
- canonical URLs;
- visited and failed URLs.

Use `--catalogue=consumer` or `--catalogue=professional` to run only one branch. Explicit `--url` options override the catalogue selection.

## Controlled product-link smoke run

```bash
php artisan microlife:product-links \
  --categories-from=scrapers/microlife/categories.json \
  --category-limit=2 \
  --save=scrapers/microlife/product-links-smoke.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

## Full product-link discovery

```bash
php artisan microlife:product-links \
  --categories-from=scrapers/microlife/categories.json \
  --save=scrapers/microlife/product-links.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

The product-link result contains normalized canonical URLs, stable SHA-256 external IDs, catalogue type, category identity, category paths, visited pages and failed URLs. The command is read-only and does not create database products.

Filter a saved category discovery to one branch when required:

```bash
php artisan microlife:product-links \
  --categories-from=scrapers/microlife/categories.json \
  --catalogue=professional \
  --save=scrapers/microlife/professional-product-links.json \
  --request-delay-ms=500 \
  --show-failures
```

Use `--insecure` only when the runtime cannot verify Microlife's TLS certificate chain.

## Crawl product data

Run a controlled smoke crawl before processing the full catalogue:

```bash
php artisan microlife:crawl-product-data \
  --from=scrapers/microlife/product-links.json \
  --limit=3 \
  --save=scrapers/microlife/product-data-smoke.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

Run the full consumer and professional catalogue:

```bash
php artisan microlife:crawl-product-data \
  --from=scrapers/microlife/product-links.json \
  --save=scrapers/microlife/product-data.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=500 \
  --show-failures
```

The dataset preserves the catalogue branch and extracts:

- product model, headline and descriptions;
- feature blocks and specification items;
- filter-safe labelled attributes;
- gallery and feature images;
- manuals, software downloads and product videos;
- related product links and external `Kup teraz` URLs;
- medical-device status;
- explicit professional cuff size variants when at least two sizes and measurements are stated by Microlife.

Use `--offset` and `--limit` for repeatable batches. Explicit `--url` options can be used to inspect one or more product pages without reading the product-link JSON.

## Import products

Validate the complete dataset without writing to the database or downloading assets:

```bash
php artisan microlife:import \
  --from=scrapers/microlife/product-data.json \
  --dry-run
```

Run a controlled three-product import without remote assets:

```bash
php artisan microlife:import \
  --from=scrapers/microlife/product-data.json \
  --status=draft \
  --limit=3 \
  --no-images \
  --no-documents \
  --show-failures
```

Run the complete import with localized product images and PDF/ZIP downloads:

```bash
php artisan microlife:import \
  --from=scrapers/microlife/product-data.json \
  --status=draft \
  --image-limit=50 \
  --asset-timeout=30 \
  --asset-attempts=5 \
  --asset-retry-delay-ms=5000 \
  --asset-request-delay-ms=250 \
  --show-failures
```

The importer:

- writes products with `external_source=microlife` and stable external IDs;
- creates the `Microlife > Produkty` and `Microlife > Produkty profesjonalne` category branches;
- imports filter-safe specifications and generated producer/catalogue/medical-device attributes;
- creates one default variant when the source has no explicit options;
- creates explicit professional cuff size variants when supplied by the crawler;
- assigns 8% VAT to medical devices and 23% otherwise;
- keeps prices empty and uses PLN until commercial prices are supplied;
- downloads product images and preserves their source URLs;
- localizes Microlife PDF/ZIP downloads under the public product asset directory;
- keeps software and unsupported downloads as source links in the product description;
- archives variants that disappear from a later source run;
- supports repeatable batches through `--offset` and `--limit`.

Use `--image-limit=0` for no per-product image limit. Use `--no-images` or `--no-documents` for retry and diagnostic runs. `--insecure` affects document downloads only and should be used only when the runtime cannot verify Microlife's TLS certificate chain.

## Resumable full-catalogue runtime

`microlife:full-catalogue` combines product crawling and importing in persisted batches. Every run writes:

- an atomic manifest under the configured runtime directory;
- one product-data JSON file per batch;
- a JSON Lines operational log under `storage/logs/microlife`;
- a final database audit under the runtime directory.

Start with one controlled batch:

```bash
php artisan microlife:full-catalogue \
  --from=scrapers/microlife/product-links.json \
  --runtime-dir=scrapers/microlife/runtime \
  --batch-size=5 \
  --max-batches=1 \
  --status=draft \
  --image-limit=0 \
  --asset-timeout=60 \
  --asset-attempts=5 \
  --asset-retry-delay-ms=5000 \
  --asset-request-delay-ms=250 \
  --timeout=60 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=750 \
  --show-failures \
  --reset
```

Inspect `storage/app/scrapers/microlife/runtime/manifest.json`, the saved batch data and the imported records. Resume the remaining catalogue with the same immutable runtime settings:

```bash
php artisan microlife:full-catalogue \
  --from=scrapers/microlife/product-links.json \
  --runtime-dir=scrapers/microlife/runtime \
  --batch-size=5 \
  --status=draft \
  --image-limit=0 \
  --asset-timeout=60 \
  --asset-attempts=5 \
  --asset-retry-delay-ms=5000 \
  --asset-request-delay-ms=250 \
  --timeout=60 \
  --attempts=5 \
  --retry-delay-ms=5000 \
  --request-delay-ms=750 \
  --show-failures \
  --resume
```

Do not combine `--resume` with `--reset` or `--refresh-discovery`. The source product-link file, batch size, execution mode, product status, image mode, document mode, image limit and TLS mode must remain unchanged while resuming a manifest. Timeout, retry and delay values may be increased between attempts.

Use `--refresh-discovery` on a new runtime to regenerate both category and product-link discovery before batching:

```bash
php artisan microlife:full-catalogue \
  --refresh-discovery \
  --categories-from=scrapers/microlife/categories.json \
  --from=scrapers/microlife/product-links.json \
  --runtime-dir=scrapers/microlife/runtime \
  --batch-size=5 \
  --status=draft \
  --image-limit=0 \
  --show-failures \
  --reset
```

Use `--dry-run` to crawl and persist batches without database or asset writes. Use `--crawl-only` to build the complete runtime product-data set without importing. `--no-images` and `--no-documents` are available for diagnostics and controlled retries.

A successful imported runtime finishes with a passing audit for product, variant, image and category coverage, and zero duplicate product or variant identities. Import warnings are treated as runtime failures so missing or rejected assets cannot be silently accepted.
