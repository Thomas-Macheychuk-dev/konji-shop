<?php

declare(strict_types=1);

return [
    'providers' => [
        'polkurier' => [
            'base_url' => env('POLKURIER_BASE_URL', 'https://api.polkurier.pl'),
            'login' => env('POLKURIER_LOGIN'),
            'token' => env('POLKURIER_TOKEN'),

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
