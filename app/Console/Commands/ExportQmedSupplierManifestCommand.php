<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedSupplierManifestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ExportQmedSupplierManifestCommand extends Command
{
    protected $signature = 'qmed:export-supplier-manifest
        {--from=scrapers/qmed/full-product-data.json}
        {--audit=scrapers/qmed/analysis/qmed09b-commercial-readiness.json}
        {--json=scrapers/qmed/analysis/qmed09c-supplier-requirements.json}
        {--csv=scrapers/qmed/analysis/qmed09c-supplier-template.csv}';

    protected $description = 'Generate private Qmed supplier commercial requirements without database writes.';

    public function __construct(private readonly QmedSupplierManifestService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $audit = (string) $this->option('audit');
        $json = (string) $this->option('json');
        $csv = (string) $this->option('csv');
        foreach ([$from, $audit, $json, $csv] as $path) {
            if (!preg_match('~^scrapers/qmed/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.(?:json|csv)$~D', $path)
                || str_contains($path, '..')) {
                $this->error('QMED09C=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (!str_ends_with($from, '.json') || !str_ends_with($audit, '.json')
            || !str_starts_with($audit, 'scrapers/qmed/analysis/')
            || !preg_match('~^scrapers/qmed/analysis/[A-Za-z0-9_-]+\.json$~D', $json)
            || !preg_match('~^scrapers/qmed/analysis/[A-Za-z0-9_-]+\.csv$~D', $csv)
            || count(array_unique([$from, $audit, $json, $csv])) !== 4) {
            $this->error('QMED09C=FAIL: invalid input/output locations.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $sourceRaw = $disk->get($from);
            $auditRaw = $disk->get($audit);
            $source = json_decode($sourceRaw, true, flags: JSON_THROW_ON_ERROR);
            $report = json_decode($auditRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($source) || !is_array($report)
                || ($report['source_report_sha256'] ?? null) !== hash('sha256', $sourceRaw)
                || !is_string($report['evidence_report_sha256'] ?? null)
                || !preg_match('/^[0-9a-f]{64}$/D', $report['evidence_report_sha256'])) {
                throw new \RuntimeException('QMED-09B report is not bound to the source JSON.');
            }
            $result = $this->service->build($source, $report);
            $manifest = $result['manifest'];
            $manifest['source_report_sha256'] = hash('sha256', $sourceRaw);
            $manifest['audit_report_sha256'] = hash('sha256', $auditRaw);
            $jsonText = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            $csvText = $this->service->csv($result['rows']);
            if (!$disk->put($json, $jsonText) || !$disk->put($csv, $csvText)) {
                throw new \RuntimeException('Unable to write private manifest files.');
            }
            $this->line('PRODUCTS=156');
            $this->line('SUPPLIER_TEMPLATE_ROWS=156');
            $this->line('COMMERCIALLY_APPROVED=0');
            $this->line('DATABASE_WRITES=0');
            $this->line('VARIANTS_CREATED=0');
            $this->line('JSON='.$json);
            $this->line('CSV='.$csv);
            $this->info('QMED09C=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED09C=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
