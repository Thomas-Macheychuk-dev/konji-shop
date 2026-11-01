<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';
require_once dirname(__DIR__, 3).'/scripts/seo/colour-correction-20261101/AppliedReceipt.php';

uses(RefreshDatabase::class);

it('corrects contradictory colours without changing titles or source descriptions and supports rollback', function (): void {
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/colour-correction-20261101/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $fixtures = array_column(json_decode(file_get_contents(base_path('tests/Fixtures/Seo/colour-correction-20261101.json')), true, flags: JSON_THROW_ON_ERROR), null, 'id');
    foreach ($manifest['entries'] as $entry) {
        Product::query()->forceCreate(['id' => $entry['id'], 'status' => ProductStatus::ACTIVE] + $entry['expected'] + [
            'description' => $fixtures[$entry['id']]['description'], 'short_description' => $fixtures[$entry['id']]['short_description'],
        ]);
    }
    $directory = storage_path('app/private/seo-repairs/colour-test-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/plan-and-backup.json', '{}');
    $sha = hash_file('sha256', $directory.'/plan-and-backup.json');
    file_put_contents($directory.'/plan.sha256', $sha.PHP_EOL);
    file_put_contents($directory.'/receipt.json', json_encode(['state' => 'applied', 'plan_sha256' => $sha], JSON_THROW_ON_ERROR));
    try {
        ProductSeoAppliedReceipt::verify($directory, $sha);
        $repair = new ProductSeoEditorialRepair($manifest);
        $plan = $repair->plan();
        expect($plan['stats']['description_updates'])->toBe(2);
        expect($plan['stats']['title_updates'])->toBe(0);
        $repair->apply($plan);
        // Superseding two metadata values must not invalidate receipt integrity.
        ProductSeoAppliedReceipt::verify($directory, $sha);
        expect($repair->verify($plan)['verified_editorial_products'])->toBe(2);
        $pink = Product::query()->findOrFail(179);
        $green = Product::query()->findOrFail(240);
        expect($pink->seo_description)->toContain('Jasnoróżowe')->not->toContain('jasnoniebieskim');
        expect($green->seo_description)->toContain('Jasnozielone')->not->toContain('różowe');
        foreach ([$pink, $green] as $product) {
            expect(ProductSeoMetadata::plain($product->description))->toBe($fixtures[$product->id]['description']);
            expect(ProductSeoMetadata::plain($product->short_description))->toBe($fixtures[$product->id]['short_description']);
        }
        $repair->rollback($plan);
        foreach ($manifest['entries'] as $entry) {
            $row = DB::table('products')->find($entry['id']);
            expect($row->seo_title)->toBe($entry['expected']['seo_title']);
            expect($row->seo_description)->toBe($entry['expected']['seo_description']);
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects a changed or rolled back prior repair receipt', function (): void {
    $directory = storage_path('app/private/seo-repairs/colour-receipt-test-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/plan-and-backup.json', '{}');
    $sha = hash_file('sha256', $directory.'/plan-and-backup.json');
    file_put_contents($directory.'/plan.sha256', $sha.PHP_EOL);
    file_put_contents($directory.'/receipt.json', json_encode(['state' => 'rolled_back', 'plan_sha256' => $sha], JSON_THROW_ON_ERROR));
    try {
        expect(fn () => ProductSeoAppliedReceipt::verify($directory, $sha))->toThrow(RuntimeException::class, 'not applied');
        expect(fn () => ProductSeoAppliedReceipt::verify($directory, str_repeat('0', 64)))->toThrow(RuntimeException::class, 'checksum');
    } finally {
        File::deleteDirectory($directory);
    }
});
