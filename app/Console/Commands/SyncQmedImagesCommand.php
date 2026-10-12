<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedImageSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SyncQmedImagesCommand extends Command
{
    protected $signature = 'qmed:sync-images
        {--from=scrapers/qmed/full-product-data.json : Local private Qmed dataset.}
        {--dry-run : Perform read-only source and database validation.}
        {--write : Download and attach missing images, local/testing only.}
        {--offset=0 : Index of first product.}
        {--limit=5 : Number of products to select.}';

    protected $description = 'Audit or import Qmed product images in controlled batches.';

    public function __construct(private readonly QmedImageSynchronizer $synchronizer)
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
        $offset = filter_var($this->option('offset'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || ! Storage::disk('local')->exists($path) || ! is_int($offset) || $offset < 0 || ! is_int($limit) || $limit < 1 || $limit > 156 || $offset + $limit > 156) {
            $this->error('Unsafe path or batch arguments.');

            return self::FAILURE;
        }
        try {
            $dataset = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($dataset)) {
                throw new \RuntimeException('Dataset must be a JSON object.');
            }
            $check = $this->synchronizer->inspect($dataset, $offset, $limit);
            foreach (['selected', 'image_references', 'products_to_import', 'unchanged'] as $key) {
                $this->line(strtoupper($key).'='.$check[$key]);
            }
            $this->line('PREFLIGHT_ERRORS='.count($check['errors']));
            foreach ($check['errors'] as $error) {
                $this->warn($error);
            }
            if (! $check['safe']) {
                $this->error('QMED_IMAGE_PREFLIGHT=REVIEW');

                return self::FAILURE;
            }
            $this->info('QMED_IMAGE_PREFLIGHT=PASS');
            if ((bool) $this->option('dry-run')) {
                $this->line('DATABASE_WRITES=0');

                return self::SUCCESS;
            }
            $result = $this->synchronizer->sync($dataset, $offset, $limit);
            foreach ($result as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->info('QMED_IMAGE_SYNC=PASS');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('QMED_IMAGE_SYNC=FAIL: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
