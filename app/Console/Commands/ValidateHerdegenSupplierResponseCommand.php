<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Herdegen\HerdegenSupplierResponseValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ValidateHerdegenSupplierResponseCommand extends Command
{
    protected $signature = 'herdegen:validate-supplier-response
        {--manifest=scrapers/herdegen/analysis/herdegen02d-documentation-manifest.json}
        {--csv=scrapers/herdegen/analysis/herdegen02d-documentation-template.csv}
        {--output=scrapers/herdegen/analysis/herdegen02e-supplier-validation.json}';

    protected $description = 'Validate a supplier CSV without DB writes or commercial approval.';

    public function __construct(private readonly HerdegenSupplierResponseValidator $validator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = ['manifest' => (string) $this->option('manifest'),
            'csv' => (string) $this->option('csv'), 'output' => (string) $this->option('output')];
        foreach ($paths as $key => $path) {
            $ext = $key === 'csv' ? 'csv' : 'json';
            if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.'. $ext . '$~D', $path)) {
                $this->error('HERDEGEN02E=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (count(array_unique($paths)) !== 3) {
            $this->error('HERDEGEN02E=FAIL: colliding paths.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            if ($disk->exists($paths['output'])) {
                throw new RuntimeException('Output exists; refusing to overwrite prior report.');
            }
            $manifestRaw = $disk->get($paths['manifest']);
            $csvRaw = $disk->get($paths['csv']);
            $manifest = json_decode($manifestRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($manifest)) {
                throw new RuntimeException('Invalid manifest JSON.');
            }
            $report = $this->validator->validate($manifest, $csvRaw);
            $report['manifest_sha256'] = hash('sha256', $manifestRaw);
            $report['supplier_csv_sha256'] = hash('sha256', $csvRaw);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($paths['output'], $payload)) {
                throw new RuntimeException('Unable to write private report.');
            }
            foreach ($report['summary'] as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->line('OUTPUT='.$paths['output']);
            $this->info('HERDEGEN02E=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN02E=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
