<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Models\Attribute;
use App\Models\Product;
use App\Services\Wojdak\WojdakProductImporter;
use App\Services\Wojdak\WojdakProductNormalizer;
use App\Services\Wojdak\WojdakProductPayloadExtractor;
use App\Services\Wojdak\WojdakVariantBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('extracts Wojdak product payload from a clothing product page', function (): void {
    $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakFemaleBlouseHtml(), 'https://wojdak.pl/product/bluza-2002/');

    expect($payload['external_id'])->toBe('bluza-2002')
        ->and($payload['name'])->toBe('Bluza 2002')
        ->and($payload['canonical_url'])->toBe('https://wojdak.pl/product/bluza-2002/')
        ->and($payload['category_url'])->toBe('https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/')
        ->and($payload['category_slug'])->toBe('bluzy-damskie')
        ->and($payload['size_table_pdf_url'])->toBe('https://wojdak.pl/wp-content/uploads/2023/05/Tabela-rozmiarow-odziez.pdf')
        ->and($payload['size_table_type'])->toBe('clothing')
        ->and($payload['images'])->toBe([
            'https://wojdak.pl/wp-content/uploads/2023/05/2002-scaled.jpg',
            'https://wojdak.pl/wp-content/uploads/2023/05/bluza2002.png',
        ]);
});

it('builds female clothing variants from Wojdak clothing size table and short sleeve height groups', function (): void {
    $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakFemaleBlouseHtml(), 'https://wojdak.pl/product/bluza-2002/');
    $result = app(WojdakVariantBuilder::class)->build($payload);

    expect($result['warnings'])->toBe([])
        ->and($result['variants'])->toHaveCount(24)
        ->and($result['variants'][0]['sku'])->toBe('WOJDAK-BLUZA-2002-34-158-164')
        ->and(wojdakVariantAttributeSummary($result['variants'][0]['attributes']))->toBe([
            ['code' => 'size', 'name' => 'Rozmiar', 'value' => '34'],
            ['code' => 'height', 'name' => 'Wzrost', 'value' => '158/164'],
        ])
        ->and($result['variants'][1]['sku'])->toBe('WOJDAK-BLUZA-2002-34-170-176');
});

it('builds footwear variants from the Wojdak shoe size table intersected with product page size range', function (): void {
    $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakFemaleShoeHtml(), 'https://wojdak.pl/product/bw162/');
    $result = app(WojdakVariantBuilder::class)->build($payload);

    expect($result['warnings'])->toBe([])
        ->and($result['variants'])->toHaveCount(7)
        ->and($result['variants'][0]['sku'])->toBe('WOJDAK-BW162-36')
        ->and(wojdakVariantAttributeSummary($result['variants'][0]['attributes']))->toBe([
            ['code' => 'size', 'name' => 'Rozmiar', 'value' => '36'],
        ])
        ->and(collect($result['variants'])->pluck('sku')->all())->toBe([
            'WOJDAK-BW162-36',
            'WOJDAK-BW162-37',
            'WOJDAK-BW162-38',
            'WOJDAK-BW162-39',
            'WOJDAK-BW162-40',
            'WOJDAK-BW162-41',
            'WOJDAK-BW162-42',
        ]);
});

it('falls back to config file when the Wojdak config key is missing from the cached configuration repository', function (): void {
    $originalConfig = Config::get('wojdak');

    try {
        Config::set('wojdak', []);

        $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakFemaleBlouseHtml(), 'https://wojdak.pl/product/bluza-2002/');
        $result = app(WojdakVariantBuilder::class)->build($payload);

        expect($result['warnings'])->toBe([])
            ->and($result['variants'])->toHaveCount(24);
    } finally {
        Config::set('wojdak', $originalConfig);
    }
});

it('imports a Wojdak product as draft with generated size variants, attributes, category and images', function (): void {
    Storage::fake('public');

    Http::fake([
        'https://wojdak.pl/wp-content/uploads/2023/05/2002-scaled.jpg' => Http::response('fake-jpeg-one', 200, ['Content-Type' => 'image/jpeg']),
        'https://wojdak.pl/wp-content/uploads/2023/05/bluza2002.png' => Http::response('fake-png-two', 200, ['Content-Type' => 'image/png']),
        '*' => Http::response('', 404),
    ]);

    $payload = app(WojdakProductPayloadExtractor::class)->extract(wojdakFemaleBlouseHtml(), 'https://wojdak.pl/product/bluza-2002/');
    $normalized = app(WojdakProductNormalizer::class)->normalize($payload);
    $product = app(WojdakProductImporter::class)->import($normalized);

    expect($product)->toBeInstanceOf(Product::class)
        ->and($product->external_source)->toBe('wojdak')
        ->and($product->external_id)->toBe('bluza-2002')
        ->and($product->status)->toBe(ProductStatus::DRAFT)
        ->and($product->variants)->toHaveCount(24)
        ->and($product->images)->toHaveCount(2)
        ->and($product->categories)->toHaveCount(1)
        ->and($product->categories->first()->slug)->toBe('bluzy-damskie');

    $firstVariant = $product->variants->first();

    expect($firstVariant->sku)->toBe('WOJDAK-BLUZA-2002-34-158-164')
        ->and($firstVariant->status)->toBe(ProductVariantStatus::DRAFT)
        ->and($firstVariant->stock_status)->toBe(StockStatus::OUT_OF_STOCK)
        ->and($firstVariant->price_net_amount)->toBeNull()
        ->and($firstVariant->attributeValues->pluck('value')->all())->toBe(['34', '158/164']);

    expect(Attribute::query()->where('external_attribute_id', 'wojdak-size')->exists())->toBeTrue()
        ->and(Attribute::query()->where('external_attribute_id', 'wojdak-height')->exists())->toBeTrue();
});

