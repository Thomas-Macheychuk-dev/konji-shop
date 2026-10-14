<?php

declare(strict_types=1);

namespace Tests\Feature\Herdegen;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReviewHerdegenManufacturerEvidenceCommandTest extends TestCase
{
    public function test_missing_submission_fails_closed(): void
    {
        Storage::fake('local');
        $this->artisan('herdegen:review-manufacturer-evidence')->assertExitCode(1);
    }

    public function test_empty_submission_outputs_private_read_only_report(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('scrapers/herdegen/analysis/herdegen02b-configuration-review.json', json_encode([
            'source' => 'herdegen', 'analysis_type' => 'herdegen02b_configuration_evidence_review',
            'summary' => ['products' => 0, 'commercially_approved' => 0, 'variants_created' => 0],
            'records' => [],
        ], JSON_THROW_ON_ERROR));
        $disk->put('scrapers/herdegen/analysis/herdegen02c-evidence-submission.json', json_encode([
            'source' => 'herdegen', 'analysis_type' => 'herdegen02c_manufacturer_evidence_submission',
            'entries' => [],
        ], JSON_THROW_ON_ERROR));
        $this->artisan('herdegen:review-manufacturer-evidence')
            ->expectsOutput('DATABASE_WRITES=0')
            ->expectsOutput('HERDEGEN02C=PASS')
            ->assertExitCode(0);
        self::assertTrue($disk->exists('scrapers/herdegen/analysis/herdegen02c-evidence-intake.json'));
    }
}
