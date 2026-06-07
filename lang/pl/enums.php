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
        'text' => 'Tekst',
    ],

    'stock_status' => [
        'in_stock' => 'Dostępny',
        'out_of_stock' => 'Niedostępny',
        'preorder' => 'Przedsprzedaż',
    ],

    'order_status' => [
        'draft' => 'Szkic',
        'pending_payment' => 'Oczekuje na płatność',
        'confirmed' => 'Potwierdzone',
        'completed' => 'Zrealizowane',
        'cancelled' => 'Anulowane',
    ],

    'payment_status' => [
        'unpaid' => 'Nieopłacone',
        'pending' => 'Oczekujące',
        'paid' => 'Opłacone',
        'failed' => 'Nieudane',
        'refunded' => 'Zwrócone',
        'partially_refunded' => 'Częściowo zwrócone',
    ],

    'fulfilment_status' => [
        'unfulfilled' => 'Nieprzygotowane do realizacji',
        'processing' => 'W realizacji',
        'shipped' => 'Wysłane',
        'delivered' => 'Dostarczone',
        'returned' => 'Zwrócone',
        'ready_for_pickup' => 'Gotowe do odbioru',
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

    'shipment_status' => [
        'pending' => 'Oczekuje',
        'created' => 'Utworzona',
        'dispatched' => 'Wysłana',
        'in_transit' => 'W transporcie',
        'delivered' => 'Dostarczona',
        'failed' => 'Nieudana',
        'cancelled' => 'Anulowana',
        'returned' => 'Zwrócona do nadawcy',
    ],
    'withdrawal_status' => [
        'submitted' => 'Złożone',
        'acknowledged' => 'Potwierdzone',
        'under_review' => 'W trakcie weryfikacji',
        'awaiting_goods' => 'Oczekuje na towar',
        'goods_received' => 'Towar otrzymany',
        'refund_pending' => 'Zwrot oczekuje',
        'refunded' => 'Zwrócone środki',
        'rejected' => 'Odrzucone',
        'cancelled' => 'Anulowane',
    ],

    'cart_status' => [
        'active' => 'Aktywny',
        'converted' => 'Przekształcony',
        'abandoned' => 'Porzucony',
        'expired' => 'Wygasły',
    ],

];
