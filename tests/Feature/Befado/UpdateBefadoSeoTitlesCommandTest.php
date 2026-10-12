<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function seedBefadoSeoProducts(
    ProductStatus $status = ProductStatus::ACTIVE,
    bool $duplicateSku = false,
): void {
    for ($i = 0; $i < 270; $i++) {
        $sku = $duplicateSku
            ? '110P000'
            : sprintf('110P%03d', $i);

        Product::query()->create([
            'name' => 'BUTY CHŁOPIĘCE SPEEDY BEFADO',
            'slug' => 'befado-seo-test-'.$i,
            'seo_title' => 'BUTY CHŁOPIĘCE SPEEDY BEFADO',
            'seo_description' => 'Testowy opis produktu Befado.',
            'description' => '<p>Opis produktu.</p>',
            'status' => $status,
            'external_source' => 'befado',
            'external_id' => (string) (90000 + $i),
            'external_parent_sku' => $sku,
        ]);
    }
}

it('previews 270 unique titles without database writes', function (): void {
    seedBefadoSeoProducts(ProductStatus::DRAFT);

    $before = Product::query()->pluck('seo_title', 'id')->all();

    $exit = Artisan::call('befado:seo-titles');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('UNIQUE_TITLES=270')
        ->toContain('CHANGED_TITLES=270')
        ->toContain('MODE=READ_ONLY')
        ->and(Product::query()->pluck('seo_title', 'id')->all())
        ->toBe($before);
});

it('refuses to update draft products', function (): void {
    Storage::fake('local');
    seedBefadoSeoProducts(ProductStatus::DRAFT);

    $exit = Artisan::call('befado:seo-titles', ['--write' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('BEFADO_SEO_TITLE_GATE=FAIL')
        ->and(Product::query()->where('seo_title', 'BUTY CHŁOPIĘCE SPEEDY BEFADO')->count())
        ->toBe(270)
        ->and(Storage::disk('local')->allFiles('scrapers/befado/backups'))
        ->toBe([]);
});

it('updates only SEO titles and is idempotent', function (): void {
    Storage::fake('local');
    seedBefadoSeoProducts();

    $before = Product::query()
        ->orderBy('id')
        ->get()
        ->mapWithKeys(fn (Product $p) => [
            $p->id => [
                'slug' => $p->slug,
                'status' => $p->status->value,
                'description' => $p->description,
                'seo_description' => $p->seo_description,
                'sku' => $p->external_parent_sku,
            ],
        ])->all();

    $firstExit = Artisan::call('befado:seo-titles', ['--write' => true]);
    $firstOutput = Artisan::output();

    expect($firstExit)->toBe(0)
        ->and($firstOutput)->toContain('UPDATED_TITLES=270')
        ->toContain('BEFADO_SEO_TITLE_UPDATE=PASS');

    $products = Product::query()->orderBy('id')->get();

    expect($products->pluck('seo_title')->unique()->count())->toBe(270);

    foreach ($products as $product) {
        expect([
            'slug' => $product->slug,
            'status' => $product->status->value,
            'description' => $product->description,
            'seo_description' => $product->seo_description,
            'sku' => $product->external_parent_sku,
        ])->toBe($before[$product->id]);
    }

    $secondExit = Artisan::call('befado:seo-titles', ['--write' => true]);

    expect($secondExit)->toBe(0)
        ->and(Artisan::output())->toContain('CHANGED_TITLES=0')
        ->toContain('UPDATED_TITLES=0');

    expect(Storage::disk('local')->allFiles('scrapers/befado/backups'))
        ->toHaveCount(2);
});

it('rejects duplicate proposed SEO titles before writing', function (): void {
    Storage::fake('local');
    seedBefadoSeoProducts(ProductStatus::ACTIVE, true);

    $exit = Artisan::call('befado:seo-titles', ['--write' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('BEFADO_SEO_TITLE_GATE=FAIL')
        ->and(Product::query()->where('seo_title', 'BUTY CHŁOPIĘCE SPEEDY BEFADO')->count())
        ->toBe(270)
        ->and(Storage::disk('local')->allFiles('scrapers/befado/backups'))
        ->toBe([]);
});
