<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedCommercialReadinessCommandTest extends TestCase
{
    public function test_it_rejects_unsafe_paths(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:audit-commercial-readiness', [
            '--from' => 'scrapers/qmed/../secret.json',
        ])->assertExitCode(1);
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed09b-commercial-readiness.json');
    }

    public function test_it_rejects_evidence_not_bound_to_source(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('scrapers/qmed/full-product-data.json', '{"products":[]}');
        Storage::disk('local')->put('scrapers/qmed/analysis/qmed08g-evidence-review.json', '{"source_report_sha256":"wrong"}');
        $this->artisan('qmed:audit-commercial-readiness')->assertExitCode(1);
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed09b-commercial-readiness.json');
    }
}
