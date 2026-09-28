<?php

declare(strict_types=1);

return [
    'release' => [
        'version' => '2026-09-24',
        'effective_date' => '2026-09-28',
        'seller' => [
            'company_name' => 'MAX-CORP sp. z o.o.',
            'street' => 'Bolesława Prusa 20',
            'postcode' => '60-820',
            'city' => 'Poznań',
            'country' => 'Polska',
            'email' => 'sklep.ortezka@gmail.com',
            'phone' => '+48 697 595 045',
            'tax_id' => '7811955475',
            'business_registry_number' => '0000701386',
            'regon' => '368620625',
            'share_capital' => '282 450,00 zł',
            'return_address' => 'Bolesława Prusa 20, 60-820 Poznań',
            'returns_email' => 'sklep.ortezka@gmail.com',
        ],
    ],

    'versions' => [
        'terms' => env('LEGAL_TERMS_VERSION', '2026-09-24'),
        'privacy' => env('LEGAL_PRIVACY_VERSION', '2026-09-24'),
        'returns' => env('LEGAL_RETURNS_VERSION', '2026-09-24'),
    ],

    'effective_date' => env('LEGAL_EFFECTIVE_DATE', '2026-09-28'),

    'seller' => [
        'shop_name' => env('SHOP_NAME', 'ORTEZKA.PL'),
        'company_name' => env('SHOP_COMPANY_NAME', 'MAX-CORP sp. z o.o.'),
        'identity_address' => env('SHOP_SELLER_IDENTITY_ADDRESS', ''),
        'representative' => env('SHOP_REPRESENTATIVE', ''),
        'street' => env('SHOP_STREET', 'Bolesława Prusa 20'),
        'postcode' => env('SHOP_POSTCODE', '60-820'),
        'city' => env('SHOP_CITY', 'Poznań'),
        'country' => env('SHOP_COUNTRY', 'Polska'),
        'email' => env('SHOP_EMAIL', 'sklep.ortezka@gmail.com'),
        'phone' => env('SHOP_PHONE', '+48 697 595 045'),
        'tax_id' => env('SHOP_TAX_ID', '7811955475'),
        'business_registry_number' => env('SHOP_REGISTRY_NUMBER', '0000701386'),
        'regon' => env('SHOP_REGON', '368620625'),
        'share_capital' => env('SHOP_SHARE_CAPITAL', '282 450,00 zł'),
    ],

    'returns' => [
        'withdrawal_days' => 14,
        'return_address' => env('SHOP_RETURN_ADDRESS', 'Bolesława Prusa 20, 60-820 Poznań'),
        'contact_email' => env('SHOP_RETURNS_EMAIL', env('SHOP_EMAIL', 'sklep.ortezka@gmail.com')),
    ],

    'documents' => [
        'terms_pdf' => 'legal/2026-09-24/ORTEZKA_PL_Regulamin_sklepu_internetowego_2026-09-24.pdf',
        'withdrawal_form_pdf' => 'legal/2026-09-24/ORTEZKA_PL_Formularz_odstapienia_DLA_KLIENTA_2026-09-24.pdf',
        'complaint_form_pdf' => 'legal/2026-09-24/ORTEZKA_PL_Formularz_reklamacyjny_DLA_KLIENTA_2026-09-24.pdf',
    ],
];
