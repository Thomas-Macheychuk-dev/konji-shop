<?php

use App\Services\Sigvaris\SigvarisImportMapper;

it('maps concrete PrestaShop combinations using source IDs rather than duplicate source references', function (): void {
    $products = [
        'products' => [[
            'external_product_id' => '7908',
            'default_combination_id' => '1001',
            'name' => 'MAGIC Pończochy',
            'source_url' => 'https://www.sklep-sigvaris.com/7908-1001-magic.html',
            'canonical_url' => 'https://www.sklep-sigvaris.com/7908-1001-magic.html',
            'reference' => 'MAGIC-ThighwGripTop-Women',
            'price_gross_amount' => 250.0,
            'price_net_amount' => 231.481481,
            'tax_rate_percent' => 8.0,
            'currency' => 'PLN',
            'availability' => 'in_stock',
            'stock_quantity' => 10,
            'is_medical_device' => true,
            'manufacturer' => 'SIGVARIS S.A.',
            'attributes' => [['label' => 'Rozmiar', 'options' => ['S', 'M'], 'selected' => 'S']],
            'features' => [['label' => 'Klasa', 'value' => 'CCL2']],
            'images' => [
                ['url' => 'https://www.sklep-sigvaris.com/3378-medium_default/magic.jpg', 'alt' => 'A medium'],
                ['url' => 'https://www.sklep-sigvaris.com/3378-large_default/magic.jpg', 'alt' => 'A large'],
            ],
            'downloads' => [['url' => 'https://www.sklep-sigvaris.com/module/prestadogpsrmanager/download?id=1', 'label' => 'Dokument']],
            'description_html' => '<p>Opis</p>',
            'source_category_paths' => [
                ['Wyroby kompresyjne'],
                ['Wyroby kompresyjne', 'Sigvaris Medical', 'Pończochy uciskowe Sigvaris'],
            ],
        ]],
    ];
    $combinations = [
        'products' => [[
            'external_product_id' => '7908',
            'default_combination_id' => '1001',
            'truncated' => false,
            'failed_requests' => [],
            'combinations' => [
                [
                    'external_variant_id' => '1001',
                    'product_url' => 'https://www.sklep-sigvaris.com/7908-1001-magic.html',
                    'reference' => 'MAGIC-ThighwGripTop-Women',
                    'display_price_amount' => 250.0,
                    'availability' => 'in_stock',
                    'stock_quantity' => 5,
                    'attributes' => [[
                        'external_group_id' => '3', 'label' => 'Rozmiar', 'external_attribute_id' => '51', 'value' => 'S',
                    ]],
                ],
                [
                    'external_variant_id' => '1002',
                    'product_url' => 'https://www.sklep-sigvaris.com/7908-1002-magic.html',
                    'reference' => 'MAGIC-ThighwGripTop-Women',
                    'display_price_amount' => 250.0,
                    'availability' => 'in_stock',
                    'stock_quantity' => 7,
                    'attributes' => [[
                        'external_group_id' => '3', 'label' => 'Rozmiar', 'external_attribute_id' => '54', 'value' => 'M',
                    ]],
                ],
            ],
        ]],
    ];

    $mapped = app(SigvarisImportMapper::class)->mapCatalogue($products, $combinations);
    $product = $mapped['products'][0];

    expect($mapped['errors'])->toBe([])
        ->and($mapped['ready_for_local_import_implementation'])->toBeTrue()
        ->and($mapped['summary']['source_concrete_combinations'])->toBe(2)
        ->and($mapped['summary']['planned_variants'])->toBe(2)
        ->and($product['product']['external_parent_sku'])->toBe('SIGVARIS-7908')
        ->and($product['tax']['vat_rate'])->toBe(8.0)
        ->and($product['variants'][0]['external_variant_id'])->toBe('sigvaris-7908-1001')
        ->and($product['variants'][0]['sku'])->toBe('SIGVARIS-7908-1001')
        ->and($product['variants'][1]['sku'])->toBe('SIGVARIS-7908-1002')
        ->and($product['variants'][0]['source_reference'])->toBe('MAGIC-ThighwGripTop-Women')
        ->and($product['variants'][1]['source_reference'])->toBe('MAGIC-ThighwGripTop-Women')
        ->and($product['variants'][0]['attributes'][0]['source_attribute_id'])->toBe('51')
        ->and($product['images'])->toHaveCount(1)
        ->and($product['images'][0]['source_url'])->toBe('https://www.sklep-sigvaris.com/3378-large_default/magic.jpg')
        ->and($product['downloads'])->toHaveCount(1)
        ->and($product['categories'][0]['path'])->toBe(['Wyroby kompresyjne', 'Sigvaris Medical', 'Pończochy uciskowe Sigvaris']);
});

it('creates one stable default variant only for a selector-less Sigvaris product', function (): void {
    $products = ['products' => [[
        'external_product_id' => '106544',
        'default_combination_id' => null,
        'name' => 'Śliska Stopka od Pani TERESA® MEDICA',
        'price_gross_amount' => 49.0,
        'price_net_amount' => 39.837398,
        'tax_rate_percent' => 23.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'stock_quantity' => 20,
        'is_medical_device' => false,
        'manufacturer' => null,
        'attributes' => [],
        'images' => [['url' => 'https://www.sklep-sigvaris.com/img/stopka.webp']],
        'downloads' => [],
        'description_html' => '<p>Opis</p>',
        'source_category_paths' => [['Akcesoria']],
    ]]];
    $combinations = ['products' => [[
        'external_product_id' => '106544',
        'default_combination_id' => null,
        'truncated' => false,
        'failed_requests' => [],
        'combinations' => [],
    ]]];

    $mapped = app(SigvarisImportMapper::class)->mapCatalogue($products, $combinations);
    $product = $mapped['products'][0];

    expect($mapped['errors'])->toBe([])
        ->and($mapped['summary']['source_concrete_combinations'])->toBe(0)
        ->and($mapped['summary']['planned_variants'])->toBe(1)
        ->and($mapped['summary']['stable_default_variants'])->toBe(1)
        ->and($product['variants'])->toHaveCount(1)
        ->and($product['variants'][0]['external_variant_id'])->toBe('sigvaris-106544-default')
        ->and($product['variants'][0]['sku'])->toBe('SIGVARIS-106544')
        ->and($product['tax']['vat_rate'])->toBe(23.0)
        ->and($product['review_items'])->toContain('source manufacturer is not stated; do not invent Producent during import.');
});

it('blocks mapping when a product with selectors has no concrete combinations', function (): void {
    $products = ['products' => [[
        'external_product_id' => '1',
        'name' => 'Variable',
        'price_gross_amount' => 108.0,
        'price_net_amount' => 100.0,
        'tax_rate_percent' => 8.0,
        'currency' => 'PLN',
        'attributes' => [['label' => 'Rozmiar', 'options' => ['S']]],
        'images' => [['url' => 'https://www.sklep-sigvaris.com/a.webp']],
        'source_category_paths' => [['A']],
    ]]];
    $combinations = ['products' => [[
        'external_product_id' => '1',
        'truncated' => false,
        'failed_requests' => [],
        'combinations' => [],
    ]]];

    $mapped = app(SigvarisImportMapper::class)->mapCatalogue($products, $combinations);

    expect($mapped['ready_for_local_import_implementation'])->toBeFalse()
        ->and(implode(' | ', $mapped['errors']))->toContain('selectors but no concrete PrestaShop combinations');
});
