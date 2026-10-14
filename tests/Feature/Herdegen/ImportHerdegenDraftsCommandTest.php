<?php

declare(strict_types=1);

namespace Tests\Feature\Herdegen;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ImportHerdegenDraftsCommandTest extends TestCase
{
    public function test_it_rejects_missing_or_invalid_private_reports(): void
    {
        Storage::fake('local');
        $this->artisan('herdegen:import', ['--from' => '../secrets.json'])->assertExitCode(1);
        $this->artisan('herdegen:import')->assertExitCode(1);
    }

    public function test_it_defaults_to_read_only_with_one_valid_record(): void
    {
        Storage::fake('local');
        $url = 'https://www.orteo.pl/test-product';
        Storage::disk('local')->put('scrapers/herdegen/analysis/listing-snapshot.json', json_encode([
            'source' => 'herdegen',
            'analysis_type' => 'herdegen_offline_listing_snapshot',
            'summary' => ['products' => 1, 'commercially_approved' => 0],
            'products' => [[
                'source_url' => $url,
                'external_id' => substr(hash('sha256', $url), 0, 32),
                'name' => 'Test Herdegen',
                'brand' => 'Herdegen',
                'commercially_approved' => false,
                'variant_candidates' => [],
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->artisan('herdegen:import')->expectsOutput('MODE=DRY_RUN')
            ->expectsOutput('DATABASE_WRITES=0')->assertExitCode(0);
    }
}
