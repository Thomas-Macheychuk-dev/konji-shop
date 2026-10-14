<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedConfigurationReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ReconcileQmedConfigurationsCommand extends Command
{
    protected $signature = 'qmed:reconcile-configurations
        {--evidence=scrapers/qmed/analysis/qmed08g-evidence-review.json}
        {--validation=scrapers/qmed/analysis/qmed09d-supplier-response-validation.json}
        {--csv=scrapers/qmed/analysis/qmed09c-supplier-template.csv}
        {--output=scrapers/qmed/analysis/qmed09f-configuration-reconciliation.json}';

    protected $description = 'Compare supplier sizes and sides with manufacturer evidence without changing products.';

    public function __construct(private readonly QmedConfigurationReconciliationService $reconciler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = ['evidence' => (string) $this->option('evidence'),
            'validation' => (string) $this->option('validation'),
            'csv' => (string) $this->option('csv'),
            'output' => (string) $this->option('output')];
        foreach ($paths as $type => $path) {
            $extension = $type === 'csv' ? 'csv' : 'json';
            if (!preg_match('~^scrapers/qmed/analysis/[A-Za-z0-9_-]+\.'. $extension .'$~D', $path)) {
                $this->error('QMED09F=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (count(array_unique($paths)) !== 4) {
            $this->error('QMED09F=FAIL: colliding paths.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $evidenceRaw = $disk->get($paths['evidence']);
            $validationRaw = $disk->get($paths['validation']);
            $csvRaw = $disk->get($paths['csv']);
            $evidence = json_decode($evidenceRaw, true, flags: JSON_THROW_ON_ERROR);
            $validation = json_decode($validationRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($evidence) || !is_array($validation)
                || ($validation['supplier_csv_sha256'] ?? null) !== hash('sha256', $csvRaw)
                || ($validation['analysis_type'] ?? null) !== 'qmed09d_supplier_response_validation'
                || ($evidence['analysis_type'] ?? null) !== 'qmed08g_consolidated_evidence_review') {
                throw new \RuntimeException('QMED evidence/CSV/validation integrity mismatch.');
            }
            $report = $this->reconciler->reconcile($evidence, $validation, $csvRaw);
            $report['evidence_report_sha256'] = hash('sha256', $evidenceRaw);
            $report['validation_report_sha256'] = hash('sha256', $validationRaw);
            $report['supplier_csv_sha256'] = hash('sha256', $csvRaw);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($paths['output'], $payload)) throw new \RuntimeException('Private report write failed.');
            foreach ($report['summary'] as $key => $value) $this->line(strtoupper($key).'='.$value);
            $this->line('OUTPUT='.$paths['output']);
            $this->info('QMED09F=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED09F=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
