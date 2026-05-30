<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Legal document versions
    |--------------------------------------------------------------------------
    |
    | Increment these versions whenever the content materially changes.
    | Orders will store the accepted versions at checkout.
    |
    */

    'versions' => [
        'terms' => env('LEGAL_TERMS_VERSION', '2026-05-30'),
        'privacy' => env('LEGAL_PRIVACY_VERSION', '2026-05-30'),
        'returns' => env('LEGAL_RETURNS_VERSION', '2026-05-30'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shop legal details
    |--------------------------------------------------------------------------
    |
    | Replace these placeholders before production.
    |
    */

    'seller' => [
        'shop_name' => env('SHOP_NAME', 'Konji Shop'),
        'company_name' => env('SHOP_COMPANY_NAME', 'Max-Corp'),
        'representative' => env('SHOP_REPRESENTATIVE', 'Krzysztof Maciejczuk'),
        'street' => env('SHOP_STREET', 'Bolesława Prusa 20'),
        'postcode' => env('SHOP_POSTCODE', '60-406'),
        'city' => env('SHOP_CITY', 'Poznań'),
        'country' => env('SHOP_COUNTRY', 'Poland'),
        'email' => env('SHOP_EMAIL', 'tomek.maciejczuk@gmail.com'),
        'phone' => env('SHOP_PHONE', '504088084'),
        'tax_id' => env('SHOP_TAX_ID', ''),
        'business_registry_number' => env('SHOP_REGISTRY_NUMBER', ''),
    ],

    'returns' => [
        'withdrawal_days' => 14,
        'return_address' => env('SHOP_RETURN_ADDRESS', 'Bolesława Prusa 20, 60-406 Poznań, Poland'),
        'contact_email' => env('SHOP_RETURNS_EMAIL', env('SHOP_EMAIL', 'tomek.maciejczuk@gmail.com')),
    ],
];
