<?php

use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');

    Storage::disk('local')->put('scrapers/sanipur/test.json', json_encode(['products' => [
        [
            'external_product_id' => '1', 'sku' => 'TM0131_31A', 'brand' => 'Matex',
            'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, turkusowe', 'price_gross_amount' => 56.48,
            'breadcrumbs' => ['Dla Domu', 'Prześcieradła'], 'is_medical_device' => false,
        ],
        [
            'external_product_id' => '2', 'sku' => 'TM0131_25A', 'brand' => 'Matex',
            'name' => 'Matex Prześcieradło jersey z gumą 90/100x190/200, niebieskie', 'price_gross_amount' => 56.48,
            'breadcrumbs' => ['Dla Domu', 'Prześcieradła'], 'is_medical_device' => false,
        ],
        [
            'external_product_id' => '3', 'sku' => 'TS0115_0301', 'brand' => 'Sanipur',
            'name' => 'SANIPUR Podkład higieniczny- MOLTON Basic 70x90', 'price_gross_amount' => 49.90,
            'breadcrumbs' => ['Medyczne'], 'is_medical_device' => true,
        ],
    ]], JSON_UNESCAPED_UNICODE));

    $this->artisan('sanipur:import', ['--from' => 'scrapers/sanipur/test.json', '--no-images' => true, '--vat-rate' => 23, '--medical-vat-rate' => 8])
        ->assertSuccessful();
});

it('previews publishing Sanipur drafts without writing', function (): void {
    $this->artisan('sanipur:publish')
        ->expectsOutputToContain('PRODUCTS_TO_PUBLISH=2')
        ->expectsOutputToContain('VARIANTS_TO_PUBLISH=3')
        ->expectsOutputToContain('PUBLISHED_WITHOUT_IMAGES=2')
        ->expectsOutputToContain('DATABASE_WRITES=0')
        ->assertSuccessful();

    expect(Product::query()->where('status', ProductStatus::ACTIVE->value)->count())->toBe(0);
});

it('publishes Sanipur drafts, keeps them published on re-import, and rolls back', function (): void {
    $other = Product::query()->create(['name' => 'Inny draft', 'slug' => 'inny-draft', 'status' => ProductStatus::DRAFT]);

    $this->artisan('sanipur:publish', ['--write' => true])
        ->expectsOutputToContain('PUBLISHED_PRODUCTS=2 PUBLISHED_VARIANTS=3')
        ->assertSuccessful();

    $sanipur = Product::query()->where('external_source', 'sanipur')->get();
    expect($sanipur->every(fn (Product $p) => $p->status === ProductStatus::ACTIVE && $p->published_at?->isPast()))->toBeTrue()
        ->and(ProductVariant::query()->whereIn('product_id', $sanipur->pluck('id'))->where('status', ProductVariantStatus::ACTIVE->value)->count())->toBe(3)
        ->and($other->fresh()->status)->toBe(ProductStatus::DRAFT);

    $this->artisan('sanipur:import', ['--from' => 'scrapers/sanipur/test.json', '--no-images' => true, '--vat-rate' => 23, '--medical-vat-rate' => 8])
        ->assertSuccessful();

    expect(Product::query()->where('external_source', 'sanipur')->where('status', ProductStatus::ACTIVE->value)->count())->toBe(2)
        ->and(ProductVariant::query()->where('status', ProductVariantStatus::ACTIVE->value)->count())->toBe(3);

    $backup = collect(Storage::disk('local')->files('catalogue-repairs'))->first();

    $this->artisan('sanipur:publish', ['--rollback' => $backup])
        ->expectsOutputToContain('RESTORED_PRODUCTS=2 RESTORED_VARIANTS=3')
        ->assertSuccessful();

    expect(Product::query()->where('external_source', 'sanipur')->where('status', ProductStatus::DRAFT->value)->whereNull('published_at')->count())->toBe(2)
        ->and(ProductVariant::query()->where('status', ProductVariantStatus::DRAFT->value)->count())->toBe(3);
});
