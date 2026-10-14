<?php

declare(strict_types=1);

namespace Tests\Feature\Herdegen;

use App\Services\Herdegen\HerdegenEvidenceAudit;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ReviewHerdegenConfigurationsCommandTest extends TestCase
{
    public function test_it_requires_valid_private_paths(): void
    {
        $this->artisan('herdegen:review-configurations', ['--output' => '../outside.json'])->assertExitCode(1);
    }

    public function test_it_refuses_modified_evidence_hash(): void
    {
        Storage::fake('local');
        $path = 'scrapers/herdegen/analysis/';
        $url = 'https://www.orteo.pl/example';
        $snapshot = ['source' => 'herdegen', 'analysis_type' => 'herdegen_offline_listing_snapshot',
            'summary' => ['products' => 1, 'commercially_approved' => 0],
            'products' => [['source_url' => $url, 'external_id' => substr(hash('sha256', $url), 0, 32),
                'name' => 'Example', 'brand' => 'Herdegen', 'review_flags' => [],
                'variant_candidates' => [], 'commercially_approved' => false]]];
        $raw = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $inventory = (new HerdegenEvidenceAudit())->audit($snapshot);
        $inventory['source_report_sha256'] = str_repeat('0', 64);
        Storage::disk('local')->put($path.'listing-snapshot.json', $raw);
        Storage::disk('local')->put($path.'herdegen02a-evidence-inventory.json', json_encode($inventory, JSON_THROW_ON_ERROR));
        $this->artisan('herdegen:review-configurations')->assertExitCode(1);
        self::assertFalse(Storage::disk('local')->exists($path.'herdegen02b-configuration-review.json'));
    }

    public function test_it_writes_read_only_report_with_matching_hashes(): void
    {
        Storage::fake('local');
        $path = 'scrapers/herdegen/analysis/';
        $url = 'https://www.orteo.pl/example';
        $snapshot = ['source' => 'herdegen', 'analysis_type' => 'herdegen_offline_listing_snapshot',
            'summary' => ['products' => 1, 'commercially_approved' => 0],
            'products' => [['source_url' => $url, 'external_id' => substr(hash('sha256', $url), 0, 32),
                'name' => 'Example', 'brand' => 'Herdegen', 'review_flags' => ['DESCRIPTION_SUGGESTS_CONFIGURATIONS'],
                'variant_candidates' => [], 'commercially_approved' => false]]];
        $raw = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $inventory = (new HerdegenEvidenceAudit())->audit($snapshot);
        $inventory['source_report_sha256'] = hash('sha256', $raw);
        Storage::disk('local')->put($path.'listing-snapshot.json', $raw);
        Storage::disk('local')->put($path.'herdegen02a-evidence-inventory.json', json_encode($inventory, JSON_THROW_ON_ERROR));
        $this->artisan('herdegen:review-configurations')->expectsOutput('HERDEGEN02B=PASS')->assertExitCode(0);
        $report = json_decode(Storage::disk('local')->get($path.'herdegen02b-configuration-review.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $report['summary']['configuration_review_required']);
        self::assertSame(0, $report['summary']['database_writes']);
    }
}
