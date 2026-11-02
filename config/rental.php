<?php

declare(strict_types=1);

return [
    // Rental enquiries are taken by phone; the numbers come from the legacy ortezka.pl rental page.
    'phones' => [
        env('RENTAL_PHONE_PRIMARY', '+48 697 595 045'),
        env('RENTAL_PHONE_SECONDARY', '+48 604 895 717'),
    ],
    'address' => env('RENTAL_ADDRESS', 'Bolesława Prusa 20, 60-820 Poznań'),
];
