<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedSupplierResponseValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class ValidateQmedSupplierResponseCommand extends Command
{
    protected $signature = 'qmed:validate-supplier-response
        {--from=scrapers/qmed/full-product-data.json}
        {--audit=scrapers/qmed/analysis/qmed09b-commercial-readiness.json}
        {--manifest=scrapers/qmed/analysis/qmed09c-supplier-requirements.json}
        {--csv=scrapers/qmed/analysis/qmed09c-supplier-template.csv}
        {--output=scrapers/qmed/analysis/qmed09d-supplier-response-validation.json}';

    protected $description = 'Read-only validation of untrusted Qmed supplier CSV responses.';

    public function __construct(private readonly QmedSupplierResponseValidator $validator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = [
            'from' => (string) $this->option('from'),
            'audit' => (string) $this->option('audit'),
            'manifest' => (string) $this->option('manifest'),
            'csv' => (string) $this->option('csv'),
            'output' => (string) $this->option('output'),
        ];
        foreach ($paths as $key => $path) {
            $extension = $key === 'csv' ? 'csv' : 'json';
            $prefix = $key === 'from' ? 'scrapers/qmed/' : 'scrapers/qmed/analysis/';
            if (!preg_match('~^'.preg_quote($prefix, '~').'[A-Za-z0-9_-]+\.'. $extension .'$~D', $path)) {
                $this->error('QMED09D=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (count(array_unique(array_values($paths))) !== count($paths)) {
            $this->error('QMED09D=FAIL: colliding paths.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $sourceRaw = $disk->get($paths['from']);
            $auditRaw = $disk->get($paths['audit']);
            $manifestRaw = $disk->get($paths['manifest']);
            $csvRaw = $disk->get($paths['csv']);
            $audit = json_decode($auditRaw, true, flags: JSON_THROW_ON_ERROR);
            $manifest = json_decode($manifestRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($audit) || !is_array($manifest)
                || ($audit['source_report_sha256'] ?? null) !== hash('sha256', $sourceRaw)
                || ($manifest['source_report_sha256'] ?? null) !== hash('sha256', $sourceRaw)
                || ($manifest['audit_report_sha256'] ?? null) !== hash('sha256', $auditRaw)) {
                throw new \RuntimeException('Manifest/audit/source integrity mismatch.');
            }
            $report = $this->validator->validate($manifest, $csvRaw);
            $report['manifest_report_sha256'] = hash('sha256', $manifestRaw);
            $report['supplier_csv_sha256'] = hash('sha256', $csvRaw);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($paths['output'], $payload)) throw new \RuntimeException('Private report write failed.');
            foreach ($report['summary'] as $key => $value) $this->line(strtoupper($key).'='.$value);
            $this->line('OUTPUT='.$paths['output']);
            $this->info('QMED09D=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED09D=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
