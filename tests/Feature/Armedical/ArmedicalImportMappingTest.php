<?php

declare(strict_types=1);

use App\Services\Armedical\ArmedicalImportMapper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

it('maps a simple ARmedical product to one draft default variant without inventing price or VAT', function (): void {
    $mapped = app(ArmedicalImportMapper::class)->mapProduct(armedicalImportSimpleProduct());

    expect($mapped['errors'])->toBe([])
        ->and($mapped['product'])->toMatchArray([
            'external_source' => 'armedical',
            'external_id' => 'armedical-balkonik-ar-023',
            'catalogue_number' => 'AR-023',
            'name' => 'Balkonik aluminiowy',
            'status' => 'draft',
            'manufacturer' => 'ARMEDICAL Sp. z o.o.',
        ])
        ->and($mapped['pricing'])->toMatchArray([
            'gross_minor' => null,
            'net_minor' => null,
            'vat_rate' => null,
            'currency' => 'PLN',
            'requires_review' => true,
        ])
        ->and($mapped['categories'][0]['path'])->toBe(['Produkty rehabilitacyjne', 'Balkoniki i podpórki'])
        ->and($mapped['variants'])->toHaveCount(1)
        ->and($mapped['variants'][0])->toMatchArray([
            'external_variant_id' => 'armedical-balkonik-ar-023-default',
            'sku' => 'ARMEDICAL-AR-023',
            'status' => 'draft',
            'is_default' => true,
            'price_gross_minor' => null,
            'vat_rate' => null,
        ])
        ->and($mapped['images'])->toHaveCount(1)
        ->and($mapped['documents'])->toHaveCount(2);
});

it('preserves exact ARmedical option rows and blocks an ambiguous repeated source label without silently correcting it', function (): void {
    $product = armedicalImportSimpleProduct();
    $product['external_product_id'] = 'armedical-soft-cast';
    $product['catalogue_number'] = '82102 do 82102';
    $product['sku'] = null;
    $product['name'] = '3M Soft Cast';
    $product['size_options'] = [
        ['label' => '82102', 'value' => '5 cm x 3,6 m'],
        ['label' => '82103', 'value' => '7,6 cm x 3,6 m'],
        ['label' => '82104', 'value' => '10,1 cm x 3,6 m'],
        ['label' => '82104', 'value' => '12,7 cm x 3,6 m'],
    ];

    $mapped = app(ArmedicalImportMapper::class)->mapProduct($product);

    expect($mapped['errors'])->toBe([])
        ->and($mapped['source_option_count'])->toBe(4)
        ->and($mapped['variants'])->toHaveCount(4)
        ->and(collect($mapped['variants'])->pluck('source_option_label')->all())->toBe(['82102', '82103', '82104', '82104'])
        ->and(collect($mapped['variants'])->pluck('source_option_value')->all())->toBe([
            '5 cm x 3,6 m',
            '7,6 cm x 3,6 m',
            '10,1 cm x 3,6 m',
            '12,7 cm x 3,6 m',
        ])
        ->and(collect($mapped['variants'])->pluck('external_variant_id')->unique())->toHaveCount(4)
        ->and($mapped['variants'][2]['sku'])->toBeNull()
        ->and($mapped['variants'][3]['sku'])->toBeNull()
        ->and($mapped['blocking_review_items'])->toHaveCount(1)
        ->and($mapped['blocking_review_items'][0])->toContain('82104')
        ->and($mapped['blocking_review_items'][0])->toContain('do not invent or rewrite');
});

it('produces a saved ARmedical mapping plan with a frozen SHA gate and zero database writes', function (): void {
    $sourceRelativePath = 'scrapers/armedical/product-data-import-map-test.json';
    $saveRelativePath = 'scrapers/armedical/import-map-test.json';
    Storage::disk('local')->delete([$sourceRelativePath, $saveRelativePath]);

    $products = [armedicalImportSimpleProduct()];
    $raw = (string) json_encode([
        'source' => 'armedical',
        'product_count' => 1,
        'products' => $products,
        'warnings' => [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    Storage::disk('local')->put($sourceRelativePath, $raw);
    $sha = hash('sha256', $raw);

    $exit = Artisan::call('armedical:import-map', [
        '--from' => $sourceRelativePath,
        '--expected-sha256' => $sha,
        '--expected-products' => 1,
        '--expected-options' => 0,
        '--expected-images' => 1,
        '--expected-documents' => 2,
        '--save' => $saveRelativePath,
        '--show-review' => true,
    ]);

    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Frozen SHA gate: PASS')
        ->and($output)->toContain('Database writes: NO')
        ->and($output)->toContain('Planned Konji variants: 1')
        ->and($output)->toContain('Products without source price: 1')
        ->and($output)->toContain('Ready for database write: NO')
        ->and($output)->toContain('PASS WITH REVIEW')
        ->and(Storage::disk('local')->exists($saveRelativePath))->toBeTrue();

    $saved = json_decode(Storage::disk('local')->get($saveRelativePath), true, flags: JSON_THROW_ON_ERROR);

    expect($saved['database_writes'])->toBeFalse()
        ->and($saved['images_downloaded'])->toBeFalse()
        ->and($saved['mapping_structurally_valid'])->toBeTrue()
        ->and($saved['ready_for_database_write'])->toBeFalse()
        ->and($saved['input_fingerprint']['sha256'])->toBe($sha);

    Storage::disk('local')->delete([$sourceRelativePath, $saveRelativePath]);
});

function armedicalImportSimpleProduct(): array
{
    return [
        'source' => 'armedical',
        'source_url' => 'https://armedical.pl/oferta/balkonik-ar-023/',
        'canonical_url' => 'https://armedical.pl/oferta/balkonik-ar-023/',
        'external_product_id' => 'armedical-balkonik-ar-023',
        'slug' => 'balkonik-ar-023',
        'catalogue_number' => 'AR-023',
        'sku' => 'AR-023',
        'name' => 'Balkonik aluminiowy',
        'brand' => 'ARmedical',
        'manufacturer' => 'ARMEDICAL Sp. z o.o.',
        'category' => 'Produkty rehabilitacyjne',
        'categories' => ['Balkoniki i podpórki', 'Produkty rehabilitacyjne'],
        'seo_title' => 'Balkonik aluminiowy - ARmedical',
        'seo_description' => null,
        'short_description' => 'To jest wyrób medyczny.',
        'description_html' => '<section><div class="post-content"><p>Opis produktu.</p></div></section>',
        'price_gross_amount' => null,
        'currency' => 'PLN',
        'availability' => 'unknown',
        'availability_label' => null,
        'stock_quantity' => null,
        'technical_specifications' => [
            ['label' => 'waga', 'value' => '2,4 kg'],
            ['label' => 'maksymalne obciążenie', 'value' => '120 kg'],
        ],
        'attributes' => [
            'waga' => '2,4 kg',
            'maksymalne obciążenie' => '120 kg',
        ],
        'size_options' => [],
        'images' => [[
            'url' => 'https://armedical.pl/wp-content/uploads/ar-023.jpg',
            'alt' => null,
            'is_primary' => true,
        ]],
        'documents' => [
            [
                'url' => 'https://armedical.pl/wp-content/uploads/ar-023-instrukcja.pdf',
                'label' => 'Instrukcja obsługi',
                'type' => 'manual',
            ],
            [
                'url' => 'https://armedical.pl/wp-content/uploads/ar-023.pdf',
                'label' => 'Dokumenty rejestrowe',
                'type' => 'registration',
            ],
        ],
        'is_medical_device' => true,
        'warnings' => [],
        'failed_urls' => [],
    ];
}
