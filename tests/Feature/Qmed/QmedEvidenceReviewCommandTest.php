<?php

declare(strict_types=1);

namespace Tests\Feature\Qmed;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class QmedEvidenceReviewCommandTest extends TestCase
{
    public function test_rejects_unsafe_paths_without_writing(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:review-evidence', ['--from' => '../secrets.json'])->assertFailed();
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed08g-evidence-review.json');
    }

    public function test_fails_closed_on_missing_private_inputs(): void
    {
        Storage::fake('local');
        $this->artisan('qmed:review-evidence')->assertFailed();
        Storage::disk('local')->assertMissing('scrapers/qmed/analysis/qmed08g-evidence-review.json');
    }
}
