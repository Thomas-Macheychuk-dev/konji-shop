<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Herdegen\HerdegenEvidenceReadiness;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ReconcileHerdegenEvidenceReadinessCommand extends Command
{
    protected $signature = 'herdegen:reconcile-evidence-readiness
        {--review=scrapers/herdegen/analysis/herdegen02b-configuration-review.json}
        {--intake=scrapers/herdegen/analysis/herdegen02c-evidence-intake.json}
        {--manifest=scrapers/herdegen/analysis/herdegen02d-documentation-manifest.json}
        {--validation=scrapers/herdegen/analysis/herdegen02e-supplier-validation.json}
        {--csv=scrapers/herdegen/analysis/herdegen02d-documentation-template.csv}
        {--output=scrapers/herdegen/analysis/herdegen02f-evidence-readiness.json}';

    protected $description = 'Reconcile read-only Herdegen supplier-evidence readiness with provenance checks.';

    public function __construct(private readonly HerdegenEvidenceReadiness $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = [];
        foreach (['review', 'intake', 'manifest', 'validation', 'csv', 'output'] as $key) {
            $path = (string) $this->option($key);
            $extension = $key === 'csv' ? 'csv' : 'json';
            if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.' . $extension . '$~D', $path)) {
                $this->error('HERDEGEN02F=FAIL: unsafe private path.');
                return self::FAILURE;
            }
            $paths[$key] = $path;
        }
        if (count(array_unique($paths)) !== count($paths)) {
            $this->error('HERDEGEN02F=FAIL: colliding paths.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            if ($disk->exists($paths['output'])) {
                throw new RuntimeException('Output already exists; refusing overwrite.');
            }
            $raw = [];
            $data = [];
            foreach (['review', 'intake', 'manifest', 'validation'] as $key) {
                $raw[$key] = $disk->get($paths[$key]);
                $data[$key] = json_decode($raw[$key], true, flags: JSON_THROW_ON_ERROR);
                if (!is_array($data[$key])) {
                    throw new RuntimeException('Invalid JSON input: ' . $key);
                }
            }
            $raw['csv'] = $disk->get($paths['csv']);
            if (($data['intake']['review_report_sha256'] ?? null) !== hash('sha256', $raw['review'])
                || ($data['manifest']['review_report_sha256'] ?? null) !== hash('sha256', $raw['review'])
                || ($data['manifest']['intake_report_sha256'] ?? null) !== hash('sha256', $raw['intake'])
                || ($data['validation']['manifest_sha256'] ?? null) !== hash('sha256', $raw['manifest'])
                || ($data['validation']['supplier_csv_sha256'] ?? null) !== hash('sha256', $raw['csv'])) {
                throw new RuntimeException('SHA-256 provenance chain mismatch.');
            }
            $result = $this->service->reconcile(
                $data['review'], $data['intake'], $data['manifest'], $data['validation'], $raw['csv']
            );
            foreach ($raw as $key => $value) {
                $result[$key . '_sha256'] = hash('sha256', $value);
            }
            $payload = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            if (!$disk->put($paths['output'], $payload)) {
                throw new RuntimeException('Could not write private report.');
            }
            foreach ($result['summary'] as $key => $value) {
                $this->line(strtoupper($key) . '=' . $value);
            }
            $this->line('OUTPUT=' . $paths['output']);
            $this->info('HERDEGEN02F=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN02F=FAIL: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
