<?php

return [
    'default' => env('PAYMENT_DEFAULT_PROVIDER', 'paynow'),

    'providers' => [
        'przelewy24' => [
            'merchant_id' => env('PRZELEWY24_MERCHANT_ID'),
            'pos_id'      => env('PRZELEWY24_POS_ID'),
            'crc'         => env('PRZELEWY24_CRC'),
            'sandbox'     => env('PRZELEWY24_SANDBOX', true),
        ],

        'paynow' => [
            'api_key'       => env('PAYNOW_API_KEY'),
            'signature_key' => env('PAYNOW_SIGNATURE_KEY'),
            'sandbox'       => env('PAYNOW_SANDBOX', true),

            'notification_path' => env('PAYNOW_NOTIFICATION_PATH', '/api/payments/paynow/notifications'),
            'return_path'       => env('PAYNOW_RETURN_PATH', '/checkout/success'),
        ],
    ],
];
