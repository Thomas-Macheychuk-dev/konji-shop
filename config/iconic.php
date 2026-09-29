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
    'vat_rates' => [],
];
