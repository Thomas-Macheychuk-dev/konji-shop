<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Reviewed Dr Sapporo VAT mapping
    |--------------------------------------------------------------------------
    |
    | This map is keyed by the stable supplier external_product_id.
    |
    | 8%:
    | - documented class-I medical devices admitted to trade;
    |
    | 23%:
    | - separately sold replacement textile pillow covers for which the
    |   manufacturer documentation reviewed on 2026-09-28 did not identify
    |   the standalone cover as a medical device or MDR accessory.
    |
    | Keep this mapping explicit and reviewed. Do not infer VAT solely from
    | is_medical_device or the product category.
    |
    */
    'vat_rates' => [
        'poduszka-ortopedyczna-rock' => 8,
        'poduszka-ortopedyczna-shell' => 8,
        'poduszka-ortopedyczna-cpap' => 8,
        'poduszka-ortopedyczna-paris' => 8,
        'poduszka-ortopedyczna-swing' => 8,
        'poduszka-ortopedyczna-jazz' => 8,
        'poduszka-ortopedyczna-blues' => 8,
        'poduszka-ortopedyczna-bossanova' => 8,
        'poduszka-ortopedyczna-nuvo' => 8,
        'poduszka-ortopedyczna-twin-plus' => 8,
        'poduszka-ortopedyczna-open' => 8,
        'poduszka-ortopedyczna-max-plus' => 8,
        'poduszka-ortopedyczna-new-york' => 8,
        'poduszka-ortopedyczna-london' => 8,
        'poduszka-ortopedyczna-salsa-standard' => 8,
        'poduszka-ortopedyczna-salsa-mini' => 8,

        'poduszka-ortopedyczna-asana' => 8,
        'poduszka-ortopedyczna-enso' => 8,
        'poduszka-ortopedyczna-hiro' => 8,

        'aparat-na-haluksy-bunito-duo-ecru' => 8,
        'aparat-na-haluksy-bunito-duo-magenta' => 8,
        'aparat-na-haluksy-bunito-duo-turkus' => 8,

        'poszewka-na-poduszke-rock' => 23,
        'poszewka-na-poduszke-shell' => 23,
        'poszewka-na-poduszke-cpap' => 23,
        'poszewka-na-poduszke-paris' => 23,
        'poszewka-na-poduszke-swing' => 23,
        'poszewka-na-poduszke-jazz' => 23,
        'poszewka-na-poduszke-blues' => 23,
        'poszewka-na-poduszke-bossanova' => 23,
        'poszewka-na-poduszke-nuvo' => 23,
        'poszewka-na-poduszke-twin-plus' => 23,
        'poszewka-na-poduszke-open' => 23,
        'poszewka-na-poduszke-max-plus' => 23,
        'poszewka-na-poduszke-new-york' => 23,
        'poszewka-na-poduszke-london' => 23,
        'poszewka-na-poduszke-salsa-standard' => 23,
        'poszewka-na-poduszke-salsa-mini' => 23,

        'poszewka-na-poduszke-ortopedyczna-yoko' => 23,
        'poszewka-na-poduszke-ortopedyczna-enso' => 23,
        'poszewka-na-poduszke-ortopedyczna-hiro' => 23,
        'poszewka-na-poduszke-ortopedyczna-asana' => 23,
    ],
];
