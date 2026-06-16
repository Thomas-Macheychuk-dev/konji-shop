<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('dry-runs cleaning anchor tags from imported Peruka product descriptions without writing changes', function (): void {
    $product = Product::query()->create([
        'name' => 'Peruka linked description product',
        'slug' => 'peruka-linked-description-product',
        'short_description' => '<p>Short <a href="https://www.peruka.pl/example.html">link text</a>.</p>',
        'description' => '<p>Read <a href="https://www.peruka.pl/example.html">product guide</a>.</p>',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'peruka',
        'external_id' => 'clean-001',
    ]);

    $this->artisan('peruka:clean-descriptions', [
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Mode: dry-run')
        ->expectsOutputToContain('Products that would be updated: 1')
        ->assertSuccessful();

    $product->refresh();

    expect($product->description)->toContain('<a href=')
        ->and($product->short_description)->toContain('<a href=');
});

it('removes anchor tags from already imported Peruka product descriptions while keeping link text', function (): void {
    $product = Product::query()->create([
        'name' => 'Peruka linked description product',
        'slug' => 'peruka-linked-description-product',
        'short_description' => '<p>Short <a href="https://www.peruka.pl/example.html">link text</a>.</p>',
        'description' => '<p>Read <a href="https://www.peruka.pl/example.html">product guide</a> and <a href="/more"><strong>details</strong></a>.</p>',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'peruka',
        'external_id' => 'clean-001',
    ]);

    $otherProduct = Product::query()->create([
        'name' => 'Other linked description product',
        'slug' => 'other-linked-description-product',
        'short_description' => null,
        'description' => '<p>Keep <a href="https://example.com">external link</a>.</p>',
        'status' => ProductStatus::DRAFT,
        'external_source' => 'mobilex',
        'external_id' => 'other-001',
    ]);

    $this->artisan('peruka:clean-descriptions')
        ->expectsOutputToContain('Updated Peruka product descriptions: 1')
        ->assertSuccessful();

    $product->refresh();
    $otherProduct->refresh();

    expect($product->description)->toBe('<p>Read product guide and <strong>details</strong>.</p>')
        ->and($product->short_description)->toBe('<p>Short link text.</p>')
        ->and($product->description)->not->toContain('<a ')
        ->and($product->description)->not->toContain('</a>')
        ->and($otherProduct->description)->toContain('<a href=');
});
