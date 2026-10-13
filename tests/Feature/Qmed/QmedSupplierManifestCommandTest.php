<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedSupplierManifestCommandTest extends TestCase
{
    public function test_rejects_unsafe_export_path(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:export-supplier-manifest', [
            '--csv' => '../../public/supplier.csv',
        ])->assertExitCode(1);
    }

    public function test_rejects_missing_source_files(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:export-supplier-manifest')->assertExitCode(1);
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed09c-supplier-requirements.json');
    }
}
