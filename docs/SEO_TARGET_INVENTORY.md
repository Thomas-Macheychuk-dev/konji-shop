# SEO-02A — Konji target inventory

`php artisan seo:target-inventory` is a read-only exporter used by the Ortezka legacy SEO migration.

It performs **no database writes** and installs **no redirect/runtime behavior**.

The exporter writes through Laravel's `local` disk. In this application that disk is rooted at `storage/app/private`. Command options remain paths relative to the local disk (for example `scrapers/seo/ortezka/target-inventory.json`).

## Outputs

By default it creates:

- `storage/app/private/scrapers/seo/ortezka/target-inventory.json`
- `storage/app/private/scrapers/seo/ortezka/target-products.csv`
- `storage/app/private/scrapers/seo/ortezka/target-categories.csv`

The product inventory includes the fields required for deterministic legacy matching:

- product ID/name/slug and final `/products/{slug}` path;
- product status, soft-delete state and storefront reachability;
- `published_at` as evidence (the current storefront controller does not gate active products on it);
- `external_source`, `external_id`, and `external_parent_sku`;
- product variants, SKU, normalized matching key, status/default/deleted state;
- category assignments and primary-category flag.

The category inventory includes the final `/categories/{slug}` path and the same status/deletion reachability boundary used by the storefront.

## Matching-key normalization

The exporter deliberately uses conservative normalization only:

1. trim leading/trailing whitespace;
2. collapse internal whitespace;
3. Unicode lowercase.

It does **not** remove punctuation from SKUs. Codes such as `AM-KDX-01/1RE` remain structurally distinct.

SEO-02B must only auto-approve an identifier match when the normalized identifier is unique on the legacy side and unique on the target side, and the destination product is storefront-reachable.
