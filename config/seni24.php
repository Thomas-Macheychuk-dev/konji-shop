<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Seni24 import safety
    |--------------------------------------------------------------------------
    |
    | Seni24 carries products with different VAT rates. The scraper records the
    | VAT rate presented on each product page and the importer requires an
    | explicit valid rate for every selected commerce product. Keep the default
    | null: a blanket VAT fallback must be supplied deliberately with --vat-rate.
    |
    */
    'default_vat_rate' => null,

    'vat_rates' => [],

    /*
    |--------------------------------------------------------------------------
    | Source taxonomy root
    |--------------------------------------------------------------------------
    |
    | Source breadcrumbs are imported below this isolated root so the supplier
    | taxonomy cannot silently merge with existing production category trees.
    |
    */
    'root_category_name' => 'Seni24',
];
