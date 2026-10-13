<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedEvidenceReviewService;
use App\Services\Qmed\QmedSemanticEvidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReviewQmedEvidenceCommand extends Command
{
    protected $signature = 'qmed:review-evidence
        {--from=scrapers/qmed/full-product-data.json}
        {--sizes=scrapers/qmed/analysis/structured-size-candidates-laravel.json}
        {--output=scrapers/qmed/analysis/qmed08g-evidence-review.json}';

    protected $description = 'Produce a private, read-only Qmed manufacturer evidence review.';

    public function __construct(
        private readonly QmedEvidenceReviewService $reviewer,
        private readonly QmedSemanticEvidenceService $semantic,
    ) { parent::__construct(); }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $sizes = (string) $this->option('sizes');
        $output = (string) $this->option('output');
        foreach ([$from, $sizes, $output] as $path) {
            if (!preg_match('~^scrapers/qmed/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.json$~D', $path)
                || str_contains($path, '..')) {
                $this->error('QMED08G=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (!str_starts_with($output, 'scrapers/qmed/analysis/') || count(array_unique([$from, $sizes, $output])) !== 3) {
            $this->error('QMED08G=FAIL: invalid output location.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $rawSource = $disk->get($from);
            $rawSizes = $disk->get($sizes);
            $source = json_decode($rawSource, true, flags: JSON_THROW_ON_ERROR);
            $sizeReport = json_decode($rawSizes, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($source) || !is_array($sizeReport)) throw new \RuntimeException('Invalid JSON report.');
            $review = $this->reviewer->review($sizeReport, $rawSizes);
            $semantic = $this->semantic->analyse($source, $review);
            $remaining = array_values(array_filter($semantic['products'], fn(array $row) => $row['review_flags'] !== []));
            $report = [
                'source' => 'qmed', 'analysis_type' => 'qmed08g_consolidated_evidence_review',
                'source_report_sha256' => hash('sha256', $rawSource),
                'size_report_sha256' => hash('sha256', $rawSizes),
                'review' => $review, 'semantic' => $semantic,
                'summary' => [
                    'products' => $review['summary']['products'],
                    'with_candidates' => $review['summary']['with_candidates'],
                    'without_candidates' => $review['summary']['without_candidates'],
                    'candidate_size_entries' => $review['summary']['candidate_size_entries'],
                    'semantic_review_required' => count($remaining),
                    'commercially_approved' => 0, 'database_writes' => 0, 'variants_created' => 0,
                ],
            ];
            if ($report['summary']['products'] !== 156 || $report['summary']['with_candidates'] !== 117
                || $report['summary']['without_candidates'] !== 39 || $report['summary']['candidate_size_entries'] !== 578) {
                throw new \RuntimeException('Unexpected Qmed size-analysis baseline.');
            }
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($output, $payload)) throw new \RuntimeException('Unable to write private report.');
            foreach ($report['summary'] as $key => $value) $this->line(strtoupper($key).'='.$value);
            $this->line('OUTPUT='.$output);
            $this->info('QMED08G=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED08G=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
