<?php

declare(strict_types=1);

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Befado\BefadoProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function befadoProductFixture(): array
{
    return [
        'source' => 'befado',
        'source_url' => 'https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664',
        'canonical_url' => 'https://befado.pl/pl/p/BUTY-DZIECIECE-HONEY-BEFADO-/9664',
        'external_product_id' => '9664',
        'slug' => 'BUTY-DZIECIECE-HONEY-BEFADO-',
        'name' => 'BUTY DZIECIĘCE HONEY BEFADO',
        'seo_title' => 'BUTY DZIECIĘCE HONEY BEFADO - Befado.pl',
        'seo_description' => 'Sprawdzone buty na każdą stopę - Befado.',
        'short_description' => 'Sprawdzone buty na każdą stopę - Befado.',
        'brand' => ['name' => 'Befado', 'slug' => 'befado'],
        'price_gross_amount' => 49.90,
        'currency' => 'PLN',
        'availability' => 'in_stock',
        'availability_label' => 'W magazynie',
        'shipping_time' => '48 godzin',
        'sku' => '102X018',
        'source_category_path' => [
            'Befado.pl',
            'Befado',
            'Buty chłopięce',
            'Kapcie',
        ],
        'categories' => [
            'Buty chłopięce',
            'Kapcie',
        ],
        'category' => 'Kapcie',
        'description_html' => '<p>Wygodne dziecięce obuwie Befado.</p>',
        'description' => 'Wygodne dziecięce obuwie Befado.',
        'images' => [],
        'attributes' => [
            ['label' => 'Typ obuwia', 'value' => 'Slip-On'],
            ['label' => 'Kolor', 'value' => 'Różowy'],
            ['label' => 'Forma', 'value' => 'Honey'],
        ],
        'requires_variants' => true,
        'variants_unresolved' => false,
        'variant_candidates' => [
            [
                'external_variant_id' => 'option-502',
                'label' => 'Rozmiar: 24',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => '24'],
                    ['label' => 'Długość wkładki', 'value' => '15.5 cm'],
                ],
                'price_gross_amount' => 49.90,
                'currency' => 'PLN',
                'availability' => 'out_of_stock',
                'availability_label' => 'Niedostępny',
            ],
            [
                'external_variant_id' => 'option-501',
                'label' => 'Rozmiar: 23',
                'attributes' => [
                    ['label' => 'Rozmiar', 'value' => '23'],
                    ['label' => 'Długość wkładki', 'value' => '15.0 cm'],
                ],
                'price_gross_amount' => 49.90,
                'currency' => 'PLN',
                'availability' => 'in_stock',
                'availability_label' => 'Dostępny',
            ],
        ],
        'is_medical_device' => false,
    ];
}

