<?php

declare(strict_types=1);

return [
    'product_status' => [
        'draft' => 'Szkic',
        'active' => 'Aktywny',
        'archived' => 'Zarchiwizowany',
    ],

    'product_variant_status' => [
        'draft' => 'Szkic',
        'active' => 'Aktywny',
        'archived' => 'Zarchiwizowany',
    ],

    'category_status' => [
        'active' => 'Aktywna',
        'archived' => 'Zarchiwizowana',
    ],

    'vat_rate' => [
        '23' => '23%',
        '8' => '8%',
        '5' => '5%',
        '0' => '0%',
    ],

    'currency' => [
        'PLN' => 'Polski złoty',
    ],

    'attribute_display_type' => [
        'select' => 'Lista wyboru',
        'radio' => 'Przyciski opcji',
        'color_swatch' => 'Próbnik koloru',
    ],

    'stock_status' => [
        'in_stock' => 'Dostępny',
        'out_of_stock' => 'Niedostępny',
        'preorder' => 'Przedsprzedaż',
    ],

    'order_status' => [
        'draft' => 'Szkic',
        'pending_payment' => 'Oczekuje na płatność',
        'paid' => 'Opłacone',
        'processing' => 'W realizacji',
        'shipped' => 'Wysłane',
        'completed' => 'Zrealizowane',
        'cancelled' => 'Anulowane',
    ],

    'payment_status' => [
        'unpaid' => 'Nieopłacone',
        'pending' => 'Oczekujące',
        'paid' => 'Opłacone',
        'failed' => 'Nieudane',
        'refunded' => 'Zwrócone',
    ],

    'fulfilment_status' => [
        'unfulfilled' => 'Nieprzygotowane do realizacji',
        'processing' => 'W realizacji',
        'shipped' => 'Wysłane',
        'delivered' => 'Dostarczone',
        'returned' => 'Zwrócone',
    ],

    'company_data_key' => [
        'name' => 'Nazwa firmy',
        'legal_name' => 'Pełna nazwa firmy',
        'tax_id' => 'NIP',
        'regon' => 'REGON',
        'krs' => 'KRS',
        'email' => 'E-mail',
        'phone' => 'Telefon',
        'street' => 'Ulica',
        'postal_code' => 'Kod pocztowy',
        'city' => 'Miasto',
        'country' => 'Kraj',
        'bank_account' => 'Numer konta bankowego',
    ],
];
