<?php

declare(strict_types=1);

use App\Console\Commands\ImportArmedicalProductionCommand;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('requires an explicit confirmation token before any ARmedical production write can start', function (): void {
    $exit = Artisan::call('armedical:production-import', [
        '--write' => true,
        '--allow-non-production' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('BLOCKED: production writes require --confirm='.ImportArmedicalProductionCommand::CONFIRMATION_TOKEN)
        ->and(Product::query()->where('external_source', 'armedical')->exists())->toBeFalse();
});

it('rejects any production import map whose bytes do not match the frozen approved SHA', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put('scrapers/armedical/import-map-priced.json', json_encode([
        'source' => 'armedical',
        'products' => [],
    ], JSON_THROW_ON_ERROR));

    $exit = Artisan::call('armedical:production-import', [
        '--allow-non-production' => true,
    ]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('BLOCKED: priced-map SHA-256 does not match the frozen production fingerprint')
        ->and($output)->toContain(ImportArmedicalProductionCommand::APPROVED_PRICED_MAP_SHA256)
        ->and(Product::query()->where('external_source', 'armedical')->exists())->toBeFalse();
});

it('rejects unknown ARmedical production execution stages before accessing catalogue data', function (): void {
    $exit = Artisan::call('armedical:production-import', [
        '--stage' => 'publish',
        '--allow-non-production' => true,
    ]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Allowed values: all, catalogue, media')
        ->and(Product::query()->where('external_source', 'armedical')->exists())->toBeFalse();
});
