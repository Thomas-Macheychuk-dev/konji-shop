<?php

declare(strict_types=1);

return [
    'base_url' => 'https://wojdak.pl',

    'attributes' => [
        'size' => [
            'external_attribute_id' => 'wojdak-size',
            'name' => 'Rozmiar',
        ],
        'height' => [
            'external_attribute_id' => 'wojdak-height',
            'name' => 'Wzrost',
        ],
        'skirt_length' => [
            'external_attribute_id' => 'wojdak-skirt-length',
            'name' => 'Długość spódnicy',
        ],
    ],

    'size_tables' => [
        'clothing' => [
            'pdf' => 'https://wojdak.pl/wp-content/uploads/2023/05/Tabela-rozmiarow-odziez.pdf',

            'female' => [
                'sizes' => [
                    '34' => ['chest_cm' => 80, 'waist_cm' => 62, 'hips_cm' => 88],
                    '36' => ['chest_cm' => 84, 'waist_cm' => 66, 'hips_cm' => 92],
                    '38' => ['chest_cm' => 88, 'waist_cm' => 70, 'hips_cm' => 96],
                    '40' => ['chest_cm' => 92, 'waist_cm' => 74, 'hips_cm' => 100],
                    '42' => ['chest_cm' => 96, 'waist_cm' => 78, 'hips_cm' => 104],
                    '44' => ['chest_cm' => 100, 'waist_cm' => 82, 'hips_cm' => 108],
                    '46' => ['chest_cm' => 104, 'waist_cm' => 86, 'hips_cm' => 112],
                    '48' => ['chest_cm' => 108, 'waist_cm' => 90, 'hips_cm' => 116],
                    '50' => ['chest_cm' => 112, 'waist_cm' => 94, 'hips_cm' => 120],
                    '52' => ['chest_cm' => 116, 'waist_cm' => 98, 'hips_cm' => 124],
                    '54' => ['chest_cm' => 120, 'waist_cm' => 102, 'hips_cm' => 128],
                    '56' => ['chest_cm' => 124, 'waist_cm' => 106, 'hips_cm' => 132],
                ],
                'height_groups' => [
                    'short_or_three_quarter_sleeve' => ['158/164', '170/176'],
                    'long_sleeve_or_trousers' => ['158', '164', '170', '176'],
                ],
                'skirt_lengths_cm' => ['55', '65'],
            ],

            'male' => [
                'sizes' => [
                    '44' => ['chest_cm' => 88, 'waist_cm' => 78, 'hips_cm' => 92],
                    '46' => ['chest_cm' => 92, 'waist_cm' => 82, 'hips_cm' => 95],
                    '48' => ['chest_cm' => 96, 'waist_cm' => 86, 'hips_cm' => 98],
                    '50' => ['chest_cm' => 100, 'waist_cm' => 90, 'hips_cm' => 101],
                    '52' => ['chest_cm' => 104, 'waist_cm' => 94, 'hips_cm' => 104],
                    '54' => ['chest_cm' => 108, 'waist_cm' => 98, 'hips_cm' => 107],
                    '56' => ['chest_cm' => 112, 'waist_cm' => 102, 'hips_cm' => 111],
                    '58' => ['chest_cm' => 116, 'waist_cm' => 106, 'hips_cm' => 115],
                    '60' => ['chest_cm' => 120, 'waist_cm' => 110, 'hips_cm' => 119],
                    '62' => ['chest_cm' => 124, 'waist_cm' => 114, 'hips_cm' => 123],
                ],
                'height_groups' => [
                    'short_sleeve' => ['170/176', '182/188'],
                    'long_sleeve_or_trousers' => ['170', '176', '182', '188'],
                ],
            ],
        ],

        'footwear' => [
            'pdf' => 'https://wojdak.pl/wp-content/uploads/2023/05/Tabela-rozmiarow-obuwie.pdf',

            'female' => [
                'sizes' => [
                    '35' => ['insole_length_cm' => 22.4],
                    '36' => ['insole_length_cm' => 23.0],
                    '37' => ['insole_length_cm' => 23.7],
                    '38' => ['insole_length_cm' => 24.4],
                    '39' => ['insole_length_cm' => 25.0],
                    '40' => ['insole_length_cm' => 25.7],
                    '41' => ['insole_length_cm' => 26.4],
                    '42' => ['insole_length_cm' => 27.0],
                ],
            ],

            'male' => [
                'sizes' => [
                    '41' => ['insole_length_cm' => 26.4],
                    '42' => ['insole_length_cm' => 27.0],
                    '43' => ['insole_length_cm' => 27.7],
                    '44' => ['insole_length_cm' => 28.4],
                    '45' => ['insole_length_cm' => 29.0],
                    '46' => ['insole_length_cm' => 29.7],
                ],
            ],
        ],
    ],
];
