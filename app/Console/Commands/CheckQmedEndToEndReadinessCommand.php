<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedEndToEndReadinessGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CheckQmedEndToEndReadinessCommand extends Command
{
    protected $signature = 'qmed:check-supplier-readiness
        {--source=scrapers/qmed/full-product-data.json}
        {--evidence=scrapers/qmed/analysis/qmed08g-evidence-review.json}
        {--audit=scrapers/qmed/analysis/qmed09b-commercial-readiness.json}
        {--manifest=scrapers/qmed/analysis/qmed09c-supplier-requirements.json}
        {--csv=scrapers/qmed/analysis/qmed09c-supplier-template.csv}
        {--validation=scrapers/qmed/analysis/qmed09d-supplier-response-validation.json}
        {--reconciliation=scrapers/qmed/analysis/qmed09f-configuration-reconciliation.json}
        {--output=scrapers/qmed/analysis/qmed09g-readiness-gate.json}';

    protected $description = 'Replay and verify private Qmed supplier evidence reports without database writes.';

    public function __construct(private readonly QmedEndToEndReadinessGate $gate)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $keys = ['source', 'evidence', 'audit', 'manifest', 'csv', 'validation', 'reconciliation', 'output'];
        $paths = [];
        foreach ($keys as $key) {
            $path = (string) $this->option($key);
            $prefix = $key === 'source' ? 'scrapers/qmed/' : 'scrapers/qmed/analysis/';
            $ext = $key === 'csv' ? 'csv' : 'json';
            if (!preg_match('~^'.preg_quote($prefix, '~').'[A-Za-z0-9_-]+\.'. $ext .'$~D', $path)) {
                $this->error('QMED09G=FAIL: unsafe private path.');
                return self::FAILURE;
            }
            $paths[$key] = $path;
        }
        if (count(array_unique($paths)) !== count($paths)) {
            $this->error('QMED09G=FAIL: path collision.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $raw = [];
            foreach ($paths as $key => $path) {
                if ($key === 'output') continue;
                $raw[$key] = $disk->get($path);
            }
            $report = $this->gate->verify($raw);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($paths['output'], $payload)) throw new \RuntimeException('Unable to write private report.');
            foreach ($report['summary'] as $key => $value) $this->line(strtoupper($key).'='.$value);
            $this->line('SUPPLIER_SUBMISSION_STATUS='.$report['supplier_submission_status']);
            $this->line('OUTPUT='.$paths['output']);
            $this->info('QMED09G=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED09G=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
