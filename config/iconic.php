<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Reviewed Iconic VAT mapping
    |--------------------------------------------------------------------------
    |
    | Key this map by Iconic external_product_id (the canonical product slug).
    | Keep it explicit and reviewed. Do not infer VAT from category membership,
    | product wording, medical-device wording, or supplier catalogue number.
    |
    | The Iconic import command blocks all database writes when any selected
    | priced product lacks an explicit VAT rate after this map / CLI overrides.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Default Iconic VAT rate
    |--------------------------------------------------------------------------
    |
    | Business-approved fallback for all commerce-eligible Iconic products.
    | Per-product vat_rates entries still take precedence when present, and
    | --vat-rate may be used as an explicit command-line fallback override.
    |
    */
    'default_vat_rate' => 8,

    'vat_rates' => [],
];
