<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedDraftImporter;
use App\Services\Qmed\QmedImportPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

final class QmedImportPreflightCommand extends Command
{
    protected $signature = 'qmed:import
        {--from=scrapers/qmed/full-product-data.json : Private local JSON dataset.}
        {--dry-run : Run read-only preflight (default unless --write supplied).}
        {--write : Explicitly permit draft-only writes in local/testing environment.}
        {--expected-count=156 : Required complete catalogue count.}
        {--limit= : Maximum selected records; mandatory for --write.}
        {--offset=0 : Starting product offset.}
        {--show-failures : Print preflight failures.}';

    protected $description = 'Validate Qmed catalogue, or explicitly create immutable draft-only products locally.';

    public function __construct(
        private readonly QmedImportPreflight $preflight,
        private readonly QmedDraftImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $write = (bool) $this->option('write');
        if ($write && ((bool) $this->option('dry-run') || ! app()->environment(['local', 'testing']))) {
            $this->error('QMED_DATABASE_WRITES=BLOCKED: --write requires local/testing and cannot be combined with --dry-run.');

            return self::FAILURE;
        }

        $path = (string) $this->option('from');
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            $this->error('Unsafe dataset path.');

            return self::FAILURE;
        }
        if (! Storage::disk('local')->exists($path)) {
            $this->error('Dataset not found: '.$path);

            return self::FAILURE;
        }

        $count = filter_var($this->option('expected-count'), FILTER_VALIDATE_INT);
        $offset = filter_var($this->option('offset'), FILTER_VALIDATE_INT);
        $limitOption = $this->option('limit');
        $limit = $limitOption === null ? null : filter_var($limitOption, FILTER_VALIDATE_INT);
        if (! is_int($count) || $count < 1 || ! is_int($offset) || $offset < 0
            || ($limitOption !== null && (! is_int($limit) || $limit < 1))
            || ($write && $limit === null)) {
            $this->error('Invalid --expected-count, --offset or --limit; --write requires an explicit positive --limit.');

            return self::FAILURE;
        }

        try {
            $dataset = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($dataset)) {
                throw new JsonException('JSON dataset must be an object.');
            }
            $result = $this->preflight->inspect($dataset, $count);
        } catch (Throwable $e) {
            $this->error('QMED_PREFLIGHT_EXCEPTION='.$e->getMessage());

            return self::FAILURE;
        }

        foreach (['products', 'unique_external_ids', 'existing_qmed_records', 'planned_creates', 'planned_updates'] as $key) {
            $this->line(strtoupper($key).'='.($result[$key] ?? 'unknown'));
        }
        $this->line('PREFLIGHT_ERRORS='.count($result['errors'] ?? []));
        if ((bool) $this->option('show-failures')) {
            foreach ($result['errors'] ?? [] as $error) {
                $this->warn($error);
            }
        }

        if (($result['safe'] ?? false) !== true) {
            $this->line('DATABASE_WRITES=0');
            $this->error('QMED_IMPORT_PREFLIGHT=REVIEW');

            return self::FAILURE;
        }

        if (! $write) {
            $this->line('DATABASE_WRITES=0');
            $this->info('QMED_IMPORT_PREFLIGHT=PASS');

            return self::SUCCESS;
        }

        try {
            $imported = $this->importer->import($dataset, $count, $limit, $offset);
        } catch (Throwable $e) {
            $this->line('DATABASE_WRITES=ROLLED_BACK_OR_NOT_STARTED');
            $this->error('QMED_IMPORT=FAIL: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($imported as $key => $value) {
            $this->line(strtoupper($key).'='.$value);
        }
        $this->info('QMED_IMPORT=DRAFT_ONLY_PASS');

        return self::SUCCESS;
    }
}
