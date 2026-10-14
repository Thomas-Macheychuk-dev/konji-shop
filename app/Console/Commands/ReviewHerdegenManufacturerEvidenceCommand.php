<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Herdegen\HerdegenManufacturerEvidenceIntake;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ReviewHerdegenManufacturerEvidenceCommand extends Command
{
    protected $signature = 'herdegen:review-manufacturer-evidence
        {--review=scrapers/herdegen/analysis/herdegen02b-configuration-review.json}
        {--submission=scrapers/herdegen/analysis/herdegen02c-evidence-submission.json}
        {--output=scrapers/herdegen/analysis/herdegen02c-evidence-intake.json}';

    protected $description = 'Read-only intake of manually supplied Herdegen documentary claims.';

    public function __construct(private readonly HerdegenManufacturerEvidenceIntake $intake)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = [
            'review' => (string) $this->option('review'),
            'submission' => (string) $this->option('submission'),
            'output' => (string) $this->option('output'),
        ];
        foreach ($paths as $path) {
            if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.json$~D', $path)) {
                $this->error('HERDEGEN02C=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (count(array_unique($paths)) !== 3) {
            $this->error('HERDEGEN02C=FAIL: colliding paths.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $reviewRaw = $disk->get($paths['review']);
            $submissionRaw = $disk->get($paths['submission']);
            $review = json_decode($reviewRaw, true, flags: JSON_THROW_ON_ERROR);
            $submission = json_decode($submissionRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($review) || !is_array($submission)) {
                throw new RuntimeException('Invalid JSON inputs.');
            }
            $report = $this->intake->assess($review, $submission);
            $report['review_report_sha256'] = hash('sha256', $reviewRaw);
            $report['submission_sha256'] = hash('sha256', $submissionRaw);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($paths['output'], $payload)) {
                throw new RuntimeException('Unable to write private report.');
            }
            foreach ($report['summary'] as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->line('OUTPUT='.$paths['output']);
            $this->info('HERDEGEN02C=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN02C=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
