<?php

declare(strict_types=1);

return [
    'providers' => [
        'polkurier' => [
            'base_url' => env('POLKURIER_BASE_URL', 'https://api.polkurier.pl'),
            'login' => env('POLKURIER_LOGIN'),
            'token' => env('POLKURIER_TOKEN'),

            'available_carriers' => [
                'cache_ttl' => (int) env('POLKURIER_AVAILABLE_CARRIERS_CACHE_TTL', 43200),

                'guards' => [
                    'enabled' => (bool) env('POLKURIER_AVAILABLE_CARRIERS_GUARDS_ENABLED', true),
                    'fail_when_cache_empty' => (bool) env('POLKURIER_AVAILABLE_CARRIERS_FAIL_WHEN_CACHE_EMPTY', false),
                    'block_required_additional_fields' => (bool) env('POLKURIER_AVAILABLE_CARRIERS_BLOCK_REQUIRED_ADDITIONAL_FIELDS', true),
                ],
            ],

            'configured_carriers' => [
                'inpost_parcel_locker' => [
                    'label' => 'Paczkomat InPost',
                    'code' => 'INPOST_PACZKOMAT',
                ],
                'inpost_courier' => [
                    'label' => 'Kurier InPost',
                    'code' => 'INPOST',
                ],
                'ups_courier' => [
                    'label' => 'Kurier UPS',
                    'code' => 'UPS',
                ],
                'dpd_courier' => [
                    'label' => 'Kurier DPD',
                    'code' => 'DPD',
                ],
            ],

            'labels' => [
                'disk' => env('POLKURIER_LABEL_DISK', 'local'),
                'path' => env('POLKURIER_LABEL_PATH', 'polkurier/labels'),
            ],

            'protocols' => [
                'disk' => env('POLKURIER_PROTOCOL_DISK', env('POLKURIER_LABEL_DISK', 'local')),
                'path' => env('POLKURIER_PROTOCOL_PATH', 'polkurier/protocols'),
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
                'point_id' => env('POLKURIER_SENDER_POINT_ID', env('POLKURIER_SENDER_MACHINE_NAME')),
            ],
        ],
    ],
];
