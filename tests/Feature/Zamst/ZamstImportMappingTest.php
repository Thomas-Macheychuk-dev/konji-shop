<?php

declare(strict_types=1);

use App\Services\Zamst\ZamstImportMapper;
use Illuminate\Support\Facades\Artisan;

it('maps concrete Zamst variations and removes selectable options that have no real variation', function (): void {
    $mapper = app(ZamstImportMapper::class);
    $mapped = $mapper->mapProduct(zamstImportMappingProduct());

    expect($mapped['errors'])->toBe([])
        ->and($mapped['product'])->toMatchArray([
            'external_source' => 'zamst',
            'external_id' => '2164',
            'external_parent_sku' => 'ZAMST-2164',
            'name' => 'Stabilizator rzepki Zamst JK-2',
            'status' => 'draft',
            'manufacturer' => 'Zamst',
        ])
        ->and($mapped['tax'])->toMatchArray([
            'vat_rate' => 23,
            'requires_review' => true,
            'gross_minor' => 28900,
            'currency' => 'PLN',
        ])
        ->and($mapped['variants'])->toHaveCount(2)
        ->and($mapped['variants'][0])->toMatchArray([
            'source_external_variant_id' => '2169',
            'external_variant_id' => 'zamst-2164-2169',
            'sku' => 'ZAMST-2164-2169',
            'status' => 'draft',
            'is_default' => true,
            'price_gross_minor' => 28900,
            'stock_status' => 'in_stock',
        ])
        ->and($mapped['categories'])->toHaveCount(2)
        ->and($mapped['categories'][0]['path'])->toBe(['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'])
        ->and(collect($mapped['categories'])->pluck('path')->all())->not->toContain(['Stabilizator na rzepkę'])
        ->and($mapped['images'])->toHaveCount(2)
        ->and($mapped['downloads'])->toHaveCount(1)
        ->and($mapped['videos'])->toHaveCount(1)
        ->and($mapped['videos'][0]['source_url'])->toBe('https://youtube.com/watch?v=jk2-demo')
        ->and($mapped['filtered_non_product_video_count'])->toBe(1);

    $sizeAttribute = collect($mapped['attributes'])->firstWhere('code', 'rozmiar');
    $producerAttribute = collect($mapped['attributes'])->firstWhere('code', 'producent');

    expect(collect($sizeAttribute['values'])->pluck('value_label')->all())->toBe(['S', 'M'])
        ->and(collect($sizeAttribute['values'])->pluck('value_label')->all())->not->toContain('2XL')
        ->and($producerAttribute['values'])->toBe([[
            'value' => 'zamst',
            'value_label' => 'Zamst',
        ]]);
});

it('maps a simple Zamst product to one stable default Konji variant', function (): void {
    $product = zamstImportMappingProduct();
    $product['external_product_id'] = '2034';
    $product['external_id'] = '2034';
    $product['slug'] = 'iw-1';
    $product['name'] = 'System do krioterapii Zamst IW-1';
    $product['price_gross_amount'] = 249.0;
    $product['variant_candidates'] = [];
    $product['attributes'] = [];
    $product['category'] = 'KRIOTERAPIA MIEJSCOWA';
    $product['source_category_paths'] = [['KRIOTERAPIA MIEJSCOWA']];

    $mapped = app(ZamstImportMapper::class)->mapProduct($product);

    expect($mapped['errors'])->toBe([])
        ->and($mapped['source_variant_count'])->toBe(0)
        ->and($mapped['variants'])->toHaveCount(1)
        ->and($mapped['variants'][0])->toMatchArray([
            'source_external_variant_id' => null,
            'external_variant_id' => 'zamst-2034-default',
            'sku' => 'ZAMST-2034',
            'price_gross_minor' => 24900,
            'is_default' => true,
        ]);
});

