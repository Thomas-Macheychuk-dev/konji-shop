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

## Import mapping dry-run

After the corrected 77-product catalogue crawl has been reviewed, build a read-only import map:

```bash
php artisan neoxmed:import-map \
  --from=scrapers/neoxmed/product-data.json \
  --save=scrapers/neoxmed/import-map.json \
  --show-review \
  --show-database
```

The command deliberately does **not** create or update products, variants, categories, media or pricing. It also does not download images.

Frozen catalogue expectations:

- 77 distinct NeoxMed products;
- 77 normal product-image associations; K-01 has no normal source product image and remains a blocking media review item;
- 81 size-chart image associations;
- one safe draft placeholder variant per distinct source product;
- globally prefixed planned variant SKUs such as `NEOX-B-01`, `NEOX-N-03-SHORT` and `NEOX-P-30-21`;
- source sizing remains informational and is not converted into inferred variants;
- source NFZ codes are preserved as metadata;
- product status remains `draft` and planned stock remains `out_of_stock`;
- catalogue price, VAT and availability are not invented.
- legacy `http://neoxmed.com` / `http://www.neoxmed.com` media URLs are canonicalized to the same NeoxMed-owned HTTPS path during mapping; third-party/non-NeoxMed HTTP URLs are rejected.

The scraper also expands comma-separated source-code headings such as `K-01, K-02 Stabilizator stawu kolanowego` into distinct source products instead of silently dropping the heading.

The mapping performs a read-only current-database audit for:

- existing NeoxMed products;
- cross-source external product ID overlaps (informational because product identity is source-scoped);
- product slug collisions;
- globally unique planned variant SKU collisions;
- exact existing category slug matches and unmatched source category slugs.

Price and VAT remain explicit blockers. A structurally valid map can therefore report `PASS WITH REVIEW`, but `ready_for_database_write` remains false until approved commercial pricing and VAT are supplied in a later pricing/preflight stage.

## Deterministic Konji taxonomy mapping

The import map does not attach NeoxMed source category slugs directly to Konji categories. Source category paths are preserved separately as provenance, while target categories are resolved by NeoxMed manufacturer-code family into the generic Konji `Produkty ortopedyczne` taxonomy.

The resolver uses category **slugs**, never database IDs, so local and production IDs may differ safely. It deliberately avoids supplier-specific Pani Teresa and Sigvaris/Mobilis category branches.

| NeoxMed code/product family | Konji target slug(s) |
| --- | --- |
| `B-*` | `produkty-ortopedyczne-bark-stabilizatory-ortopedyczne` |
| `K-*`, `EK-*` | `produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-kolanowy` |
| `S-01..S-04`, `ES-*` | `produkty-ortopedyczne-stabilizatory-ortopedyczne-staw-skokowy` |
| `S-05`, `S-06` | `walkery` |
| `C-01`, `U-01` | `stabilizatory-ortopedyczne` |
| `L-*`, `EL-*` | `produkty-ortopedyczne-stabilizatory-ortopedyczne-lokiec` |
| normal `N-*`, `EN-*` | `produkty-ortopedyczne-stabilizatory-ortopedyczne-nadgarstek` |
| `N-02`, `N-04`, `N-07` | wrist + `kciuk` |
| `N-09` | `kciuk` |
| `N-10` | `stabilizatory-ortopedyczne` fallback |
| `P-05`, `P-06`, `P-13`, `P-30-*` | `brzuch` |
| remaining `P-*` | `produkty-ortopedyczne-stabilizatory-ortopedyczne-kregoslup` |
| `SZ-*` | `kregoslup-szyjny` |
| `T-*` | `produkty-ortopedyczne-stabilizatory-ortopedyczne-temblaki` |
| `T-03` | slings + shoulder stabilizers |

The database audit treats every resolved target slug as required. A missing, archived or soft-deleted target category is a hard database-audit error and blocks future import implementation. Raw source slug `temblaki` is intentionally not used because it currently belongs to a supplier-specific category branch in the existing catalogue.
