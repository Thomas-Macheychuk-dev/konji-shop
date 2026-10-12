<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedCategorySynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SyncQmedCategoriesCommand extends Command
{
    protected $signature = 'qmed:sync-categories
        {--from=scrapers/qmed/full-product-data.json : Private Qmed dataset.}
        {--dry-run : Read-only validation.}
        {--write : Create archived categories and attach Qmed products in local/testing only.}';

    protected $description = 'Audit or create Qmed category hierarchy and product memberships.';

    public function __construct(private readonly QmedCategorySynchronizer $syncer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((bool) $this->option('dry-run') === (bool) $this->option('write')) {
            $this->error('Specify exactly one of --dry-run or --write.');

            return self::FAILURE;
        }
        $path = (string) $this->option('from');
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || ! Storage::disk('local')->exists($path)) {
            $this->error('Unsafe or missing dataset path.');

            return self::FAILURE;
        }
        try {
            $dataset = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
            $check = $this->syncer->inspect($dataset);
            foreach ($check as $key => $value) {
                if ($key !== 'errors') {
                    $this->line(strtoupper($key).'='.($value === true ? 'PASS' : ($value === false ? 'FAIL' : (string) $value)));
                }
            }
            foreach ($check['errors'] as $error) {
                $this->warn($error);
            }
            if (! $check['safe']) {
                $this->error('QMED_CATEGORY_PREFLIGHT=REVIEW');

                return self::FAILURE;
            }
            $this->info('QMED_CATEGORY_PREFLIGHT=PASS');
            if ((bool) $this->option('dry-run')) {
                $this->line('DATABASE_WRITES=0');

                return self::SUCCESS;
            }
            $result = $this->syncer->sync($dataset);
            foreach ($result as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->info('QMED_CATEGORY_SYNC=PASS');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('QMED_CATEGORY_SYNC=FAIL: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
