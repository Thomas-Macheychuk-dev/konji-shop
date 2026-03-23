<?php

declare(strict_types=1);

return [
    'product_status' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'product_variant_status' => [
        'draft' => 'Draft',
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'category_status' => [
        'active' => 'Active',
        'archived' => 'Archived',
    ],

    'vat_rate' => [
        '23' => '23%',
        '8' => '8%',
        '5' => '5%',
        '0' => '0%',
    ],

    'currency' => [
        'PLN' => 'Polish Złoty',
    ],

    'attribute_display_type' => [
        'select' => 'Select',
        'radio' => 'Radio',
        'color_swatch' => 'Color swatch',
    ],

    'stock_status' => [
        'in_stock' => 'In stock',
        'out_of_stock' => 'Out of stock',
        'preorder' => 'Pre-order',
    ],

    'order_status' => [
        'draft' => 'Draft',
        'pending_payment' => 'Pending payment',
        'paid' => 'Paid',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'payment_status' => [
        'unpaid' => 'Unpaid',
        'pending' => 'Pending',
        'paid' => 'Paid',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
    ],

    'fulfilment_status' => [
        'unfulfilled' => 'Unfulfilled',
        'processing' => 'Processing',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'returned' => 'Returned',
    ],

    'company_data_key' => [
        'name' => 'Company name',
        'legal_name' => 'Legal company name',
        'tax_id' => 'Tax ID',
        'regon' => 'REGON',
        'krs' => 'KRS',
        'email' => 'Email',
        'phone' => 'Phone',
        'street' => 'Street',
        'postal_code' => 'Postal code',
        'city' => 'City',
        'country' => 'Country',
        'bank_account' => 'Bank account',
    ],
];