/**
 * @param  array<int, array<string, mixed>>  $attributes
 * @return array<int, array{code:string, name:string, value:string}>
 */
function wojdakVariantAttributeSummary(array $attributes): array
{
    return collect($attributes)
        ->map(fn (array $attribute): array => [
            'code' => (string) $attribute['code'],
            'name' => (string) $attribute['name'],
            'value' => (string) $attribute['value'],
        ])
        ->values()
        ->all();
}

function wojdakFemaleBlouseHtml(): string
{
    return <<<'HTML'
        <!DOCTYPE html>
        <html lang="pl-PL">
        <head>
            <title>Bluza 2002 - Wojdak</title>
            <link rel="canonical" href="https://wojdak.pl/product/bluza-2002/" />
            <meta property="og:title" content="Bluza 2002 - Wojdak" />
        </head>
        <body>
            <main>
                <div class="container container--medium">
                    <ul class="ms-breadcrumbs">
                        <li><a href="https://wojdak.pl/produkty/odziez-medyczna-damska/bluzy-damskie/">« Powrót do listy kategorii</a></li>
                    </ul>
                </div>
                <div class="single-product__gallery">
                    <a class="swiper-slide" href="https://wojdak.pl/wp-content/uploads/2023/05/2002-scaled.jpg">
                        <img src="https://wojdak.pl/wp-content/uploads/2023/05/2002-270x352.jpg.webp" alt="">
                    </a>
                    <a class="swiper-slide" href="https://wojdak.pl/wp-content/uploads/2023/05/bluza2002.png">
                        <img src="https://wojdak.pl/wp-content/uploads/2023/05/bluza2002-270x352.png.webp" alt="">
                    </a>
                </div>
                <div class="single-product__about">
                    <h1 class="single-product__about-title">Bluza 2002</h1>
                    <div class="single-product__about-text">
                        <p><strong>bluza chirurgiczna</strong><br>Ten model dostępny jest z krótkim rękawem.</p>
                        <p>Odzież dostępna w tkaninach: Microstretch, Rayon, Rofinor, Charlotte, Teredo i Flex we wszystkich rozmiarach.</p>
                    </div>
                    <a class="button" href="https://wojdak.pl/wp-content/uploads/2023/05/Tabela-rozmiarow-odziez.pdf" target="_blank">Zobacz tabelę rozmiarową</a>
                </div>
            </main>
        </body>
        </html>
    HTML;
}

function wojdakFemaleShoeHtml(): string
{
    return <<<'HTML'
        <!DOCTYPE html>
        <html lang="pl-PL">
        <head>
            <title>BW162 - Wojdak</title>
            <link rel="canonical" href="https://wojdak.pl/product/bw162/" />
        </head>
        <body>
            <main>
                <ul class="ms-breadcrumbs">
                    <li><a href="https://wojdak.pl/produkty/obuwie-damskie/">« Powrót do listy kategorii</a></li>
                </ul>
                <div class="single-product__gallery">
                    <a class="swiper-slide" href="https://wojdak.pl/wp-content/uploads/2023/05/bw-162.png">
                        <img src="https://wojdak.pl/wp-content/uploads/2023/05/bw-162.png.webp" alt="">
                    </a>
                </div>
                <div class="single-product__about">
                    <h1 class="single-product__about-title">BW162</h1>
                    <div class="single-product__about-text">
                        <p>Eleganckie obuwie damskie wykonane z naturalnej skóry. Model ten jest dostępny w szerokim zakresie rozmiarów od 36 do 42.</p>
                    </div>
                    <a class="button" href="https://wojdak.pl/wp-content/uploads/2023/05/Tabela-rozmiarow-obuwie.pdf" target="_blank">Zobacz tabelę rozmiarową</a>
                </div>
            </main>
        </body>
        </html>
    HTML;
}
