<?php

declare(strict_types=1);

return [
    'providers' => [
        'polkurier' => [
            'base_url' => env('POLKURIER_BASE_URL', 'https://api.polkurier.pl'),
            'login' => env('POLKURIER_LOGIN'),
            'token' => env('POLKURIER_TOKEN'),

            'labels' => [
                'disk' => env('POLKURIER_LABEL_DISK', 'local'),
                'path' => env('POLKURIER_LABEL_PATH', 'polkurier/labels'),
            ],

            'valuation' => [
                'enabled' => env('POLKURIER_VALUATION_ENABLED', false),

                'fallback_prices' => [
                    'inpost' => [
                        'parcel_locker' => (int) env('POLKURIER_FALLBACK_INPOST_PARCEL_LOCKER_AMOUNT', 0),
                        'courier' => (int) env('POLKURIER_FALLBACK_INPOST_COURIER_AMOUNT', 0),
                    ],
                    'ups' => [
                        'courier' => (int) env('POLKURIER_FALLBACK_UPS_COURIER_AMOUNT', 0),
                    ],
                    'dpd' => [
                        'courier' => (int) env('POLKURIER_FALLBACK_DPD_COURIER_AMOUNT', 0),
                    ],
                    'local_pickup' => [
                        'local_pickup' => 0,
                    ],
                ],
            ],

            'default_pack' => [
                'shipmenttype' => env('POLKURIER_DEFAULT_SHIPMENT_TYPE', 'box'),
                'length' => (int) env('POLKURIER_DEFAULT_PACK_LENGTH', 30),
                'width' => (int) env('POLKURIER_DEFAULT_PACK_WIDTH', 20),
                'height' => (int) env('POLKURIER_DEFAULT_PACK_HEIGHT', 10),
                'weight' => (float) env('POLKURIER_DEFAULT_PACK_WEIGHT', 1),
                'amount' => (int) env('POLKURIER_DEFAULT_PACK_AMOUNT', 1),
                'type' => env('POLKURIER_DEFAULT_PACK_TYPE', 'ST'),
            ],

            'sender' => [
                'company' => env('POLKURIER_SENDER_COMPANY'),
                'person' => env('POLKURIER_SENDER_PERSON'),
                'street' => env('POLKURIER_SENDER_STREET'),
                'housenumber' => env('POLKURIER_SENDER_HOUSE_NUMBER'),
                'flatnumber' => env('POLKURIER_SENDER_FLAT_NUMBER'),
                'postcode' => env('POLKURIER_SENDER_POSTCODE'),
                'city' => env('POLKURIER_SENDER_CITY'),
                'email' => env('POLKURIER_SENDER_EMAIL'),
                'phone' => env('POLKURIER_SENDER_PHONE'),
                'country' => env('POLKURIER_SENDER_COUNTRY', 'PL'),
                'machinename' => env('POLKURIER_SENDER_MACHINE_NAME'),
            ],
        ],
    ],
];
