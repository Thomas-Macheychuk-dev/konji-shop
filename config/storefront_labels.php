<?php

/*
 * Shopper-facing labels for supplier attribute names and values. Importers keep matching on the original
 * attributes.name / attribute_values.value; only the storefront (product page, cart, orders) reads these.
 * Check slugs against a database with `php artisan storefront:check-option-labels`.
 */
return [
    // attributes.slug => label
    'attributes' => [
        'mag-uni-spec' => 'Rozmiar',
        'sposob-wykonczenia-palcow' => 'Palce',
        'typ-stopy' => 'Palce',
        'wariant-dlugosci' => 'Długość',
        'ccl' => 'Klasa kompresji',
        'lewyprawy' => 'Strona',
        'lewy-prawy' => 'Strona',
        'konczyna' => 'Strona',
        'ktora-dlon' => 'Strona',
        'rzomiar' => 'Rozmiar',
        'model' => 'Model',
        'twardosc' => 'Twardość',
        'latex' => 'Lateks',
        'wybierz-wersje' => 'Wersja',
        'wzormodel' => 'Wzór',
        'warstwowosc' => 'Liczba warstw',
        'nazwa-zestawu' => 'Zestaw nr',
        'obwod' => 'Szerokość',
        'kolor-i-tkanina' => 'Kolor i tkanina',

        // Herdegen release attributes ("Herdegen <type> / <product hash>") without a general type rule below.
        'herdegen-0702d6ce11ef441cb6764d4bb6a29042-103' => 'Szerokość siedziska',
        'herdegen-1c00927e176fef040d185512a910bac2-134' => 'Wypełnienie',
        'herdegen-af5f73014e26d71d573397d6a64c84d1-136' => 'Wypełnienie',
        'herdegen-ff4374135c555cb05046db7913c18c5d-135' => 'Wypełnienie',
        'herdegen-ab1fbff74eb0c0cabb1fd98087860a8a-137' => 'Rozmiar i wypełnienie',
        'herdegen-c579bfd1355594e7955de15d36799dfe-133' => 'Rozmiar i wypełnienie',
        'herdegen-81d03d78f0a1ad9d9b92046da8462b05-131' => 'Wzór',
        'herdegen-95ae83dd8f05bc8670462ac65c57afd5-130' => 'Wzór',
        'herdegen-6a8c06714799ebcf2778982ff66dd8b8-132' => 'Długość',
    ],

    // regex on attributes.name => label, for attributes created by future imports
    'attribute_name_patterns' => [
        '#^Herdegen 26 / #' => 'Kolor',
        '#^Herdegen 24 / #' => 'Rozmiar',
        '#^Herdegen 98 / #' => 'Strona',
    ],

    // attribute_values.value => label
    'values' => [
        'With latex' => 'Z lateksem',
        'Single unit' => '1 sztuka',
        '1 Para' => '1 para',
        '<Brak>' => 'Uniwersalny',
        'MIĘKKA' => 'Miękka',
        'TWARDA' => 'Twarda',
        'LEWY' => 'Lewa',
        'PRAWY' => 'Prawa',
        'specjalny' => 'Specjalny',
        'uniwersalny' => 'Uniwersalny',
    ],
];
