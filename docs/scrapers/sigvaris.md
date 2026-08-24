# sklep-sigvaris.com catalogue crawler and scraper

## Scope

This is the read-only discovery/scraping stage for `https://www.sklep-sigvaris.com/`.

It performs **no Konji database writes and no asset downloads**. The pipeline is deliberately staged:

```text
sigvaris:categories
      ↓
sigvaris:product-links
      ↓
sigvaris:crawl-product-data
      ↓
sigvaris:combinations
      ↓
JSON combination review
      ↓
future import mapping
      ↓
future local import
      ↓
future production import
```

## Source platform

The store is PrestaShop. Current live characteristics that the scraper is designed around include:

- category URLs such as `/17-wyroby-kompresyjne`;
- paginated category listings using `?page=N`;
- 12 products per normal listing page;
- product URLs such as `/7881-94755-...html`, where the first numeric segment is the product ID and the second is the currently rendered combination ID;
- product selectors such as colour, length, size and toe type;
- gallery images, product descriptions, technical features, manufacturer/importer information and documents;
- product-level medical-device messaging;
- price-history data which can expose both tax-included and tax-excluded values.

## Important PrestaShop variant boundary

The first product-data crawl records all selectable attribute options and the **currently rendered concrete combination**. It intentionally does not manufacture a Cartesian product of option values and pretend those are real variants.

`sigvaris:combinations` validates variants against PrestaShop itself. It reproduces the storefront `ajax=1&action=refresh` request, walks from one returned selector state to neighbouring selector states, and records only the `id_product_attribute` values returned by PrestaShop. Invalid selector combinations that PrestaShop normalises to an existing combination therefore do not create fake Konji variants.

The command has a per-product refresh-request safety limit and reports a product as truncated instead of silently claiming enumeration is complete.

## Commands

### Categories

```bash
php artisan sigvaris:categories \
  --save=scrapers/sigvaris/categories.json \
  --show-failures
```

### Product links

```bash
php artisan sigvaris:product-links \
  --categories-from=scrapers/sigvaris/categories.json \
  --save=scrapers/sigvaris/product-links.json \
  --show-failures
```

### Product data smoke crawl

```bash
php artisan sigvaris:crawl-product-data \
  --from=scrapers/sigvaris/product-links.json \
  --limit=5 \
  --save=scrapers/sigvaris/product-data-smoke.json \
  --show-failures
```

The crawl should be reviewed before a full product-data run or any importer work.


### Concrete PrestaShop combination smoke enumeration

Run this after the 5-product product-data smoke crawl:

```bash
php artisan sigvaris:combinations \
  --from=scrapers/sigvaris/product-data-smoke.json \
  --limit=5 \
  --max-requests-per-product=1000 \
  --save=scrapers/sigvaris/combinations-smoke.json \
  --show-failures
```

A product is not ready for mapping if `truncated=true`, if any refresh requests failed, or if a selector-bearing product has no concrete combinations. Do not run the 226-product detailed crawl/import stage until representative combination enumeration has been reviewed.
