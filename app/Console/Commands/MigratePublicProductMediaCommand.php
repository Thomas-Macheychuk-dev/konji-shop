<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Storage\PublicProductMediaMigrationService;
use Illuminate\Console\Command;
use Throwable;

final class MigratePublicProductMediaCommand extends Command
{
    protected $signature = 'shop:migrate-public-media
        {--source=public-local : Source filesystem disk containing the current public product files}
        {--target=public-s3 : Target filesystem disk}
        {--prefix=products : Path prefix to migrate}
        {--write : Copy objects instead of performing a dry run}
        {--rewrite-descriptions : Replace legacy /storage/products/... links with the configured public target URL}';

    protected $description = 'Safely copy public product media to durable object storage without deleting the source copy.';

    public function handle(PublicProductMediaMigrationService $migration): int
    {
        $source = trim((string) $this->option('source'));
        $target = trim((string) $this->option('target'));
        $prefix = trim((string) $this->option('prefix'));
        $write = (bool) $this->option('write');
        $rewriteDescriptions = (bool) $this->option('rewrite-descriptions');

        try {
            $result = $migration->migrate(
                sourceDisk: $source,
                targetDisk: $target,
                prefix: $prefix,
                write: $write,
                rewriteDescriptions: $rewriteDescriptions,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['Mode', $write ? 'WRITE' : 'DRY RUN'],
                ['Source disk', $source],
                ['Target disk', $target],
                ['Prefix', $prefix],
                ['Source files', $result['source_files']],
                ['Planned copies', $result['planned']],
                ['Copied', $result['copied']],
                ['Already present', $result['already_present']],
                ['Failed', $result['failed']],
                ['Product descriptions updated', $result['descriptions_updated']],
            ],
        );

        foreach ($result['failures'] as $failure) {
            $this->error($failure);
        }

        if ($result['failed'] > 0) {
            return self::FAILURE;
        }

        if (! $write) {
            $this->info('Dry run only. Re-run with --write after reviewing the counts.');
        } else {
            $this->info('Migration completed. Source files were intentionally retained for rollback.');
        }

        return self::SUCCESS;
    }
}
