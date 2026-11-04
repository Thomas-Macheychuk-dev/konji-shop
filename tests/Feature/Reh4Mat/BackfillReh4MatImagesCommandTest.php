<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const REH4MAT_BACKFILL_FIXTURE = 'scrapers/reh4mat/test-backfill-images.json';

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');

    writeReh4MatBackfillFixture([
        [
            'external_product_id' => 'am-sb-11',
            'canonical_url' => 'https://www.reh4mat.com/produkt/biodro/am-sb-11/',
            'images' => [
                ['url' => 'https://www.reh4mat.com/media/k2/items/cache/aaa_XL.jpg', 'alt' => 'Orteza AM-SB-11'],
                ['url' => 'https://www.reh4mat.com/media/k2/items/cache/bbb_XL.jpg', 'alt' => ''],
                ['url' => 'https://www.reh4mat.com/media/k2/items/cache/aaa_XL.jpg', 'alt' => 'duplicate'],
            ],
        ],
        [
            'external_product_id' => 'pokrowiec-elastyczny',
            'canonical_url' => 'https://www.reh4mat.com/produkt/stabilizacja-akcesoria/pokrowiec-elastyczny/',
            'images' => [],
        ],
        [
            'external_product_id' => '3692',
            'images' => [['url' => 'https://www.reh4mat.com/uploads/2026/05/has-images.jpg']],
        ],
    ]);

    $this->withoutImages = reh4matBackfillProduct('am-sb-11', 'AM-SB-11 orteza stawu biodrowego');
    $this->noUrls = reh4matBackfillProduct('pokrowiec-elastyczny', 'Pokrowiec elastyczny');
    $this->withImages = reh4matBackfillProduct('3692', 'Orteza z obrazem');
    $this->withImages->images()->create(['disk' => 'public', 'path' => 'products/reh4mat/3692/gallery/existing.jpg', 'is_main' => true, 'sort_order' => 0]);
});

afterEach(function (): void {
    @unlink(storage_path('app/'.REH4MAT_BACKFILL_FIXTURE));
});

it('previews moved StabiloBed image URLs without downloading or writing', function (): void {
    Http::fake();

    $this->artisan('reh4mat:backfill-images', ['--from' => REH4MAT_BACKFILL_FIXTURE])
        ->expectsOutputToContain('PRODUCTS_WITHOUT_IMAGES=2')
        ->expectsOutputToContain('WITH_IMAGE_URLS=1')
        ->expectsOutputToContain('IMAGE_URLS=2')
        ->expectsOutputToContain('HOST moved: stabilobedsystem.pl, then flex-point.pl=2')
        ->expectsOutputToContain($this->withoutImages->id.' am-sb-11 <- https://stabilobedsystem.pl/media/k2/items/cache/aaa_XL.jpg')
        ->expectsOutputToContain('NO_IMAGE_URLS '.$this->noUrls->id.' pokrowiec-elastyczny <- https://www.reh4mat.com/produkt/stabilizacja-akcesoria/pokrowiec-elastyczny/')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    Http::assertNothingSent();
    expect(ProductImage::query()->count())->toBe(1);
});

it('adds images from the moved host to products without images and changes nothing else', function (): void {
    Http::fake([
        'https://stabilobedsystem.pl/media/k2/items/cache/aaa_XL.jpg' => Http::response(reh4matBackfillImage(200, 0, 0), 200, ['Content-Type' => 'image/png']),
        'https://stabilobedsystem.pl/media/k2/items/cache/bbb_XL.jpg' => Http::response(reh4matBackfillImage(0, 200, 0), 200, ['Content-Type' => 'image/png']),
        '*' => Http::response('', 404),
    ]);
    $before = $this->withoutImages->fresh()->only(['name', 'slug', 'status', 'seo_title', 'description', 'updated_at']);

    $this->artisan('reh4mat:backfill-images', ['--from' => REH4MAT_BACKFILL_FIXTURE, '--write' => true])
        ->expectsOutputToContain('PRODUCTS_GIVEN_IMAGES=1 OF 1')
        ->expectsOutputToContain('IMAGES_ADDED=2')
        ->expectsOutputToContain('REH4MAT_IMAGE_BACKFILL=PASS')
        ->assertSuccessful();

    $images = $this->withoutImages->images()->orderBy('sort_order')->get();

    expect($images)->toHaveCount(2)
        ->and($images[0]->is_main)->toBeTrue()
        ->and($images[1]->is_main)->toBeFalse()
        ->and($images[0]->alt_text)->toBe('Orteza AM-SB-11')
        ->and($images[1]->alt_text)->toBe('AM-SB-11 orteza stawu biodrowego')
        ->and($images[0]->source_url)->toBe('https://stabilobedsystem.pl/media/k2/items/cache/aaa_XL.jpg')
        ->and($images[0]->path)->toStartWith('products/reh4mat/am-sb-11/gallery/')
        ->and($this->withoutImages->fresh()->only(['name', 'slug', 'status', 'seo_title', 'description', 'updated_at']))->toEqual($before)
        ->and($this->withImages->images()->pluck('path')->all())->toBe(['products/reh4mat/3692/gallery/existing.jpg'])
        ->and($this->noUrls->images()->count())->toBe(0);

    Storage::disk('public')->assertExists($images[0]->path);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'www.reh4mat.com'));

    $this->artisan('reh4mat:backfill-images', ['--from' => REH4MAT_BACKFILL_FIXTURE, '--write' => true])
        ->expectsOutputToContain('NOTHING_TO_WRITE')
        ->assertSuccessful();

    expect(ProductImage::query()->count())->toBe(3);
});

