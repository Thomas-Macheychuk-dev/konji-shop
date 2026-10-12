<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Services\Qmed\QmedDraftImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Qmed\Support\QmedDatasetFactory;

uses(RefreshDatabase::class);

it('creates draft-only products and is idempotent before enrichment', function (): void {
    $dataset = QmedDatasetFactory::make(2);
    $importer = app(QmedDraftImporter::class);

    expect($importer->import($dataset, 2, 2))->toMatchArray(['created' => 2, 'unchanged' => 0]);
    expect($importer->import($dataset, 2, 2))->toMatchArray(['created' => 0, 'unchanged' => 2]);

    $products = Product::where('external_source', 'qmed')->get();
    expect($products)->toHaveCount(2);
    foreach ($products as $product) {
        expect($product->status)->toBe(ProductStatus::DRAFT)
            ->and($product->published_at)->toBeNull()
            ->and($product->external_parent_sku)->toBeNull()
            ->and($product->variants()->count())->toBe(0)
            ->and($product->images()->count())->toBe(0);
    }
});

it('refuses to overwrite a manually edited draft', function (): void {
    $dataset = QmedDatasetFactory::make(1);
    $importer = app(QmedDraftImporter::class);
    $importer->import($dataset, 1, 1);
    Product::where('external_source', 'qmed')->firstOrFail()->update(['name' => 'Changed by operator']);

    expect(fn () => $importer->import($dataset, 1, 1))
        ->toThrow(RuntimeException::class, 'Existing Qmed draft differs');
    expect(Product::where('external_source', 'qmed')->firstOrFail()->name)->toBe('Changed by operator');
});

it('rejects a foreign slug collision without writing Qmed records', function (): void {
    $dataset = QmedDatasetFactory::make(2);
    $collisionUrl = $dataset['products'][1]['source_url'];
    Product::create([
        'name' => 'Foreign product',
        'slug' => 'qmed-'.substr(hash('sha256', $collisionUrl), 0, 40),
        'description' => '<p>Unrelated</p>',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'unrelated',
        'external_id' => 'foreign-product',
    ]);

    expect(fn () => app(QmedDraftImporter::class)->import($dataset, 2, 2))
        ->toThrow(RuntimeException::class);
    expect(Product::where('external_source', 'qmed')->count())->toBe(0);
});
