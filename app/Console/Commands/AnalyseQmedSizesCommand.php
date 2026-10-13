<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedSizeClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AnalyseQmedSizesCommand extends Command
{
    protected $signature = 'qmed:analyse-sizes
        {--from=scrapers/qmed/full-product-data.json : Source JSON on the private local disk.}
        {--output=scrapers/qmed/analysis/structured-size-candidates-v3.json : Destination on the private local disk.}';

    protected $description = 'Produce a read-only Qmed size evidence report without creating product variants.';

    public function __construct(private readonly QmedSizeClassifier $classifier)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $output = (string) $this->option('output');
        if (! preg_match('~^scrapers/qmed/[A-Za-z0-9/_-]+\.json$~D', $from)
            || ! preg_match('~^scrapers/qmed/analysis/[A-Za-z0-9_-]+\.json$~D', $output)
            || str_contains($from, '..') || str_contains($output, '..')
            || $from === $output) {
            $this->error('QMED_SIZE_ANALYSIS=FAIL: unsafe private input/output path.');
            return self::FAILURE;
        }
        try {
            $disk = Storage::disk('local');
            if (! $disk->exists($from)) {
                throw new \RuntimeException('Source file not found.');
            }
            $data = json_decode($disk->get($from), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException('Source must be a JSON object.');
            }
            $report = $this->classifier->analyse($data);
            $payload = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            if (! $disk->put($output, $payload)) {
                throw new \RuntimeException('Unable to write private report.');
            }
            foreach ($report['counts'] as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->line('OUTPUT='.$output);
            $this->line('DATABASE_WRITES=0');
            $this->line('VARIANTS_CREATED=0');
            $this->info('QMED_SIZE_ANALYSIS=PASS');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED_SIZE_ANALYSIS=FAIL: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
