# Zamst catalogue crawler and scraper

## Scope of this patch

This is the read-only discovery/scraping stage for `https://zamst.com.pl/sklep/`.

It does **not** create or update Konji products, variants, categories, images, prices, or any other database records. Importing is deliberately deferred until the live crawl output has been reviewed locally.

The pipeline is:

```text
zamst:categories
        ↓
zamst:product-links
        ↓
zamst:crawl-product-data
        ↓
JSON review
        ↓
future local importer patch
        ↓
future production run
```

## Source structure covered

The scraper understands the current Zamst WordPress/WooCommerce catalogue structure:

- `/sklep/` catalogue sections in `ul.category-list`;
- `/kategoria-produktu/.../` category hierarchy;
- `/produkt/{slug}/` product detail URLs;
- WooCommerce product IDs such as `id="product-2164"` and `data-product_id`;
- variable-product `data-product_variations` JSON;
- variation attributes such as `attribute_pa_rozmiar`;
- WooCommerce product gallery images and product-content images;
- visible product price plus Product JSON-LD fallback;
- product category links from `.posted_in`;
- description tab content;
- PDF/document links and YouTube/Vimeo links.

A select option is **not** treated as a sellable variant by itself. Variant candidates come only from concrete WooCommerce `data-product_variations` records. This matters when a size is present in the dropdown but WooCommerce does not expose a real variation for it.

## 1. Discover categories

Smoke / normal discovery:

```bash
php artisan zamst:categories \
  --save=scrapers/zamst/categories.json \
  --timeout=30 \
  --attempts=3 \
  --retry-delay-ms=2000 \
  --request-delay-ms=500 \
  --show-failures
```

Output:

```text
storage/app/scrapers/zamst/categories.json
```

Review at least:

- `category_urls`;
- `top_categories`;
- category `path` values;
- `is_catalogue_section`;
- `product_count` where available;
- `failed_urls`.

## 2. Discover product links

Start with a bounded smoke crawl that enriches links using only a few category pages:

```bash
php artisan zamst:product-links \
  --categories-from=scrapers/zamst/categories.json \
  --category-limit=3 \
  --page-limit=2 \
  --save=scrapers/zamst/product-links-smoke.json \
  --timeout=30 \
  --attempts=3 \
  --retry-delay-ms=2000 \
  --request-delay-ms=500 \
  --show-failures
```

If the smoke result is correct, discover the complete link set:

```bash
php artisan zamst:product-links \
  --categories-from=scrapers/zamst/categories.json \
  --save=scrapers/zamst/product-links.json \
  --timeout=30 \
  --attempts=3 \
  --retry-delay-ms=2000 \
  --request-delay-ms=500 \
  --show-failures
```

The link dataset deduplicates products that occur in multiple sport/body-part categories and preserves all discovered category contexts on each product.

## 3. Scrape product data

First inspect three products only:

```bash
php artisan zamst:crawl-product-data \
  --from=scrapers/zamst/product-links.json \
  --limit=3 \
  --save=scrapers/zamst/product-data-smoke.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=3000 \
  --request-delay-ms=750 \
  --show-failures
```

You can also inspect one known page directly:

```bash
php artisan zamst:crawl-product-data \
  --url=https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/ \
  --save=scrapers/zamst/jk-2.json \
  --request-delay-ms=0 \
  --show-failures
```

If smoke output is correct, crawl the complete discovered catalogue:

```bash
php artisan zamst:crawl-product-data \
  --from=scrapers/zamst/product-links.json \
  --save=scrapers/zamst/product-data.json \
  --timeout=30 \
  --attempts=5 \
  --retry-delay-ms=3000 \
  --request-delay-ms=750 \
  --show-failures
```

Use `--offset` and `--limit` for repeatable batches if the source starts rate-limiting.

## Product-data review gate

Before an importer is built, audit the JSON for:

```text
product_count
failed_urls
warnings
external_product_id
name
price_gross_amount
currency
availability
categories / source_category_paths
attributes
variant_candidates
images / gallery_images / content_images
downloads
videos
```

For variable products, compare the count and IDs in `variant_candidates` against WooCommerce. Do not infer missing variants from select dropdown values.

No production crawl/import should be run from this patch. The next stage is a separate local importer only after the discovery and product-data JSON have been accepted.
