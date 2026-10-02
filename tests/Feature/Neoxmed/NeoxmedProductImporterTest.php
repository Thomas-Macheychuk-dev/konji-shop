<?php

declare(strict_types=1);

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Images\RemoteImageImporter;
use App\Services\Neoxmed\NeoxmedPricedMapBuilder;
use App\Services\Neoxmed\NeoxmedProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Category::query()->create([
        'name' => 'Stabilizatory ortopedyczne',
        'slug' => 'stabilizatory-ortopedyczne',
        'status' => CategoryStatus::ACTIVE,
    ]);
});

function neoxmedImporterFixture(): array
{
    $mapped = [
        'source' => 'neoxmed',
        'product' => [
            'external_source' => 'neoxmed',
            'external_id' => 'B-01',
            'external_parent_sku' => 'NEOX-B-01',
            'name' => 'Kamizelka stawu barkowego',
            'slug' => 'neox-b-01-kamizelka-stawu-barkowego',
            'status' => 'draft',
            'short_description_html' => '<p>Opis produktu</p>',
            'description_html' => '<p>Opis pełny</p>',
            'seo_title' => 'Kamizelka stawu barkowego',
            'seo_description' => 'Opis produktu',
            'brand' => 'Neox',
        ],
        'categories' => [['target_slug' => 'stabilizatory-ortopedyczne']],
        'medical_device' => ['is_medical_device' => true],
        'nfz' => ['codes' => ['J.06.01.00']],
        'sizing' => [
            'size_note' => 'Tabela rozmiarów w galerii',
            'variant_generation_allowed' => false,
            'size_chart_images' => [],
        ],
        'availability' => ['planned_stock_status' => 'out_of_stock'],
        'pricing' => [
            'net_minor' => 10000,
            'gross_minor' => 10800,
            'vat_rate' => 8,
            'currency' => 'PLN',
        ],
        'variants' => [[
            'sku' => 'NEOX-B-01',
            'external_variant_id' => 'neoxmed-B-01-default',
            'status' => 'draft',
            'is_default' => true,
            'stock_status' => 'out_of_stock',
            'price_net_minor' => 10000,
            'price_gross_minor' => 10800,
            'vat_rate' => 8,
            'currency' => 'PLN',
        ]],
        'images' => [[
            'source_url' => 'https://neoxmed.com/wp-content/uploads/B-01.jpg',
            'role' => 'product',
            'alt' => 'Kamizelka',
        ]],
    ];

    return [
        'source' => 'neoxmed',
        'mode' => 'priced_import_mapping_dry_run',
        'ready_for_database_write' => true,
        'source_product_count' => 1,
        'mapped_product_count' => 1,
        'errors' => [],
        'blocking_review_items' => [],
        'products' => [$mapped],
    ];
}

it('rejects incomplete priced map without persisting anything', function (): void {
    $map = neoxmedImporterFixture();
    $map['ready_for_database_write'] = false;
    $result = app(NeoxmedProductImporter::class)->preflight($map);

    expect($result['safe'])->toBeFalse()
        ->and($result['errors'])->not->toBe([])
        ->and(Product::query()->count())->toBe(0);
});

it('rejects tampered price / VAT arithmetic', function (): void {
    $map = neoxmedImporterFixture();
    $map['products'][0]['pricing']['gross_minor'] = 10799;

    $result = app(NeoxmedProductImporter::class)->preflight($map);
    expect($result['safe'])->toBeFalse()
        ->and(implode(' ', $result['errors']))->toContain('pricing');
});

it('imports a safe draft with one placeholder, taxonomy, brand and NFZ without inferring stock', function (): void {
    $importer = app(NeoxmedProductImporter::class);
    $map = neoxmedImporterFixture();

    expect($importer->preflight($map)['safe'])->toBeTrue();
    $first = $importer->import($map, false);
    $second = $importer->import($map, false);
    $product = Product::query()->where('external_source', 'neoxmed')->firstOrFail();
    $variant = $product->variants()->firstOrFail();

    expect($first)->toMatchArray(['selected' => 1, 'created' => 1, 'updated' => 0, 'images' => 0])
        ->and($second)->toMatchArray(['selected' => 1, 'created' => 0, 'updated' => 1, 'images' => 0])
        ->and(Product::query()->where('external_source', 'neoxmed')->count())->toBe(1)
        ->and(ProductVariant::query()->where('product_id', $product->id)->count())->toBe(1)
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->published_at)->toBeNull()
        ->and($variant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variant->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($variant->price_net_amount)->toBe(10000)
        ->and($variant->price_gross_amount)->toBe(10800)
        ->and($product->categories()->firstOrFail()->slug)->toBe('stabilizatory-ortopedyczne')
        ->and($product->attributeValues()->count())->toBe(3);
});

