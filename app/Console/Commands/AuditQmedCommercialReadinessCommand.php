<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedCommercialReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AuditQmedCommercialReadinessCommand extends Command
{
    protected $signature = 'qmed:audit-commercial-readiness
        {--from=scrapers/qmed/full-product-data.json}
        {--evidence=scrapers/qmed/analysis/qmed08g-evidence-review.json}
        {--output=scrapers/qmed/analysis/qmed09b-commercial-readiness.json}';

    protected $description = 'Audit Qmed commercial evidence without changing products or variants.';

    public function __construct(private readonly QmedCommercialReadinessService $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $evidencePath = (string) $this->option('evidence');
        $output = (string) $this->option('output');
        foreach ([$from, $evidencePath, $output] as $path) {
            if (!preg_match('~^scrapers/qmed/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.json$~D', $path)
                || str_contains($path, '..')) {
                $this->error('QMED09B=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (!str_starts_with($output, 'scrapers/qmed/analysis/')
            || count(array_unique([$from, $evidencePath, $output])) !== 3) {
            $this->error('QMED09B=FAIL: invalid output path.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $raw = $disk->get($from);
            $evidenceRaw = $disk->get($evidencePath);
            $source = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $evidence = json_decode($evidenceRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($source) || !is_array($evidence)) throw new \RuntimeException('Invalid JSON input.');
            if (($evidence['source_report_sha256'] ?? null) !== hash('sha256', $raw)) {
                throw new \RuntimeException('QMED-08G evidence is not bound to this source JSON.');
            }
            $report = $this->auditor->audit($source, $evidence);
            $report['source_report_sha256'] = hash('sha256', $raw);
            $report['evidence_report_sha256'] = hash('sha256', $evidenceRaw);
            $summary = $report['summary'];
            if ($summary['source_skus_present_unverified'] !== 0
                || $summary['source_positive_gross_prices_present_unverified'] !== 0
                || $summary['commercially_ready'] !== 0) {
                throw new \RuntimeException('Commercial baseline changed; requires manual review.');
            }
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($output, $payload)) throw new \RuntimeException('Unable to write private report.');
            $this->line('PRODUCTS='.$summary['products']);
            $this->line('SOURCE_SKUS_PRESENT='.$summary['source_skus_present_unverified']);
            $this->line('SOURCE_PRICES_PRESENT='.$summary['source_positive_gross_prices_present_unverified']);
            $this->line('ORDERABLE_VARIANTS_VERIFIED='.$summary['orderable_variants_verified']);
            $this->line('SEMANTIC_REVIEW_REQUIRED='.$summary['semantic_review_required']);
            $this->line('COMMERCIALLY_READY='.$summary['commercially_ready']);
            $this->line('DATABASE_WRITES=0');
            $this->line('VARIANTS_CREATED=0');
            $this->line('OUTPUT='.$output);
            $this->info('QMED09B=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED09B=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
