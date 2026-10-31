<?php

use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

require_once dirname(__DIR__, 3).'/scripts/seo/product-database-20261031/Metadata.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-repair-20261031/EditorialRepair.php';
require_once dirname(__DIR__, 3).'/scripts/seo/editorial-followup-20261031/PreviousBatch.php';

uses(RefreshDatabase::class);

function followupPreviousFixture(): array
{
    $p = Product::query()->create(['name' => 'Model ABC', 'slug' => 'followup-precondition-abc', 'status' => ProductStatus::ACTIVE,
        'seo_title' => 'Model ABC', 'seo_description' => 'Model ABC. Sprawdź opis i parametry produktu w Ortezka.pl.', 'description' => 'Dwie kieszenie.', 'short_description' => 'Model ABC']);
    $row = (array) DB::table('products')->where('id', $p->id)->first();
    $manifest = ['repair' => 'product-seo-editorial-20261031-v1', 'entries' => [[
        'id' => $p->id, 'expected' => array_intersect_key($row, array_flip(['name', 'slug', 'external_source', 'external_id', 'seo_title', 'seo_description'])),
        'source_description_sha256' => hash('sha256', $row['description']), 'source_short_description_sha256' => hash('sha256', $row['short_description']),
        'after' => ['seo_title' => 'Model ABC', 'seo_description' => 'Model ABC. Dwie kieszenie.'],
    ]]];
    $repair = new ProductSeoEditorialRepair($manifest);
    $plan = $repair->plan();
    $repair->apply($plan);
    $directory = storage_path('app/private/seo-repairs/test-followup-'.bin2hex(random_bytes(6)));
    File::ensureDirectoryExists($directory);
    file_put_contents($directory.'/plan-and-backup.json', json_encode($plan, JSON_THROW_ON_ERROR));
    $sha = hash_file('sha256', $directory.'/plan-and-backup.json');
    file_put_contents($directory.'/plan.sha256', $sha.PHP_EOL);
    file_put_contents($directory.'/receipt.json', json_encode(['state' => 'applied', 'plan_sha256' => $sha], JSON_THROW_ON_ERROR));

    return [$directory, $sha, $manifest, $p];
}

it('accepts the actual applied previous state without database writes', function (): void {
    [$directory, $sha, $manifest] = followupPreviousFixture();
    try {
        $queries = [];
        DB::listen(function ($q) use (&$queries): void { $queries[] = strtolower(ltrim($q->sql)); });
        ProductSeoPreviousBatch::verify($directory, $sha, $manifest);
        foreach ($queries as $query) {
            expect(str_starts_with($query, 'select'))->toBeTrue();
        }
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects a rolled back previous receipt', function (): void {
    [$directory, $sha, $manifest] = followupPreviousFixture();
    try {
        file_put_contents($directory.'/receipt.json', json_encode(['state' => 'rolled_back', 'plan_sha256' => $sha], JSON_THROW_ON_ERROR));
        expect(fn () => ProductSeoPreviousBatch::verify($directory, $sha, $manifest))->toThrow(RuntimeException::class, 'not applied');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('rejects a changed previous backup or later editor changes', function (): void {
    [$directory, $sha, $manifest, $product] = followupPreviousFixture();
    try {
        expect(fn () => ProductSeoPreviousBatch::verify($directory, str_repeat('0', 64), $manifest))->toThrow(RuntimeException::class, 'checksum');
        $product->update(['seo_description' => 'Later editor copy.']);
        expect(fn () => ProductSeoPreviousBatch::verify($directory, $sha, $manifest))->toThrow(RuntimeException::class, 'Verification mismatch');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('validates the frozen followup metadata without overwriting the first batch', function (): void {
    $manifest = json_decode(file_get_contents(base_path('scripts/seo/editorial-followup-20261031/review.json')), true, flags: JSON_THROW_ON_ERROR);
    $previous = json_decode(file_get_contents(base_path('scripts/seo/editorial-repair-20261031/review.json')), true, flags: JSON_THROW_ON_ERROR);
    new ProductSeoEditorialRepair($manifest);
    expect(array_intersect(array_column($manifest['entries'], 'id'), array_column($previous['entries'], 'id')))->toBe([]);
    expect($manifest['requires_previous_applied_plan_sha256'])->toBe('0d90883956719cee4cfbf3805e3de024ea74c8e807d01669e113c54aef8ec762');
});
