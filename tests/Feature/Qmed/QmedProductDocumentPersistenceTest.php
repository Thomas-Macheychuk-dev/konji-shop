<?php

declare(strict_types=1);

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductDocument;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function qmedDocumentTestProduct(string $externalId): Product
{
    return Product::create([
        'name' => 'Qmed document test '.$externalId,
        'slug' => 'qmed-doc-test-'.$externalId,
        'status' => ProductStatus::DRAFT,
        'external_source' => 'qmed',
        'external_id' => $externalId,
    ]);
}

function qmedDocumentTestAttributes(Product $product, string $externalId): array
{
    return [
        'product_id' => $product->id,
        'external_source' => 'qmed',
        'external_id' => $externalId,
        'title' => 'Instrukcja używania',
        'file_name' => 'IFU_HIP_BRACE.pdf',
        'disk' => 'local',
        'path' => 'product-documents/qmed/'.$externalId.'-'.str_repeat('a', 64).'.pdf',
        'mime_type' => 'application/pdf',
        'file_size' => 168604,
        'sha256' => str_repeat('a', 64),
    ];
}

it('persists private product document metadata and resolves the product relationship', function (): void {
    $product = qmedDocumentTestProduct('first');
    $document = $product->documents()->create(qmedDocumentTestAttributes($product, '1087'));

    expect(Schema::hasTable('product_documents'))->toBeTrue()
        ->and($document->exists)->toBeTrue()
        ->and($document->file_size)->toBe(168604)
        ->and($document->disk)->toBe('local')
        ->and($document->product->is($product))->toBeTrue()
        ->and($product->fresh()->documents)->toHaveCount(1)
        ->and($product->fresh()->status)->toBe(ProductStatus::DRAFT);
});

it('enforces global uniqueness of source and external attachment ID', function (): void {
    $first = qmedDocumentTestProduct('first');
    $second = qmedDocumentTestProduct('second');
    ProductDocument::create(qmedDocumentTestAttributes($first, '1087'));

    expect(fn () => ProductDocument::create(qmedDocumentTestAttributes($second, '1087')))
        ->toThrow(QueryException::class);

    expect(ProductDocument::count())->toBe(1);
});

it('allows the same external ID for another manufacturer', function (): void {
    $product = qmedDocumentTestProduct('first');
    $other = qmedDocumentTestProduct('second');
    ProductDocument::create(qmedDocumentTestAttributes($product, '1087'));
    $attributes = qmedDocumentTestAttributes($other, '1087');
    $attributes['external_source'] = 'other';
    ProductDocument::create($attributes);

    expect(ProductDocument::count())->toBe(2);
});

it('removes product document rows when the product is hard deleted', function (): void {
    $product = qmedDocumentTestProduct('first');
    $product->documents()->create(qmedDocumentTestAttributes($product, '1087'));
    $product->forceDelete();
    expect(ProductDocument::count())->toBe(0);
});
