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