it('imports Befado products idempotently as draft with per-size stock', function (): void {
    $importer = app(BefadoProductImporter::class);
    $fixture = befadoProductFixture();

    $root = Category::query()->create([
        'name' => 'Obuwie',
        'slug' => 'obuwie',
        'status' => CategoryStatus::ACTIVE,
    ]);

    Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Buty chłopięce',
        'slug' => 'buty-chlopiece',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $importer->import($fixture, ProductStatus::DRAFT, false);
    $result = $importer->import($fixture, ProductStatus::DRAFT, false);

    expect(Product::query()->where('external_source', 'befado')->count())->toBe(1)
        ->and(ProductVariant::query()->count())->toBe(2)
        ->and(Category::query()->whereNull('parent_id')->pluck('name')->all())
        ->toContain('Obuwie')
        ->not->toContain('Befado');

    $product = $result['product']->fresh(['variants.attributeValues.attribute', 'categories']);

    // Insole length must remain visible as product information,
    // but must not become a second selectable variant attribute.
    expect($product->description)
        ->toContain('Tabela rozmiarów i długości wkładki')
        ->toContain('<th>Rozmiar</th>')
        ->toContain('<th>Długość wkładki</th>')
        ->toContain('15.0 cm')
        ->toContain('15.5 cm');

    // Supplier SEO text must not leak into Ortezka metadata.
    expect($product->seo_title)
        ->toBe('BUTY DZIECIĘCE HONEY BEFADO – 102X018')
        ->not->toContain('Befado.pl')
        ->and($product->seo_description)
        ->toContain('Wygodne dziecięce obuwie Befado.')
        ->not->toContain('Sprawdzone buty na każdą stopę')
        ->and($product->short_description)
        ->toContain('Wygodne dziecięce obuwie Befado.')
        ->not->toContain('Sprawdzone buty na każdą stopę');

    expect($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->external_id)->toBe('9664')
        ->and($product->external_parent_sku)->toBe('102X018')
        ->and($product->variants)->toHaveCount(2);

    $variants = $product->variants->keyBy('external_variant_id');

    // BEFADO_SELECTABLE_VARIANTS:
    // Shoe size is selectable; insole length is informational only.
    foreach ($variants as $variant) {
        $attributes = $variant->attributeValues
            ->map(fn ($value) => $value->attribute->name)
            ->all();

        expect($attributes)->toBe(['Rozmiar']);
    }

    expect($variants['befado-9664-option-502']->attributeValues
        ->pluck('value')->all())->toBe(['24'])
        ->and($variants['befado-9664-option-501']->attributeValues
            ->pluck('value')->all())->toBe(['23']);

    expect($variants['befado-9664-option-502']->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($variants['befado-9664-option-502']->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($variants['befado-9664-option-502']->vat_rate)->toBe(VatRate::VAT_23)
        ->and($variants['befado-9664-option-502']->price_gross_amount)->toBe(4990)
        ->and($variants['befado-9664-option-502']->is_default)->toBeFalse()
        ->and($variants['befado-9664-option-501']->stock_status)->toBe(StockStatus::IN_STOCK)
        ->and($variants['befado-9664-option-501']->is_default)->toBeTrue();
});

it('rejects Befado imports when source variants are unresolved', function (): void {
    $fixture = befadoProductFixture();
    $fixture['variants_unresolved'] = true;
    $fixture['variant_candidates'] = [];

    expect(fn () => app(BefadoProductImporter::class)->import(
        $fixture,
        ProductStatus::DRAFT,
        false,
    ))->toThrow(
        InvalidArgumentException::class,
        'has unresolved source variants',
    );

    expect(Product::query()->where('external_source', 'befado')->count())->toBe(0);
});

it('removes copied ChatGPT interface styling while preserving product content', function (): void {
    $fixture = befadoProductFixture();

    $fixture['description_html'] = <<<'HTML'
<div class="resetcss">
    <h2 class="h3">Kapcie chłopięce Speedy Befado</h2>
    <div class="qMYqUG_convSearchResultHighlightRoot">
        <section class="text-token-text-primary w-full" dir="auto">
            <div class="agent-turn" data-testid="conversation-turn">
                <p>Wygodne kapcie dla dzieci.</p>
                <ul><li>Elastyczna podeszwa</li></ul>
            </div>
        </section>
    </div>
</div>
HTML;

    $root = Category::query()->create([
        'name' => 'Obuwie',
        'slug' => 'obuwie',
        'status' => CategoryStatus::ACTIVE,
    ]);

    Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Buty chłopięce',
        'slug' => 'buty-chlopiece',
        'status' => CategoryStatus::ACTIVE,
    ]);

    $result = app(BefadoProductImporter::class)->import(
        $fixture,
        ProductStatus::DRAFT,
        false,
    );

    $html = $result['product']->description;

    expect($html)
        ->toContain('Kapcie chłopięce Speedy Befado')
        ->toContain('Wygodne kapcie dla dzieci.')
        ->toContain('Elastyczna podeszwa')
        ->toContain('<ul>')
        ->not->toContain('qMYqUG_convSearchResultHighlightRoot')
        ->not->toContain('text-token-text-primary')
        ->not->toContain('agent-turn')
        ->not->toContain('data-testid')
        ->toContain('befado-product-meta');
});

function befadoImageTestCategories(): void
{
    $root = Category::query()->create([
        'name' => 'Obuwie',
        'slug' => 'obuwie',
        'status' => CategoryStatus::ACTIVE,
    ]);

    Category::query()->create([
        'parent_id' => $root->id,
        'name' => 'Buty chłopięce',
        'slug' => 'buty-chlopiece',
        'status' => CategoryStatus::ACTIVE,
    ]);
}

function befadoImageTestFixture(array $urls): array
{
    $fixture = befadoProductFixture();

    $fixture['images'] = array_map(
        fn (string $url): array => [
            'url' => $url,
            'alt' => 'Befado test image',
        ],
        $urls,
    );

    return $fixture;
}

function befadoImageTestPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQIHWP4z8DwHwAFgAI/ScL/nwAAAABJRU5ErkJggg==',
        true,
    );
}