it('falls back to flex-point.pl for moved files that stabilobedsystem.pl does not serve', function (): void {
    writeReh4MatBackfillFixture([[
        'external_product_id' => 'am-sb-11',
        'images' => [
            ['url' => 'https://www.reh4mat.com/media/k2/items/cache/fp_L.jpg', 'alt' => 'Kamizelka FP-02'],
            ['url' => 'https://www.reh4mat.com/images/produkty/FP-40/dwa-pasy.jpg'],
        ],
    ]]);
    Http::fake([
        'https://flex-point.pl/media/k2/items/cache/fp_L.jpg' => Http::response(reh4matBackfillImage(1, 2, 3), 200, ['Content-Type' => 'image/png']),
        'https://flex-point.pl/images/produkty/FP-40/dwa-pasy.jpg' => Http::response(reh4matBackfillImage(4, 5, 6), 200, ['Content-Type' => 'image/png']),
        '*' => Http::response('', 404),
    ]);

    $this->artisan('reh4mat:backfill-images', ['--from' => REH4MAT_BACKFILL_FIXTURE, '--write' => true])
        ->expectsOutputToContain('IMAGES_ADDED=2')
        ->expectsOutputToContain('IMAGE_URLS_FAILED=0')
        ->assertSuccessful();

    expect($this->withoutImages->images()->orderBy('sort_order')->pluck('source_url')->all())->toBe([
        'https://flex-point.pl/media/k2/items/cache/fp_L.jpg',
        'https://flex-point.pl/images/produkty/FP-40/dwa-pasy.jpg',
    ]);
    Http::assertSent(fn ($request) => $request->url() === 'https://stabilobedsystem.pl/media/k2/items/cache/fp_L.jpg');
});

it('leaves a product untouched when none of its images download', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    $this->artisan('reh4mat:backfill-images', ['--from' => REH4MAT_BACKFILL_FIXTURE, '--write' => true])
        ->expectsOutputToContain('PRODUCTS_GIVEN_IMAGES=0 OF 1')
        ->expectsOutputToContain('IMAGE_URLS_FAILED=2')
        ->assertSuccessful();

    expect(ProductImage::query()->count())->toBe(1);
});

it('rolls back only the images it added', function (): void {
    Http::fake([
        'https://stabilobedsystem.pl/*' => Http::response(reh4matBackfillImage(10, 20, 30), 200, ['Content-Type' => 'image/png']),
    ]);

    $this->artisan('reh4mat:backfill-images', ['--from' => REH4MAT_BACKFILL_FIXTURE, '--write' => true, '--image-limit' => 1])
        ->expectsOutputToContain('IMAGES_ADDED=1')
        ->assertSuccessful();

    $backup = Storage::disk('local')->files('catalogue-repairs')[0];
    $added = $this->withoutImages->images()->firstOrFail();

    $this->artisan('reh4mat:backfill-images', ['--rollback' => $backup])
        ->expectsOutputToContain('REMOVED_IMAGES=1 OF 1')
        ->expectsOutputToContain('FILES_DELETED=1')
        ->assertSuccessful();

    Storage::disk('public')->assertMissing($added->path);
    expect($this->withoutImages->images()->count())->toBe(0)
        ->and($this->withImages->images()->count())->toBe(1);
});

/**
 * @param  list<array<string, mixed>>  $products
 */
function writeReh4MatBackfillFixture(array $products): void
{
    $path = storage_path('app/'.REH4MAT_BACKFILL_FIXTURE);

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0755, true);
    }

    file_put_contents($path, json_encode(['source' => 'reh4mat', 'products' => $products], JSON_THROW_ON_ERROR));
}

function reh4matBackfillProduct(string $externalId, string $name): Product
{
    return Product::query()->create([
        'external_source' => 'reh4mat',
        'external_id' => $externalId,
        'name' => $name,
        'slug' => $externalId,
        'seo_title' => $name.' | Reh4Mat',
        'description' => '<p>'.$name.'</p>',
        'status' => ProductStatus::ACTIVE,
    ]);
}

function reh4matBackfillImage(int $red, int $green, int $blue): string
{
    $image = imagecreatetruecolor(1, 1);
    imagefill($image, 0, 0, imagecolorallocate($image, $red, $green, $blue));

    ob_start();
    imagepng($image);
    $contents = (string) ob_get_clean();
    imagedestroy($image);

    return $contents;
}
