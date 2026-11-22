<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\RemoteImageImporter;
use App\Services\Peruka\PerukaPhotoOrder;
use App\Services\Peruka\PerukaProductImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const WIG_MEDIA = 'https://www.peruka.pl/media/products/028c0b0c56b48b548584ed6b3066b843/images/';

it('tells peruka.pl colour samples from model photos and charts', function (): void {
    foreach (['15-23-6R.jpg', '61.jpg', '2.jpg', '6-8-4R-hh.jpg', '10-14-80-A.jpg', '830-6-4.jpg', 'PER001757-52732.jpg', 'Hai001468-69110.jpg', 'large_26-27-19-8R.jpg'] as $file) {
        expect(PerukaPhotoOrder::isColourSample(WIG_MEDIA.$file))->toBeTrue($file);
    }

    foreach (['Pudding-01.jpeg', 'JELLY-8-14-6R-front-900x900.jpg', 'Hair-Up-Calata-15-23-6R-900x900.jpg', 'ELL000421-17677.jpg', 'turn-mono-part-hotmocca-rooted-bs.jpg', 'baza-tresy.JPG', '-dlugosci.webp'] as $file) {
        expect(PerukaPhotoOrder::isColourSample(WIG_MEDIA.$file))->toBeFalse($file);
    }

    expect(PerukaPhotoOrder::isChart(WIG_MEDIA.'baza-tresy-przedzialek-linia.JPG'))->toBeTrue()
        ->and(PerukaPhotoOrder::isChart(WIG_MEDIA.'-dlugosci.webp'))->toBeTrue()
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'PER001442-37205.jpg', WIG_MEDIA.'baza-tresy.JPG', WIG_MEDIA.'Eclair-01.jpg', WIG_MEDIA.'Eclair-02.jpeg']))->toBe([2, 0, 1, 3])
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'Eclair-01.jpg', WIG_MEDIA.'PER001442-37205.jpg']))->toBe([0, 1])
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'PER001442-37205.jpg', WIG_MEDIA.'-dlugosci.webp']))->toBe([0, 1]);
});

it('treats gallery photos with stock numbers as wig photos', function (): void {
    // Bachata 23/66/8R on peruka.pl: the main photo is the colour sample, the gallery shows the wig.
    expect(PerukaPhotoOrder::order([WIG_MEDIA.'Hai001421-68529.jpg', WIG_MEDIA.'Hai001421-68241.jpg', WIG_MEDIA.'Hai001421-68242.jpg']))->toBe([1, 0, 2])
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'14-16.jpg', WIG_MEDIA.'Hai001358-68796.jpg']))->toBe([1, 0])
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'PER001819-52674.jpg', WIG_MEDIA.'26-27-19-8R.jpg', WIG_MEDIA.'baza-tresy.JPG', WIG_MEDIA.'PER001819-52009.jpg']))->toBe([3, 0, 1, 2])
        // Diana, Identica and Nr 377: stock numbers without a prefix.
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'002095-17622.jpg', WIG_MEDIA.'baza-tresy.JPG', WIG_MEDIA.'002095-17621.jpeg']))->toBe([2, 0, 1])
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'002300-38054.jpg', WIG_MEDIA.'002300-38026.jpg']))->toBe([1, 0])
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'0277-17386.jpg', WIG_MEDIA.'0277-17385.jpeg']))->toBe([1, 0])
        ->and(PerukaPhotoOrder::order([WIG_MEDIA.'1.jpg', WIG_MEDIA.'baza-tresy.JPG', WIG_MEDIA.'000935-4092.jpeg']))->toBe([2, 0, 1])
        ->and(PerukaPhotoOrder::isColourCode(WIG_MEDIA.'830-131-14.jpg'))->toBeTrue()
        ->and(PerukaPhotoOrder::isColourCode(WIG_MEDIA.'133-130.jpg'))->toBeTrue()
        ->and(PerukaPhotoOrder::isColourCode(WIG_MEDIA.'000124-835.jpeg'))->toBeFalse()
        // Bachata after the reorder: the wig photo in front has a stock number too; the sample is second.
        ->and(PerukaPhotoOrder::colourSampleIndex([WIG_MEDIA.'Hai001421-68241.jpg', WIG_MEDIA.'Hai001421-68529.jpg']))->toBe(1)
        // Delmar hair piece: only a sample and the base photo, not reordered.
        ->and(PerukaPhotoOrder::colourSampleIndex([WIG_MEDIA.'6-8-30.jpg', WIG_MEDIA.'delmar-baza-23-32.jpeg']))->toBe(0)
        ->and(PerukaPhotoOrder::colourSampleIndex([WIG_MEDIA.'Eclair-01.jpg', WIG_MEDIA.'PER001442-37205.jpg']))->toBe(1)
        ->and(PerukaPhotoOrder::colourSampleIndex([WIG_MEDIA.'Eclair-01.jpg', WIG_MEDIA.'Eclair-02.jpg', WIG_MEDIA.'2.jpg']))->toBeNull();
});

