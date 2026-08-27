<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public-local');
    Storage::fake('public-s3', [
        'url' => 'https://cdn.example.test',
    ]);

    config()->set('filesystems.disks.public-s3.url', 'https://cdn.example.test');
});

it('dry-runs product media migration without writing or deleting anything', function (): void {
    Storage::disk('public-local')->put('products/example/gallery/image.webp', 'image-bytes');

    expect(Artisan::call('shop:migrate-public-media'))->toBe(0);

    Storage::disk('public-local')->assertExists('products/example/gallery/image.webp');
    Storage::disk('public-s3')->assertMissing('products/example/gallery/image.webp');

    expect(Artisan::output())
        ->toContain('DRY RUN')
        ->toContain('Planned copies');
});

it('copies product media to the target and intentionally retains the source for rollback', function (): void {
    Storage::disk('public-local')->put('products/example/gallery/image.webp', 'image-bytes');
    Storage::disk('public-local')->put('products/example/documents/manual.pdf', '%PDF-example');

    expect(Artisan::call('shop:migrate-public-media', ['--write' => true]))->toBe(0);

    Storage::disk('public-s3')->assertExists('products/example/gallery/image.webp');
    Storage::disk('public-s3')->assertExists('products/example/documents/manual.pdf');
    Storage::disk('public-local')->assertExists('products/example/gallery/image.webp');
    Storage::disk('public-local')->assertExists('products/example/documents/manual.pdf');

    expect(Storage::disk('public-s3')->get('products/example/gallery/image.webp'))->toBe('image-bytes');
});

it('rewrites legacy product storage links only after the matching object exists on the target', function (): void {
    Storage::disk('public-local')->put('products/example/documents/manual.pdf', '%PDF-example');

    $product = Product::query()->create([
        'name' => 'Storage migration product',
        'slug' => 'storage-migration-product',
        'status' => ProductStatus::ACTIVE,
        'description' => '<p><a href="/storage/products/example/documents/manual.pdf">Manual</a></p>',
        'short_description' => null,
    ]);

    expect(Artisan::call('shop:migrate-public-media', [
        '--write' => true,
        '--rewrite-descriptions' => true,
    ]))->toBe(0);

    $product->refresh();

    expect($product->description)
        ->toContain('https://cdn.example.test/products/example/documents/manual.pdf')
        ->not->toContain('/storage/products/example/documents/manual.pdf');
});

it('fails description rewriting when a referenced legacy object is missing from the target', function (): void {
    Product::query()->create([
        'name' => 'Missing target product',
        'slug' => 'missing-target-product',
        'status' => ProductStatus::ACTIVE,
        'description' => '<img src="/storage/products/example/missing.webp">',
    ]);

    expect(Artisan::call('shop:migrate-public-media', [
        '--write' => true,
        '--rewrite-descriptions' => true,
    ]))->toBe(1);

    expect(Artisan::output())->toContain('target object is missing');
});

it('refuses to rewrite product links before an HTTPS public media URL is configured', function (): void {
    config()->set('filesystems.disks.public-s3.url', '');
    Storage::disk('public-local')->put('products/example/documents/manual.pdf', '%PDF-example');

    expect(Artisan::call('shop:migrate-public-media', [
        '--write' => true,
        '--rewrite-descriptions' => true,
    ]))->toBe(1);

    expect(Artisan::output())->toContain('PUBLIC_FILESYSTEM_URL');
});
