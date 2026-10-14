<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Herdegen\HerdegenConfigurationReview;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ReviewHerdegenConfigurationsCommand extends Command
{
    protected $signature = 'herdegen:review-configurations
        {--from=scrapers/herdegen/analysis/listing-snapshot.json}
        {--inventory=scrapers/herdegen/analysis/herdegen02a-evidence-inventory.json}
        {--output=scrapers/herdegen/analysis/herdegen02b-configuration-review.json}';

    protected $description = 'Read-only configuration evidence review; does not infer orderable variants.';

    public function __construct(private readonly HerdegenConfigurationReview $reviewer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $paths = [
            'from' => (string) $this->option('from'),
            'inventory' => (string) $this->option('inventory'),
            'output' => (string) $this->option('output'),
        ];
        foreach ($paths as $path) {
            if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.json$~D', $path)) {
                $this->error('HERDEGEN02B=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if (count(array_unique($paths)) !== 3) {
            $this->error('HERDEGEN02B=FAIL: colliding paths.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $sourceRaw = $disk->get($paths['from']);
            $inventoryRaw = $disk->get($paths['inventory']);
            $source = json_decode($sourceRaw, true, flags: JSON_THROW_ON_ERROR);
            $inventory = json_decode($inventoryRaw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($source) || !is_array($inventory)
                || ($inventory['source_report_sha256'] ?? null) !== hash('sha256', $sourceRaw)) {
                throw new RuntimeException('Snapshot/inventory SHA-256 mismatch.');
            }
            $report = $this->reviewer->review($source, $inventory);
            $report['source_report_sha256'] = hash('sha256', $sourceRaw);
            $report['inventory_report_sha256'] = hash('sha256', $inventoryRaw);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($paths['output'], $payload)) {
                throw new RuntimeException('Private report write failed.');
            }
            foreach ($report['summary'] as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->line('OUTPUT='.$paths['output']);
            $this->info('HERDEGEN02B=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN02B=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