it('puts the model photo before the colour sample when importing a wig', function (): void {
    $remote = Mockery::mock(RemoteImageImporter::class);
    $remote->shouldReceive('import')->andReturnUsing(static fn (string $url, string $directory): array => [
        'disk' => 'public',
        'path' => $directory.'/'.basename($url),
        'source_url' => $url,
        'mime_type' => 'image/jpeg',
        'file_size' => 128,
        'sha256' => hash('sha256', $url),
    ]);
    app()->instance(RemoteImageImporter::class, $remote);

    $product = app(PerukaProductImporter::class)->import([
        'external_product_id' => '15545',
        'slug' => 'eclair-10-14-80-a-peruka-syntetyczna',
        'name' => 'Peruka z włosów syntetycznych wielotonowy blond ECLAIR 10/14/80-A',
        'price_gross_amount' => 1034.0,
        'images' => [
            ['url' => WIG_MEDIA.'PER001442-37205.jpg', 'alt' => null],
            ['url' => WIG_MEDIA.'Eclair-01.jpg', 'alt' => null],
            ['url' => WIG_MEDIA.'baza-tresy-przedzialek-linia.JPG', 'alt' => null],
        ],
    ], ProductStatus::ACTIVE)['product'];

    $images = $product->images()->orderBy('sort_order')->get();

    expect($images->pluck('path')->map(fn (string $path): string => basename($path))->all())
        ->toBe(['Eclair-01.jpg', 'PER001442-37205.jpg', 'baza-tresy-przedzialek-linia.JPG'])
        ->and($images->where('is_main', true)->pluck('path')->map(fn (string $path): string => basename($path))->all())
        ->toBe(['Eclair-01.jpg']);
});

it('previews, writes and rolls back putting the wig model photo first', function (): void {
    Storage::fake('local');
    $this->travelTo('2026-11-20 12:00:00');

    $eclair = wigWithPhotos('eclair-10-14-80-a-peruka-syntetyczna', ['PER001442-37205.jpg', 'Eclair-01.jpg', 'baza-tresy.JPG']);
    $sample = $eclair->images()->where('is_main', true)->firstOrFail();
    $eclair->update(['default_image_type' => Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE, 'default_image_id' => $sample->id]);
    $calata = wigWithPhotos('calata-15-23-6r-peruka-syntetyczna', ['Hair-Up-Calata-15-23-6R-900x900.jpg', '15-23-6R.jpg']);
    wigWithPhotos('flatwhite-small-61', ['61.jpg', '-dlugosci.webp']);
    $bachata = wigWithPhotos('bachata-23-66-8r-peruka-syntetyczna', ['Hai001421-68529.jpg', 'Hai001421-68241.jpg', 'Hai001421-68242.jpg']);

    $this->artisan('peruka:model-photo-first')
        ->expectsOutputToContain('PRODUCTS=4 ALREADY_DONE=0 CHANGES=2 NO_MODEL_PHOTO=1')
        ->expectsOutputToContain('MOVE product='.$eclair->id.' eclair-10-14-80-a-peruka-syntetyczna: PER001442-37205.jpg -> Eclair-01.jpg')
        ->expectsOutputToContain('NO_MODEL_PHOTO product=')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($eclair->fresh()->main_image_url)->toEndWith('PER001442-37205.jpg');

    $this->artisan('peruka:model-photo-first', ['--write' => true])
        ->expectsOutputToContain('REORDERED=2')
        ->assertSuccessful();

    expect($bachata->fresh()->main_image_url)->toEndWith('Hai001421-68241.jpg');

    // A second run would read the stock-number wig photo now in front as a colour sample.
    $this->artisan('peruka:model-photo-first', ['--write' => true])
        ->expectsOutputToContain('PRODUCTS=4 ALREADY_DONE=2 CHANGES=0 NO_MODEL_PHOTO=1')
        ->assertSuccessful();

    expect($bachata->fresh()->main_image_url)->toEndWith('Hai001421-68241.jpg');

    $this->travelTo('2026-11-24 12:00:00');
    $imported = wigWithPhotos('samba-830-131-14-peruka-syntetyczna', ['Hai001489-68485.jpg', 'Hai001489-68583.jpg']);

    $this->artisan('peruka:model-photo-first')
        ->expectsOutputToContain('PRODUCTS=5 ALREADY_DONE=3 CHANGES=0')
        ->assertSuccessful();

    expect($imported->fresh()->main_image_url)->toEndWith('Hai001489-68485.jpg');

    $eclair->refresh()->load('images');

    expect($eclair->main_image_url)->toEndWith('Eclair-01.jpg')
        ->and($eclair->default_image_id)->toBeNull()
        ->and($eclair->images->sortBy('sort_order')->pluck('path')->map(fn (string $path): string => basename($path))->values()->all())
        ->toBe(['Eclair-01.jpg', 'PER001442-37205.jpg', 'baza-tresy.JPG'])
        ->and($calata->fresh()->main_image_url)->toEndWith('Hair-Up-Calata-15-23-6R-900x900.jpg');

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->first();

    $this->artisan('peruka:model-photo-first', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED=2 OF 2')
        ->assertSuccessful();

    $eclair->refresh();

    expect($eclair->main_image_url)->toEndWith('PER001442-37205.jpg')
        ->and($eclair->default_image_id)->toBe($sample->id)
        ->and(Storage::disk('local')->exists($backup.'.rolled-back'))->toBeTrue();

    $this->artisan('peruka:model-photo-first')
        ->expectsOutputToContain('ALREADY_DONE=1 CHANGES=2')
        ->assertSuccessful();
});

/** @param  list<string>  $files */
function wigWithPhotos(string $slug, array $files): Product
{
    $product = Product::query()->create([
        'name' => $slug,
        'slug' => $slug,
        'status' => ProductStatus::ACTIVE,
        'external_source' => 'peruka',
        'external_id' => $slug,
    ]);

    foreach ($files as $position => $file) {
        ProductImage::query()->create([
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => 'products/peruka/'.$slug.'/gallery/'.$file,
            'source_url' => WIG_MEDIA.$file,
            'sort_order' => $position,
            'is_main' => $position === 0,
        ]);
    }

    return $product;
}
