<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Seni24 VAT fallback
    |--------------------------------------------------------------------------
    |
    | Seni24 exposes VAT on product/variant price data and that source value is
    | authoritative for this importer. Keep the default fallback null so a
    | scraper regression cannot silently apply one tax rate across the catalog.
    |
    | A reviewed per-product entry or --vat-rate may be supplied deliberately
    | when source VAT evidence is missing. Explicit variant VAT always wins.
    |
    */
    'default_vat_rate' => null,

    /*
    |--------------------------------------------------------------------------
    | Reviewed per-product VAT fallback map
    |--------------------------------------------------------------------------
    |
    | Key by Seni24 numeric external_product_id. These are fallbacks only; they
    | never override explicit variant VAT parsed from Seni24.
    |
    */
    'vat_rates' => [],
];