it('refuses to overwrite a manually activated product', function (): void {
    $importer = app(NeoxmedProductImporter::class);
    $map = neoxmedImporterFixture();
    $importer->import($map, false);
    Product::query()->where('external_source', 'neoxmed')->firstOrFail()->update([
        'status' => ProductStatus::ACTIVE,
    ]);

    expect($importer->preflight($map)['safe'])->toBeFalse();
    expect(fn () => $importer->import($map, false))->toThrow(InvalidArgumentException::class);
});

it('fails closed when priced-map contents differ from their exact replayed approvals', function (): void {
    $map = neoxmedImporterFixture();
    $structuralProduct = $map['products'][0];
    $structuralProduct['pricing']['net_minor'] = null;
    $structuralProduct['pricing']['gross_minor'] = null;
    $structuralProduct['pricing']['vat_rate'] = null;
    $structuralProduct['variants'][0]['price_net_minor'] = null;
    $structuralProduct['variants'][0]['price_gross_minor'] = null;
    $structuralProduct['variants'][0]['vat_rate'] = null;

    $structural = [
        'source' => 'neoxmed',
        'mapping_structurally_valid' => true,
        'database_audit' => ['safe_for_future_import_implementation' => true, 'errors' => []],
        'products' => [$structuralProduct],
    ];
    $builder = app(NeoxmedPricedMapBuilder::class);
    $rawMap = json_encode($structural, JSON_THROW_ON_ERROR);
    $approval = $builder->buildApprovalTemplate($structural, hash('sha256', $rawMap));
    $approval['products'][0]['net_amount_pln'] = '100.00';
    $approval['products'][0]['gross_amount_pln'] = '108.00';
    $approval['products'][0]['vat_rate'] = 8;
    $approval['approval_reference'] = 'TEST-ONLY';
    $approval['approved_by'] = 'Synthetic fixture';
    $approval['approved_at'] = '2026-10-02T12:00:00+02:00';
    $rawApproval = json_encode($approval, JSON_THROW_ON_ERROR);
    $priced = $builder->build($structural, hash('sha256', $rawMap), $approval, hash('sha256', $rawApproval));
    expect($priced['ready_for_database_write'])->toBeTrue();

    $dir = 'scrapers/neoxmed/tests/importer-'.uniqid('', true);
    $path = storage_path('app/'.$dir);
    File::ensureDirectoryExists($path);
    try {
        file_put_contents($path.'/map.json', $rawMap);
        file_put_contents($path.'/approvals.json', $rawApproval);
        $priced['products'][0]['pricing']['gross_minor'] = 99999; // Unapproved tampering
        file_put_contents($path.'/priced.json', json_encode($priced, JSON_THROW_ON_ERROR));
        $exit = Artisan::call('neoxmed:import-products', [
            '--import-map' => $dir.'/map.json',
            '--approvals' => $dir.'/approvals.json',
            '--from' => $dir.'/priced.json',
            '--write' => true,
            '--no-images' => true,
        ]);
        expect($exit)->toBe(1)
            ->and(Product::query()->count())->toBe(0);
    } finally {
        File::deleteDirectory($path);
    }
});

it('imports normal and size-chart images as distinct gallery records without publishing', function (): void {
    $map = neoxmedImporterFixture();
    $map['products'][0]['sizing']['size_chart_images'] = [[
        'source_url' => 'https://neoxmed.com/wp-content/uploads/B-01-size.jpg',
        'role' => 'size_chart',
        'alt' => 'Rozmiary B-01',
    ]];
    $remote = Mockery::mock(RemoteImageImporter::class);
    $remote->shouldReceive('import')->twice()->andReturnUsing(
        static function (string $url, string $directory, string $disk, array $hosts): array {
            expect($disk)->toBe('public')
                ->and($directory)->toContain('products/neoxmed/b-01/gallery')
                ->and($hosts)->toBe(['neoxmed.com']);
            $hash = hash('sha256', $url);

            return [
                'disk' => 'public',
                'path' => $directory.'/'.$hash.'.jpg',
                'source_url' => $url,
                'mime_type' => 'image/jpeg',
                'file_size' => 128,
                'sha256' => $hash,
            ];
        },
    );
    app()->instance(RemoteImageImporter::class, $remote);
    $importer = app(NeoxmedProductImporter::class);
    expect($importer->preflight($map)['safe'])->toBeTrue();

    $result = $importer->import($map);
    $product = Product::query()->where('external_source', 'neoxmed')->firstOrFail();
    $images = $product->images()->get();

    expect($result['images'])->toBe(2)
        ->and($images)->toHaveCount(2)
        ->and($images[0]->is_main)->toBeTrue()
        ->and($images[1]->is_main)->toBeFalse()
        ->and($images[1]->alt_text)->toBe('Rozmiary B-01')
        ->and($product->default_image_id)->toBe($images[0]->id)
        ->and($product->status)->toBe(ProductStatus::DRAFT);
});
