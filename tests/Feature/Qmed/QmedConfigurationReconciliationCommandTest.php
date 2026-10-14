<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedConfigurationReconciliationCommandTest extends TestCase
{
    public function test_rejects_unsafe_output_path(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:reconcile-configurations', [
            '--output' => '../public/report.json',
        ])->assertExitCode(1);
    }

    public function test_missing_private_inputs_fail_closed(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:reconcile-configurations')->assertExitCode(1);
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed09f-configuration-reconciliation.json');
    }
}
