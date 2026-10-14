<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Herdegen\HerdegenEvidenceAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class AuditHerdegenEvidenceCommand extends Command
{
    protected $signature = 'herdegen:audit-evidence
        {--from=scrapers/herdegen/analysis/listing-snapshot.json}
        {--output=scrapers/herdegen/analysis/herdegen02a-evidence-inventory.json}';

    protected $description = 'Inventory offline Herdegen listing evidence without touching products, variants or images.';

    public function __construct(private readonly HerdegenEvidenceAudit $auditor)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $output = (string) $this->option('output');
        foreach ([$from, $output] as $path) {
            if (!preg_match('~^scrapers/herdegen/analysis/[A-Za-z0-9_-]+\.json$~D', $path)) {
                $this->error('HERDEGEN02A=FAIL: unsafe private path.');
                return self::FAILURE;
            }
        }
        if ($from === $output) {
            $this->error('HERDEGEN02A=FAIL: input and output collide.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            $raw = $disk->get($from);
            $snapshot = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($snapshot)) {
                throw new RuntimeException('Invalid JSON root.');
            }
            $report = $this->auditor->audit($snapshot);
            $report['source_report_sha256'] = hash('sha256', $raw);
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
            if (!$disk->put($output, $payload)) {
                throw new RuntimeException('Could not write private report.');
            }
            foreach ($report['summary'] as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->line('OUTPUT='.$output);
            $this->info('HERDEGEN02A=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('HERDEGEN02A=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
