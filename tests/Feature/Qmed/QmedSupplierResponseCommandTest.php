<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedSupplierResponseCommandTest extends TestCase
{
    public function test_rejects_public_output_path(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:validate-supplier-response', [
            '--output' => '../public/result.json',
        ])->assertExitCode(1);
    }

    public function test_missing_private_inputs_fail_closed(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:validate-supplier-response')->assertExitCode(1);
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed09d-supplier-response-validation.json');
    }
}
