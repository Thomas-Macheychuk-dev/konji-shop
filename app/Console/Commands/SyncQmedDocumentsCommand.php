<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Qmed\QmedDocumentSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SyncQmedDocumentsCommand extends Command
{
    protected $signature = 'qmed:sync-documents
        {--from=scrapers/qmed/full-product-data.json : Private local Qmed dataset.}
        {--dry-run : Explicitly request read-only preflight (also the default).}
        {--write : Download and persist documents (local/testing only).}
        {--offset=0 : Starting product index.}
        {--limit=5 : Number of products.}
        {--exceptions= : Private, explicitly approved unavailable-document manifest (optional).}';

    protected $description = 'Audit or safely import Qmed manufacturer PDF attachments.';

    public function __construct(private readonly QmedDocumentSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('write') && $this->option('dry-run')) {
            $this->error('Select either --write or --dry-run, not both.');
            return self::FAILURE;
        }
        if ($this->option('write') && ! app()->environment(['local', 'testing'])) {
            $this->error('Document writes are restricted to local/testing.');
            return self::FAILURE;
        }
        $path = (string) $this->option('from');
        $offset = filter_var($this->option('offset'), FILTER_VALIDATE_INT);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')
            || ! preg_match('~^scrapers/qmed/[a-zA-Z0-9/_-]+\.json$~D', $path)
            || ! Storage::disk('local')->exists($path)
            || ! is_int($offset) || $offset < 0 || ! is_int($limit) || $limit < 1
            || $limit > 156 || $offset + $limit > 156) {
            $this->error('Unsafe dataset path or batch parameters.');
            return self::FAILURE;
        }
        try {
            $dataset = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($dataset)) {
                throw new \RuntimeException('Qmed dataset is not an object.');
            }
            $exceptions = [];
            $exceptionPath = (string) ($this->option('exceptions') ?? '');
            if ($exceptionPath !== '') {
                if (! preg_match('~^scrapers/qmed/[a-zA-Z0-9/_-]+\.json$~D', $exceptionPath)
                    || str_contains($exceptionPath, '..')
                    || ! Storage::disk('local')->exists($exceptionPath)) {
                    throw new \RuntimeException('Unsafe exception manifest path.');
                }
                $manifest = json_decode(Storage::disk('local')->get($exceptionPath), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($manifest) || array_keys($manifest) !== ['source', 'unavailable_attachments']
                    || $manifest['source'] !== 'qmed' || ! is_array($manifest['unavailable_attachments'])) {
                    throw new \RuntimeException('Invalid exception manifest envelope.');
                }
                $exceptions = $manifest['unavailable_attachments'];
            }
            $check = $this->synchronizer->inspect($dataset, $offset, $limit, $exceptions);
            foreach (['selected', 'all_references', 'selected_references', 'to_import', 'unchanged', 'unavailable'] as $key) {
                $this->line(strtoupper($key).'='.$check[$key]);
            }
            $this->line('PREFLIGHT_ERRORS='.count($check['errors']));
            foreach ($check['errors'] as $error) {
                $this->warn($error);
            }
            if (! $check['safe']) {
                $this->error('QMED_DOCUMENT_PREFLIGHT=REVIEW');
                return self::FAILURE;
            }
            $this->info('QMED_DOCUMENT_PREFLIGHT=PASS');
            if (! $this->option('write')) {
                $this->line('DATABASE_WRITES=0');
                return self::SUCCESS;
            }
            foreach ($this->synchronizer->sync($dataset, $offset, $limit, $exceptions) as $key => $value) {
                $this->line(strtoupper($key).'='.$value);
            }
            $this->info('QMED_DOCUMENT_SYNC=PASS');
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('QMED_DOCUMENT_SYNC=FAIL: '.$exception->getMessage());
            return self::FAILURE;
        }
    }
}
