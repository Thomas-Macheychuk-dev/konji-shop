<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedEndToEndReadinessCommandTest extends TestCase
{
    public function test_rejects_output_outside_private_analysis_directory(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:check-supplier-readiness', [
            '--output' => '../public/report.json',
        ])->assertExitCode(1);
    }

    public function test_rejects_colliding_input_and_output_paths(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:check-supplier-readiness', [
            '--output' => 'scrapers/qmed/analysis/qmed09d-supplier-response-validation.json',
        ])->assertExitCode(1);
    }

    public function test_missing_private_reports_fail_closed(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:check-supplier-readiness')->assertExitCode(1);
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed09g-readiness-gate.json');
    }
}