it('imports a Befado gallery idempotently without duplicating images', function (): void {
    Storage::fake('public');
    befadoImageTestCategories();

    $url = 'https://befado.pl/userdata/public/gfx/1234/photo.png';

    Http::fake([
        '*' => Http::response(
            befadoImageTestPng(),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $importer = app(BefadoProductImporter::class);
    $fixture = befadoImageTestFixture([$url]);

    $first = $importer->import($fixture, ProductStatus::DRAFT, true);
    $firstId = $first['product']->images()->firstOrFail()->id;

    $second = $importer->import($fixture, ProductStatus::DRAFT, true);

    $images = $second['product']->images()->get();

    expect($images)->toHaveCount(1)
        ->and($images->first()->id)->toBe($firstId)
        ->and($images->first()->is_main)->toBeTrue()
        ->and($images->first()->mime_type)->toBe('image/png')
        ->and($second['product']->status)->toBe(ProductStatus::DRAFT);
});

it('preserves the existing gallery and product data when an image download fails', function (): void {
    Storage::fake('public');
    befadoImageTestCategories();

    $oldUrl = 'https://befado.pl/userdata/public/gfx/1234/old.png';
    $failedUrl = 'https://befado.pl/userdata/public/gfx/1235/missing.png';

    Http::fake([
        $oldUrl => Http::response(
            befadoImageTestPng(),
            200,
            ['Content-Type' => 'image/png'],
        ),
        $failedUrl => Http::response('', 404),
    ]);

    $importer = app(BefadoProductImporter::class);

    $original = $importer->import(
        befadoImageTestFixture([$oldUrl]),
        ProductStatus::DRAFT,
        true,
    )['product'];

    $originalImage = $original->images()->firstOrFail();

    $replacement = befadoImageTestFixture([$oldUrl, $failedUrl]);
    $replacement['name'] = 'THIS CHANGE MUST ROLL BACK';

    expect(fn () => $importer->import(
        $replacement,
        ProductStatus::DRAFT,
        true,
    ))->toThrow(RuntimeException::class);

    $product = $original->fresh();

    expect($product->name)->toBe('BUTY DZIECIĘCE HONEY BEFADO')
        ->and($product->images()->count())->toBe(1)
        ->and($product->images()->firstOrFail()->id)->toBe($originalImage->id)
        ->and($product->images()->firstOrFail()->source_url)->toBe($oldUrl)
        ->and(Storage::disk('public')->exists($originalImage->path))
        ->toBeTrue();
});

it('rejects duplicate Befado gallery content without removing existing images', function (): void {
    Storage::fake('public');
    befadoImageTestCategories();

    $firstUrl = 'https://befado.pl/userdata/public/gfx/1234/first.png';
    $secondUrl = 'https://befado.pl/userdata/public/gfx/1235/second.png';

    Http::fake([
        '*' => Http::response(
            befadoImageTestPng(),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $importer = app(BefadoProductImporter::class);

    $original = $importer->import(
        befadoImageTestFixture([$firstUrl]),
        ProductStatus::DRAFT,
        true,
    )['product'];

    $originalImageId = $original->images()->firstOrFail()->id;

    expect(fn () => $importer->import(
        befadoImageTestFixture([$firstUrl, $secondUrl]),
        ProductStatus::DRAFT,
        true,
    ))->toThrow(RuntimeException::class, 'Duplicate Befado gallery content');

    expect($original->fresh()->images()->count())->toBe(1)
        ->and($original->fresh()->images()->firstOrFail()->id)
        ->toBe($originalImageId);
});

it('generates distinct Befado SEO titles using SKU prefixes', function (): void {
    befadoImageTestCategories();

    $first = befadoProductFixture();
    $first['external_product_id'] = '9664';
    $first['sku'] = '102X01812300A01';

    $second = befadoProductFixture();
    $second['external_product_id'] = '9665';
    $second['sku'] = '102X01912300A01';

    $importer = app(BefadoProductImporter::class);

    $firstProduct = $importer->import(
        $first,
        ProductStatus::DRAFT,
        false,
    )['product'];

    $secondProduct = $importer->import(
        $second,
        ProductStatus::DRAFT,
        false,
    )['product'];

    expect($firstProduct->seo_title)
        ->toBe('BUTY DZIECIĘCE HONEY BEFADO – 102X018')
        ->and($secondProduct->seo_title)
        ->toBe('BUTY DZIECIĘCE HONEY BEFADO – 102X019')
        ->and($firstProduct->seo_title)
        ->not->toBe($secondProduct->seo_title);
});

it('does not duplicate an SKU already present in a Befado SEO title', function (): void {
    befadoImageTestCategories();

    $fixture = befadoProductFixture();
    $fixture['name'] = 'Półbuty dziecięce Rock Befado 169P036';
    $fixture['sku'] = '169P036';

    $product = app(BefadoProductImporter::class)->import(
        $fixture,
        ProductStatus::DRAFT,
        false,
    )['product'];

    expect($product->seo_title)
        ->toBe('Półbuty dziecięce Rock Befado 169P036')
        ->and(substr_count($product->seo_title, '169P036'))
        ->toBe(1);
});
