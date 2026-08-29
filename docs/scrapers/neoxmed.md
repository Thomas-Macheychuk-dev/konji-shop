# NeoxMed catalogue crawler and scraper

Source: `https://neoxmed.com/`

This first NeoxMed patch is intentionally **crawl/scrape only**. It does not create or update Konji Shop products.

## Live catalogue shape

NeoxMed is a WordPress catalogue rather than a conventional e-commerce shop. The public product navigation exposes seven catalogue pages:

- Ortezy kończyn dolnych
- Ortezy kończyn górnych
- Ortezy tułowia
- Ortezy szyi
- Ortezy barku
- Temblaki
- Opaski elastyczne

Products are published as repeated `h2` product sections on those category pages, not as individual product-detail URLs. The scraper therefore uses the Neox product code (for example `B-01`, `T-03`, `EK-01`) as the external product identity and deduplicates responsive duplicate blocks and cross-category duplicates.

The manufacturer pages do not expose retail prices. The crawl output intentionally keeps price/currency/availability null rather than inventing commercial data.

Size information is frequently published as images. The scraper preserves those images separately in `size_chart_images` and emits a review warning instead of inventing structured variants.

## 1. Discover catalogue pages

```bash
docker compose exec app php artisan neoxmed:categories \
  --save=scrapers/neoxmed/categories.json \
  --show-failures
```

Expected current category count: **7**.

For a zero-delay local/test run:

```bash
docker compose exec app php artisan neoxmed:categories \
  --request-delay-ms=0 \
  --retry-delay-ms=0 \
  --json
```

## 2. Smoke scrape

Use the saved category discovery and select only a few deduplicated products:

```bash
docker compose exec app php artisan neoxmed:crawl-product-data \
  --from=scrapers/neoxmed/categories.json \
  --limit=5 \
  --save=scrapers/neoxmed/product-data-smoke.json \
  --show-failures
```

Inspect:

- SKU / `external_product_id`
- product name
- source category paths
- description lines
- NFZ codes where published
- product images
- size-chart images
- warnings

## 3. Full crawl

```bash
docker compose exec app php artisan neoxmed:crawl-product-data \
  --from=scrapers/neoxmed/categories.json \
  --save=scrapers/neoxmed/product-data.json \
  --show-failures
```

Or discover and crawl in one command:

```bash
docker compose exec app php artisan neoxmed:crawl-product-data \
  --discover \
  --save=scrapers/neoxmed/product-data.json \
  --show-failures
```

## Output contract

Each product includes at least:

- `source = neoxmed`
- source category URL and category paths
- manufacturer code as `source_code`; normally it is also the `external_product_id`/`sku`, while headings that reuse a base code receive a deterministic qualifier such as `P-30-21` or `N-03-SHORT`
- normalized product name and slug
- Neox brand metadata
- description text/lines/HTML
- NFZ codes where present
- product images
- visual size-chart images
- `is_medical_device = true`
- null price/currency/availability because the manufacturer catalogue does not publish retail commercial data
- warnings for visual-only size information

No database writes are performed by either NeoxMed command.

## Crawl etiquette and failure handling

Defaults are conservative:

- 750 ms delay between product-category requests
- 500 ms delay for category discovery
- 20 second request timeout
- up to 3 attempts for HTTP 429 and 5xx responses
- 1500 ms retry delay

If NeoxMed starts returning HTTP 429, the full crawl stops early rather than continuing to hammer the supplier site.

## Next phase

Only after the live crawl output is reviewed should a separate patch add mapping/import behavior. That future patch must resolve at minimum:

- price source
- VAT policy
- structured size/variant mapping from visual size charts
- category mapping into Konji Shop
- image/download policy
- duplicate/reused manufacturer codes, preserving cross-category duplicates as one product while keeping distinct source headings such as P-30 heights and Short models separate