it('creates a saved Zamst import map without database writes or image downloads', function (): void {
    $sourceRelativePath = 'scrapers/zamst/product-data-map-test.json';
    $saveRelativePath = 'scrapers/zamst/import-map-test.json';
    $sourcePath = storage_path('app/'.$sourceRelativePath);
    $savePath = storage_path('app/'.$saveRelativePath);
    @mkdir(dirname($sourcePath), 0755, true);
    @unlink($sourcePath);
    @unlink($savePath);

    file_put_contents($sourcePath, json_encode([
        'source' => 'zamst',
        'products' => [zamstImportMappingProduct()],
    ], JSON_THROW_ON_ERROR));

    $exit = Artisan::call('zamst:import-map', [
        '--from' => $sourceRelativePath,
        '--save' => $saveRelativePath,
        '--show-products' => true,
    ]);

    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Database writes: NO')
        ->and($output)->toContain('Planned Konji variants: 2')
        ->and($output)->toContain('PASS: mapping is structurally ready')
        ->and(is_file($savePath))->toBeTrue();

    $saved = json_decode((string) file_get_contents($savePath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['images_downloaded'])->toBeFalse()
        ->and($saved['ready_for_local_import_implementation'])->toBeTrue();

    @unlink($sourcePath);
    @unlink($savePath);
});

function zamstImportMappingProduct(): array
{
    return [
        'source' => 'zamst',
        'source_url' => 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/',
        'canonical_url' => 'https://zamst.com.pl/produkt/stabilizator-kolana-jk-2/',
        'external_product_id' => '2164',
        'external_id' => '2164',
        'slug' => 'stabilizator-kolana-jk-2',
        'name' => 'Stabilizator rzepki Zamst JK-2',
        'brand' => 'Zamst',
        'sku' => null,
        'seo_description' => 'Zamst JK-2 – stabilizator kolana dla sportowców.',
        'short_description' => 'Zamst JK-2 – stabilizator kolana dla sportowców.',
        'description_html' => '<p>Stabilizator przeznaczony dla aktywnych sportowców.</p>',
        'price_gross_amount' => 289.0,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'category' => 'Stabilizator na rzepkę',
        'categories' => [
            'Stabilizatory stawu kolanowego',
            'Stabilizator na rzepkę',
            'Ortezy dla siatkarzy',
        ],
        'source_category_paths' => [
            ['Stabilizatory stawu kolanowego', 'Stabilizator na rzepkę'],
            ['Stabilizator na rzepkę'],
            ['Ortezy dla siatkarzy'],
        ],
        'attributes' => [[
            'code' => 'rozmiar',
            'label' => 'Rozmiar',
            'options' => [
                ['value' => 's', 'label' => 'S'],
                ['value' => 'm', 'label' => 'M'],
                ['value' => '2xl', 'label' => '2XL'],
            ],
        ]],
        'variant_candidates' => [
            [
                'external_variant_id' => '2169',
                'sku' => null,
                'attributes' => [[
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 's',
                    'value_label' => 'S',
                ]],
                'price_gross_amount' => 289.0,
                'currency' => 'PLN',
                'availability' => 'in_stock',
                'active' => true,
                'visible' => true,
                'purchasable' => true,
            ],
            [
                'external_variant_id' => '2168',
                'sku' => null,
                'attributes' => [[
                    'code' => 'rozmiar',
                    'label' => 'Rozmiar',
                    'value' => 'm',
                    'value_label' => 'M',
                ]],
                'price_gross_amount' => 289.0,
                'currency' => 'PLN',
                'availability' => 'in_stock',
                'active' => true,
                'visible' => true,
                'purchasable' => true,
            ],
        ],
        'gallery_images' => [[
            'url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp',
            'alt' => 'Stabilizator rzepki Zamst JK-2',
            'title' => null,
        ]],
        'content_images' => [[
            'url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/jk-2-feature.webp',
            'alt' => 'Technologia JK-2',
            'title' => null,
        ]],
        'images' => [
            [
                'url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/01-jk-2.webp',
                'alt' => 'Stabilizator rzepki Zamst JK-2',
                'title' => null,
            ],
            [
                'url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/jk-2-feature.webp',
                'alt' => 'Technologia JK-2',
                'title' => null,
            ],
        ],
        'downloads' => [[
            'url' => 'https://zamst.com.pl/wp-content/uploads/2020/08/JK-2_PL.pdf',
            'label' => 'Instrukcja JK-2',
            'extension' => 'pdf',
        ]],
        'videos' => [
            ['url' => 'https://www.youtube.com/@zamstpolska', 'label' => 'Zamst Polska'],
            ['url' => 'https://youtube.com/watch?v=jk2-demo', 'label' => 'Film JK-2'],
        ],
        'is_medical_device' => null,
        'warnings' => [],
    ];
}
