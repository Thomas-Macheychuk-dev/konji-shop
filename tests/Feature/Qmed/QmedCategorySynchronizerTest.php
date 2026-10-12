<?php

declare(strict_types=1);

use App\Enums\CategoryStatus;
use App\Models\Category;
use App\Models\Product;
use App\Services\Qmed\QmedCategorySynchronizer;
use App\Services\Qmed\QmedDraftImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Qmed\Support\QmedDatasetFactory;

uses(RefreshDatabase::class);

it('creates the archived hierarchy and 156 memberships only once', function (): void {
    $dataset = QmedDatasetFactory::make();
    app(QmedDraftImporter::class)->import($dataset, 156, 156);
    $sync = app(QmedCategorySynchronizer::class);

    expect($sync->sync($dataset))->toMatchArray(['created_categories' => 14, 'created_memberships' => 156]);
    expect($sync->sync($dataset))->toMatchArray(['created_categories' => 0, 'created_memberships' => 0]);
    expect(Category::where('slug', 'like', 'qmed%')->count())->toBe(14);
    expect(Category::where('slug', 'like', 'qmed%')->get()->every(fn (Category $c) => $c->status === CategoryStatus::ARCHIVED))->toBeTrue();

    $root = Category::where('slug', 'qmed')->firstOrFail();
    $neck = Category::where('slug', 'qmed-szyja')->firstOrFail();
    expect($neck->parent_id)->toBe($root->id);
    expect($neck->products()->count())->toBe(156);
    expect(Product::where('external_source', 'qmed')->count())->toBe(156);
});

it('rejects conflicting pre-existing Qmed category without changing memberships', function (): void {
    $dataset = QmedDatasetFactory::make();
    app(QmedDraftImporter::class)->import($dataset, 156, 156);
    Category::create(['name' => 'Wrong category', 'slug' => 'qmed', 'status' => CategoryStatus::ARCHIVED]);

    expect(fn () => app(QmedCategorySynchronizer::class)->sync($dataset))
        ->toThrow(RuntimeException::class);
    expect(Category::where('slug', 'like', 'qmed%')->count())->toBe(1);
    expect(DB::table('category_product')->count())->toBe(0);
});
