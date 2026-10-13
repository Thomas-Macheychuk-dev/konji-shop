<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedSizeAnalysisCommandTest extends TestCase
{
    public function test_command_produces_private_read_only_report(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('scrapers/qmed/full-product-data.json', json_encode([
            'products' => [[
                'name' => 'Sample', 'external_id' => 'test',
                'technical_tables' => [['rows' => [
                    ['Rozmiar', 'S', 'M'], ['Obwód (cm)', '25-30', '30-35'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->artisan('qmed:analyse-sizes')->assertExitCode(0);
        $path = 'scrapers/qmed/analysis/structured-size-candidates-v3.json';
        self::assertTrue(Storage::disk('local')->exists($path));
        $report = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(2, $report['counts']['candidate_size_entries']);
        self::assertFalse($report['products'][0]['commercially_verified']);
    }

    public function test_command_rejects_unsafe_paths_and_missing_input(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:analyse-sizes', ['--from' => '../secret.json'])->assertExitCode(1);
        $this->artisan('qmed:analyse-sizes', ['--output' => 'scrapers/qmed/full-product-data.json'])->assertExitCode(1);
        $this->artisan('qmed:analyse-sizes')->assertExitCode(1);
    }
}
