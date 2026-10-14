<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Herdegen\HerdegenDocumentationManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ExportHerdegenDocumentationManifestCommand extends Command
{
    protected $signature = 'herdegen:export-documentation-manifest
        {--review=scrapers/herdegen/analysis/herdegen02b-configuration-review.json}
        {--intake=scrapers/herdegen/analysis/herdegen02c-evidence-intake.json}
        {--json=scrapers/herdegen/analysis/herdegen02d-documentation-manifest.json}
        {--csv=scrapers/herdegen/analysis/herdegen02d-documentation-template.csv}';

    protected $description = 'Export private Herdegen manufacturer documentation requirements, without changing products.';

    public function __construct(private readonly HerdegenDocumentationManifest $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = [
            'review' => (string) $this->option('review'),
            'intake' => (string) $this->option('intake'),
            'json' => (string) $this->option('json'),
            'csv' => (string) $this->option('csv'),
        ];
        foreach ($paths as $key => $path) {
            $extension = $key === 'csv' ? 'csv' : 'json';
            if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.'.$extension.'$~D', $path)) {
                $this->error('HERDEGEN02D=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (count(array_unique($paths)) !== 4) {
            $this->error('HERDEGEN02D=FAIL: colliding paths.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $reviewRaw = $disk->get($paths['review']);
            $intakeRaw = $disk->get($paths['intake']);
            $review = json_decode($reviewRaw, true, flags: JSON_THROW_ON_ERROR);
            $intake = json_decode($intakeRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($review) || !is_array($intake)
                || ($intake['review_report_sha256'] ?? null) !== hash('sha256', $reviewRaw)) {
                throw new RuntimeException('HERDEGEN-02B/02C provenance mismatch.');
            }
            $result = $this->service->build($review, $intake);
            $manifest = $result['manifest'];
            $manifest['review_report_sha256'] = hash('sha256', $reviewRaw);
            $manifest['intake_report_sha256'] = hash('sha256', $intakeRaw);
            $csv = $this->service->csv($result['rows']);
            $manifest['template_csv_sha256'] = hash('sha256', $csv);
            $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            // Preflight both outputs before writing either one.
            if ($disk->exists($paths['json']) || $disk->exists($paths['csv'])) {
                throw new RuntimeException('Output exists; refusing to overwrite a supplier-edited template.');
            }
            if (!$disk->put($paths['json'], $json) || !$disk->put($paths['csv'], $csv)) {
                throw new RuntimeException('Private manifest export failed.');
            }
            foreach ($manifest['summary'] as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->line('JSON='.$paths['json']);
            $this->line('CSV='.$paths['csv']);
            $this->info('HERDEGEN02D=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN02D=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
