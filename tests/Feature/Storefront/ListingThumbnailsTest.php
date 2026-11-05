<?php

use App\Enums\CategoryStatus;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Images\ListingThumbnail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

function pngOfSize(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 21, 95, 168));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function thumbnailProduct(string $name, string $imageBytes, ?Category $category = null): ProductImage
{
    $product = Product::query()->create([
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'status' => ProductStatus::ACTIVE,
        'published_at' => now()->subDay(),
    ]);

    if ($category !== null) {
        $product->categories()->attach($category->id, ['is_primary' => true]);
    }

    $path = 'products/test/'.str($name)->slug().'.png';
    Storage::disk('public')->put($path, $imageBytes);

    return ProductImage::query()->create([
        'product_id' => $product->id,
        'disk' => 'public',
        'path' => $path,
        'mime_type' => 'image/png',
        'sort_order' => 0,
        'is_main' => true,
    ]);
}

it('shrinks large images to a 600px JPEG and leaves small ones alone', function (): void {
    $thumbnails = new ListingThumbnail;
    $jpeg = $thumbnails->make(pngOfSize(1600, 1000));

    [$width, $height, $type] = getimagesizefromstring($jpeg);

    expect([$width, $height, $type])->toBe([600, 375, IMAGETYPE_JPEG])
        ->and($thumbnails->make(pngOfSize(500, 400)))->toBeNull()
        ->and(ListingThumbnail::pathFor('products/reh4mat/1/a.png'))->toBe('thumbnails/600/products/reh4mat/1/a.jpg');

    expect(fn () => $thumbnails->make('not an image'))->toThrow(RuntimeException::class);
});

it('previews, then creates thumbnails that product cards use', function (): void {
    $category = Category::query()->create(['name' => 'Ortezy', 'slug' => 'ortezy', 'status' => CategoryStatus::ACTIVE]);
    $large = thumbnailProduct('Orteza duża', pngOfSize(1600, 1200), $category);
    $small = thumbnailProduct('Orteza mała', pngOfSize(400, 400), $category);
    $broken = thumbnailProduct('Orteza zepsuta', 'not an image', $category);

    $this->artisan('catalogue:generate-thumbnails')
        ->expectsOutputToContain('MISSING=3 THIS_RUN=3')
        ->expectsOutputToContain('MODE=READ_ONLY')
        ->assertSuccessful();

    expect($large->fresh()->thumbnail_path)->toBeNull();

    $this->artisan('catalogue:generate-thumbnails', ['--write' => true])
        ->expectsOutputToContain('CREATED=1 ALREADY_SMALL=1 FAILED=1 STILL_MISSING=0')
        ->expectsOutputToContain('FAILED ProductImage='.$broken->id)
        ->assertSuccessful();

    $thumbnailPath = 'thumbnails/600/products/test/orteza-duza.jpg';

    expect($large->fresh()->thumbnail_path)->toBe($thumbnailPath)
        ->and($small->fresh()->thumbnail_path)->toBe($small->path)
        ->and($broken->fresh()->thumbnail_path)->toBe($broken->path);

    Storage::disk('public')->assertExists($thumbnailPath);

    $this->get('/categories/ortezy')
        ->assertSee(Storage::disk('public')->url($thumbnailPath), false)
        ->assertDontSee(Storage::disk('public')->url($large->path), false);

    $this->artisan('catalogue:generate-thumbnails')->expectsOutputToContain('MISSING=0')->assertSuccessful();
});

it('returns 404 for page numbers that are not positive integers', function (): void {
    $category = Category::query()->create(['name' => 'Ortezy', 'slug' => 'ortezy', 'status' => CategoryStatus::ACTIVE]);
    thumbnailProduct('Orteza', pngOfSize(10, 10), $category);

    $this->get('/categories/ortezy?page=abc')->assertNotFound();
    $this->get('/categories/ortezy?page=0')->assertNotFound();
    $this->get('/categories/ortezy?page=1')->assertOk();
    $this->get('/?q=orteza&page=abc')->assertNotFound();
    $this->get('/?q=orteza&page=1')->assertOk();
});

it('gives description images the product name as alt and lazy loading', function (): void {
    $product = Product::query()->create([
        'name' => 'ATTACK PRO',
        'slug' => 'attack-pro',
        'status' => ProductStatus::ACTIVE,
        'published_at' => now()->subDay(),
        'description' => '<p>Opis</p><img src="https://cdn.example/a.png" class="aligncenter"><img src="https://cdn.example/b.png" alt="Schemat">',
    ]);

    $payload = $this->get('/products/'.$product->slug)->assertOk()->viewData('productPayload');

    expect($payload['description'])
        ->toContain('<img src="https://cdn.example/a.png" class="aligncenter" alt="ATTACK PRO" loading="lazy">')
        ->toContain('<img src="https://cdn.example/b.png" alt="Schemat" loading="lazy">');
});
