<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ProductStatus;
use App\Services\Medi\MediProductDataCrawler;
use App\Services\Medi\MediProductImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

final class RunMediFullCatalogueCommand extends Command
{
    protected $signature = 'medi:full-catalogue
        {--from=scrapers/medi/product-links.json : Product-link discovery JSON path under storage/app.}
        {--runtime-dir=scrapers/medi/runtime : Runtime manifest and product-data batch directory under storage/app.}
        {--batch-size=5 : Number of Medi product URLs to crawl and import per batch.}
        {--start-offset= : Explicit product URL offset for a new runtime.}
        {--resume : Continue from the runtime manifest next offset.}
        {--reset : Delete the configured runtime directory before starting a new runtime.}
        {--max-batches= : Maximum number of batches to process in this invocation.}
        {--status=draft : Product status to assign: draft, active, or archived.}
        {--dry-run : Crawl and persist batches without database writes or image downloads.}
        {--crawl-only : Crawl and persist batches without importing products.}
        {--no-images : Do not download or sync images during import.}
        {--image-limit=5 : Maximum number of images to import per product. Use 0 for no limit.}
        {--timeout=20 : HTTP request timeout in seconds.}
        {--attempts=3 : Number of attempts per Medi product request.}
        {--retry-delay-ms=1500 : Milliseconds to pause before retrying a failed Medi product request.}
        {--request-delay-ms=750 : Milliseconds to pause before each Medi product request.}
        {--continue-on-failure : Advance past partial crawl/import failures and record them in the manifest.}
        {--show-failures : Print crawl and import failures at the end.}';

    protected $description = 'Run the Medi full catalogue crawl and import in resumable, logged batches.';

    public function __construct(
        private readonly MediProductDataCrawler $crawler,
        private readonly MediProductImporter $importer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sourceRelativePath = $this->relativePathOption('from', 'scrapers/medi/product-links.json');
        $runtimeRelativePath = $this->relativePathOption('runtime-dir', 'scrapers/medi/runtime');
        $sourcePath = storage_path('app/'.$sourceRelativePath);
        $runtimePath = storage_path('app/'.$runtimeRelativePath);
        $manifestPath = $runtimePath.'/manifest.json';
        $resume = (bool) $this->option('resume');
        $reset = (bool) $this->option('reset');
        $continueOnFailure = (bool) $this->option('continue-on-failure');

        if ($resume && $reset) {
            throw new InvalidArgumentException('--resume and --reset cannot be used together.');
        }

        if ($reset && is_dir($runtimePath)) {
            File::deleteDirectory($runtimePath);
        }

        $source = $this->loadJson($sourcePath, 'Medi product-link discovery JSON');
        $sourceContents = file_get_contents($sourcePath);

        if ($sourceContents === false) {
            throw new RuntimeException('Unable to read Medi product-link discovery JSON: '.$sourcePath);
        }

        $productUrls = $this->productUrls($source);

        if ($productUrls === []) {
            $this->error('No Medi product URLs were found in storage/app/'.$sourceRelativePath);

            return self::FAILURE;
        }

        $sourceHash = hash('sha256', $sourceContents);
        $batchSize = $this->positiveIntOption('batch-size', 5);
        $dryRun = (bool) $this->option('dry-run');
        $crawlOnly = (bool) $this->option('crawl-only');
        $status = $this->productStatusOption();
        $importImages = ! $dryRun && ! $crawlOnly && ! (bool) $this->option('no-images');
        $imageLimit = $this->imageLimitOption();
        $executionConfiguration = [
            'mode' => $dryRun ? 'dry-run' : ($crawlOnly ? 'crawl-only' : 'database-import'),
            'status' => $status->value,
            'import_images' => $importImages,
            'image_limit' => $imageLimit,
        ];
        $manifest = null;

        if ($resume) {
            if (! is_file($manifestPath)) {
                $this->error('Cannot resume because the runtime manifest does not exist: storage/app/'.$runtimeRelativePath.'/manifest.json');

                return self::FAILURE;
            }

            $manifest = $this->loadJson($manifestPath, 'Medi runtime manifest');
            $this->validateResumeManifest(
                $manifest,
                $sourceHash,
                $batchSize,
                count($productUrls),
                $executionConfiguration,
            );
        } elseif (is_file($manifestPath)) {
            $this->error('A Medi runtime manifest already exists. Use --resume or --reset.');

            return self::FAILURE;
        }

        $manifest ??= $this->newManifest(
            sourceRelativePath: $sourceRelativePath,
            sourceHash: $sourceHash,
            runtimeRelativePath: $runtimeRelativePath,
            totalProductUrls: count($productUrls),
            batchSize: $batchSize,
            startOffset: $this->nullableNonNegativeIntOption('start-offset') ?? 0,
            executionConfiguration: $executionConfiguration,
        );

        $this->configureCrawler();

        $maxBatches = $this->nullablePositiveIntOption('max-batches');
        $nextOffset = (int) ($manifest['next_offset'] ?? 0);
        $processedBatches = 0;
        $hadFailures = (bool) ($manifest['had_failures'] ?? false);

        $this->info('Running Medi full catalogue runtime...');
        $this->line('Source: storage/app/'.$sourceRelativePath);
        $this->line('Runtime: storage/app/'.$runtimeRelativePath);
        $this->line('Total product URLs: '.count($productUrls));
        $this->line('Batch size: '.$batchSize);
        $this->line('Starting offset: '.$nextOffset);
        $this->line('Resume: '.($resume ? 'yes' : 'no'));
        $this->line('Mode: '.($dryRun ? 'dry-run' : ($crawlOnly ? 'crawl-only' : 'database import')));
        $this->line('Status: '.$status->value);
        $this->line('Images: '.($importImages ? 'download and sync' : 'skipped'));
        $this->line('Image limit per product: '.($imageLimit === null ? 'none' : (string) $imageLimit));
        $this->line('Operational log: '.$manifest['log_path']);

        $this->appendLog($manifest, 'runtime_started', [
            'resume' => $resume,
            'next_offset' => $nextOffset,
            'dry_run' => $dryRun,
            'crawl_only' => $crawlOnly,
        ]);
        $this->saveManifest($manifestPath, $manifest);

        while ($nextOffset < count($productUrls)) {
            if ($maxBatches !== null && $processedBatches >= $maxBatches) {
                $manifest['status'] = 'paused';
                $manifest['updated_at'] = now()->toIso8601String();
                $this->appendLog($manifest, 'runtime_paused', ['next_offset' => $nextOffset]);
                $this->saveManifest($manifestPath, $manifest);
                $this->info('Runtime paused after '.$processedBatches.' batch(es). Resume with --resume.');

                return $hadFailures ? self::FAILURE : self::SUCCESS;
            }

            $limit = min($batchSize, count($productUrls) - $nextOffset);
            $batchKey = (string) $nextOffset;
            $batchRelativePath = $runtimeRelativePath.'/product-data/'.$this->batchFileName($nextOffset, $limit);
            $batchPath = storage_path('app/'.$batchRelativePath);
            $batch = is_array($manifest['batches'][$batchKey] ?? null)
                ? $manifest['batches'][$batchKey]
                : $this->newBatch($nextOffset, $limit, $batchRelativePath);

            $this->newLine();
            $this->info(sprintf(
                'Batch %d: product URLs %d-%d of %d',
                $processedBatches + 1,
                $nextOffset + 1,
                $nextOffset + $limit,
                count($productUrls),
            ));

            $batch['attempts'] = ((int) ($batch['attempts'] ?? 0)) + 1;
            $batch['started_at'] = now()->toIso8601String();
            $batch['status'] = 'running';
            $manifest['batches'][$batchKey] = $batch;
            $manifest['status'] = 'running';
            $manifest['updated_at'] = now()->toIso8601String();
            $this->appendLog($manifest, 'batch_started', [
                'offset' => $nextOffset,
                'limit' => $limit,
                'attempt' => $batch['attempts'],
            ]);
            $this->saveManifest($manifestPath, $manifest);

            $productData = null;
            $reuseBatchData = in_array($batch['previous_status'] ?? null, ['crawl_complete', 'import_failed'], true)
                && is_file($batchPath);

            if ($reuseBatchData) {
                $this->line('Reusing saved product-data batch: storage/app/'.$batchRelativePath);
                $productData = $this->loadJson($batchPath, 'Medi product-data batch JSON');
            } else {
                $productData = $this->crawler->crawlFromProductLinkDiscovery($source, $limit, $nextOffset);
                unset($productData['product_link_discovery']);
                $productData['product_link_discovery_path'] = 'storage/app/'.$sourceRelativePath;
                $this->saveJson($batchPath, $productData);
                $this->line('Saved product-data batch to storage/app/'.$batchRelativePath);
            }

            $crawlFailures = $this->stringMap($productData['failed_urls'] ?? []);
            $crawlStoppedEarly = (bool) ($productData['stopped_early'] ?? false);
            $batch['source_url_count'] = (int) ($productData['source_product_url_count'] ?? $limit);
            $batch['product_count'] = count(is_array($productData['products'] ?? null) ? $productData['products'] : []);
            $batch['crawl_failure_count'] = count($crawlFailures);
            $batch['crawl_failures'] = $crawlFailures;
            $batch['crawl_warning_count'] = count(is_array($productData['warnings'] ?? null) ? $productData['warnings'] : []);
            $batch['error'] = null;
            $batch['previous_status'] = 'crawl_complete';
            $batch['status'] = 'crawl_complete';
            $manifest['batches'][$batchKey] = $batch;
            $manifest['counts']['products_crawled'] = $this->sumBatchField($manifest, 'product_count');
            $manifest['counts']['crawl_failures'] = $this->sumBatchField($manifest, 'crawl_failure_count');
            $manifest['updated_at'] = now()->toIso8601String();
            $this->saveManifest($manifestPath, $manifest);

            if ($crawlFailures !== [] || $crawlStoppedEarly) {
                $hadFailures = true;
                $manifest['had_failures'] = true;
                $batch['status'] = 'crawl_failed';
                $batch['previous_status'] = 'crawl_failed';
                $batch['error'] = $crawlStoppedEarly
                    ? (string) ($productData['stop_reason'] ?? 'Medi product crawl stopped early')
                    : 'One or more Medi product URLs failed to crawl.';
                $manifest['batches'][$batchKey] = $batch;
                $this->appendLog($manifest, 'batch_crawl_failed', [
                    'offset' => $nextOffset,
                    'failures' => $crawlFailures,
                    'stopped_early' => $crawlStoppedEarly,
                ]);

                if (! $continueOnFailure) {
                    $manifest['status'] = 'failed';
                    $manifest['next_offset'] = $nextOffset;
                    $manifest['updated_at'] = now()->toIso8601String();
                    $this->saveManifest($manifestPath, $manifest);
                    $this->error('Batch crawl failed. The next offset was retained for a resumable retry.');
                    $this->maybePrintFailures($manifest);

                    return self::FAILURE;
                }
            }

            $importFailures = [];
            $importWarnings = [];
            $importedCount = 0;

            if (! $dryRun && ! $crawlOnly) {
                $products = is_array($productData['products'] ?? null) ? $productData['products'] : [];
                $this->line('Importing '.$batch['product_count'].' crawled product(s)...');

                foreach ($products as $index => $scraped) {
                    if (! is_array($scraped)) {
                        continue;
                    }

                    $name = $this->productName($scraped);
                    $this->line(sprintf('  %d/%d %s', $index + 1, count($products), $name));

                    try {
                        $result = $this->importer->import($scraped, $status, $importImages, $imageLimit);
                        $importedCount++;

                        foreach ($result['warnings'] as $warning) {
                            $importWarnings[] = [
                                'product' => $name,
                                'warning' => $warning,
                            ];
                            $this->warn('    '.$warning);
                        }
                    } catch (Throwable $exception) {
                        $importFailures[] = [
                            'product' => $name,
                            'url' => $this->productUrl($scraped),
                            'error' => $exception->getMessage(),
                        ];
                        $this->error('    Failed: '.$exception->getMessage());
                    }
                }
            }

            $batch['imported_count'] = $importedCount;
            $batch['import_failure_count'] = count($importFailures);
            $batch['import_failures'] = $importFailures;
            $batch['import_warning_count'] = count($importWarnings);
            $batch['import_warnings'] = $importWarnings;
            $batch['completed_at'] = now()->toIso8601String();

            if ($importFailures !== []) {
                $hadFailures = true;
                $manifest['had_failures'] = true;
                $batch['status'] = 'import_failed';
                $batch['previous_status'] = 'import_failed';
                $manifest['batches'][$batchKey] = $batch;
                $manifest['counts']['products_imported'] = $this->sumBatchField($manifest, 'imported_count');
                $manifest['counts']['import_failures'] = $this->sumBatchField($manifest, 'import_failure_count');
                $this->appendLog($manifest, 'batch_import_failed', [
                    'offset' => $nextOffset,
                    'failures' => $importFailures,
                ]);

                if (! $continueOnFailure) {
                    $manifest['status'] = 'failed';
                    $manifest['next_offset'] = $nextOffset;
                    $manifest['updated_at'] = now()->toIso8601String();
                    $this->saveManifest($manifestPath, $manifest);
                    $this->error('Batch import failed. Saved product data will be reused on the next --resume run.');
                    $this->maybePrintFailures($manifest);

                    return self::FAILURE;
                }
            }

            $batch['status'] = $dryRun ? 'dry_run_complete' : ($crawlOnly ? 'crawl_only_complete' : 'completed');
            $batch['previous_status'] = $batch['status'];
            $manifest['batches'][$batchKey] = $batch;
            $nextOffset += max(1, (int) $batch['source_url_count']);
            $manifest['next_offset'] = min($nextOffset, count($productUrls));
            $manifest['counts']['batches_completed'] = $this->completedBatchCount($manifest);
            $manifest['counts']['products_crawled'] = $this->sumBatchField($manifest, 'product_count');
            $manifest['counts']['products_imported'] = $this->sumBatchField($manifest, 'imported_count');
            $manifest['counts']['crawl_failures'] = $this->sumBatchField($manifest, 'crawl_failure_count');
            $manifest['counts']['import_failures'] = $this->sumBatchField($manifest, 'import_failure_count');
            $hadFailures = $manifest['counts']['crawl_failures'] > 0 || $manifest['counts']['import_failures'] > 0;
            $manifest['had_failures'] = $hadFailures;
            $manifest['updated_at'] = now()->toIso8601String();
            $this->appendLog($manifest, 'batch_completed', [
                'offset' => (int) $batch['offset'],
                'next_offset' => $manifest['next_offset'],
                'products_crawled' => $batch['product_count'],
                'products_imported' => $batch['imported_count'],
            ]);
            $this->saveManifest($manifestPath, $manifest);

            $this->info(sprintf(
                'Batch complete: crawled %d, imported %d, crawl failures %d, import failures %d',
                $batch['product_count'],
                $batch['imported_count'],
                $batch['crawl_failure_count'],
                $batch['import_failure_count'],
            ));

            $processedBatches++;
        }

        $manifest['status'] = $hadFailures ? 'completed_with_failures' : 'completed';
        $manifest['completed_at'] = now()->toIso8601String();
        $manifest['updated_at'] = $manifest['completed_at'];
        $this->appendLog($manifest, 'runtime_completed', [
            'status' => $manifest['status'],
            'counts' => $manifest['counts'],
        ]);
        $this->saveManifest($manifestPath, $manifest);

        $this->newLine();
        $this->info('Medi full catalogue runtime finished with status: '.$manifest['status']);
        $this->line('Next offset: '.$manifest['next_offset'].'/'.$manifest['total_product_urls']);
        $this->line('Completed batches: '.$manifest['counts']['batches_completed']);
        $this->line('Products crawled: '.$manifest['counts']['products_crawled']);
        $this->line('Products imported: '.$manifest['counts']['products_imported']);
        $this->line('Crawl failures: '.$manifest['counts']['crawl_failures']);
        $this->line('Import failures: '.$manifest['counts']['import_failures']);
        $this->maybePrintFailures($manifest);

        return $hadFailures ? self::FAILURE : self::SUCCESS;
    }

    private function configureCrawler(): void
    {
        $this->crawler
            ->withTimeout($this->positiveIntOption('timeout', 20))
            ->withRetry(
                $this->positiveIntOption('attempts', 3),
                $this->nonNegativeIntOption('retry-delay-ms', 1500),
            )
            ->withRequestDelayMilliseconds($this->nonNegativeIntOption('request-delay-ms', 750))
            ->withProgressCallback(fn (string $message): null => $this->line('  '.$message));
    }

    /**
     * @return array<string, mixed>
     */
    private function newManifest(
        string $sourceRelativePath,
        string $sourceHash,
        string $runtimeRelativePath,
        int $totalProductUrls,
        int $batchSize,
        int $startOffset,
        array $executionConfiguration,
    ): array {
        $runId = now()->format('Ymd-His').'-'.bin2hex(random_bytes(3));
        $startOffset = min(max(0, $startOffset), $totalProductUrls);

        return [
            'version' => 1,
            'source' => 'medi',
            'run_id' => $runId,
            'status' => 'pending',
            'source_path' => 'storage/app/'.$sourceRelativePath,
            'source_sha256' => $sourceHash,
            'runtime_path' => 'storage/app/'.$runtimeRelativePath,
            'log_path' => 'storage/logs/medi/full-catalogue-'.$runId.'.jsonl',
            'total_product_urls' => $totalProductUrls,
            'batch_size' => $batchSize,
            'execution_configuration' => $executionConfiguration,
            'start_offset' => $startOffset,
            'next_offset' => $startOffset,
            'had_failures' => false,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'completed_at' => null,
            'counts' => [
                'batches_completed' => 0,
                'products_crawled' => 0,
                'products_imported' => 0,
                'crawl_failures' => 0,
                'import_failures' => 0,
            ],
            'batches' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function newBatch(int $offset, int $limit, string $relativePath): array
    {
        return [
            'offset' => $offset,
            'limit' => $limit,
            'status' => 'pending',
            'previous_status' => null,
            'attempts' => 0,
            'product_data_path' => 'storage/app/'.$relativePath,
            'source_url_count' => 0,
            'product_count' => 0,
            'imported_count' => 0,
            'crawl_failure_count' => 0,
            'import_failure_count' => 0,
            'crawl_failures' => [],
            'import_failures' => [],
            'crawl_warning_count' => 0,
            'import_warning_count' => 0,
            'import_warnings' => [],
            'error' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateResumeManifest(
        array $manifest,
        string $sourceHash,
        int $batchSize,
        int $totalProductUrls,
        array $executionConfiguration,
    ): void {
        if (($manifest['source'] ?? null) !== 'medi') {
            throw new RuntimeException('Runtime manifest is not a Medi manifest.');
        }

        if (($manifest['source_sha256'] ?? null) !== $sourceHash) {
            throw new RuntimeException('Medi product-link discovery JSON changed since this runtime started. Use --reset to start a new runtime.');
        }

        if ((int) ($manifest['batch_size'] ?? 0) !== $batchSize) {
            throw new RuntimeException('The --batch-size value must match the existing runtime manifest batch size.');
        }

        if ((int) ($manifest['total_product_urls'] ?? -1) !== $totalProductUrls) {
            throw new RuntimeException('Medi product URL count changed since this runtime started. Use --reset to start a new runtime.');
        }

        if (($manifest['execution_configuration'] ?? null) !== $executionConfiguration) {
            throw new RuntimeException('Medi runtime mode, status, or image settings changed. Resume with the original settings or use --reset.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function appendLog(array $manifest, string $event, array $context = []): void
    {
        $logPath = (string) ($manifest['log_path'] ?? '');

        if (! str_starts_with($logPath, 'storage/logs/')) {
            throw new RuntimeException('Invalid Medi runtime log path.');
        }

        $path = storage_path(substr($logPath, strlen('storage/')));
        $this->ensureDirectory(dirname($path));
        $line = json_encode([
            'timestamp' => now()->toIso8601String(),
            'run_id' => $manifest['run_id'] ?? null,
            'event' => $event,
            'context' => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Unable to append Medi runtime operational log: '.$path);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function saveManifest(string $path, array $manifest): void
    {
        $this->saveJson($path, $manifest);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveJson(string $path, array $data): void
    {
        $this->ensureDirectory(dirname($path));
        $temporaryPath = $path.'.tmp';
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        );

        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write temporary Medi runtime JSON: '.$temporaryPath);
        }

        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to publish Medi runtime JSON: '.$path);
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create Medi runtime directory: '.$path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException($label.' does not exist: '.$path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Unable to read '.$label.': '.$path);
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($label.' is invalid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException($label.' does not contain a JSON object: '.$path);
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<string>
     */
    private function productUrls(array $source): array
    {
        $values = is_array($source['product_urls'] ?? null) ? $source['product_urls'] : [];

        if ($values === [] && is_array($source['products'] ?? null)) {
            foreach ($source['products'] as $product) {
                if (is_array($product) && is_string($product['url'] ?? null)) {
                    $values[] = $product['url'];
                }
            }
        }

        $urls = [];
        $seen = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $url = trim($value);

            if ($url === '' || isset($seen[$url])) {
                continue;
            }

            $seen[$url] = true;
            $urls[] = $url;
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function sumBatchField(array $manifest, string $field): int
    {
        $sum = 0;

        foreach (($manifest['batches'] ?? []) as $batch) {
            if (is_array($batch)) {
                $sum += (int) ($batch[$field] ?? 0);
            }
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function completedBatchCount(array $manifest): int
    {
        $count = 0;

        foreach (($manifest['batches'] ?? []) as $batch) {
            if (! is_array($batch)) {
                continue;
            }

            if (in_array($batch['status'] ?? null, ['completed', 'dry_run_complete', 'crawl_only_complete'], true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function maybePrintFailures(array $manifest): void
    {
        if (! (bool) $this->option('show-failures')) {
            return;
        }

        $printedHeading = false;

        foreach (($manifest['batches'] ?? []) as $batch) {
            if (! is_array($batch)) {
                continue;
            }

            foreach (($batch['crawl_failures'] ?? []) as $url => $reason) {
                if (! $printedHeading) {
                    $this->newLine();
                    $this->warn('Medi runtime failures:');
                    $printedHeading = true;
                }

                $this->line('- crawl '.$url.' — '.$reason);
            }

            foreach (($batch['import_failures'] ?? []) as $failure) {
                if (! is_array($failure)) {
                    continue;
                }

                if (! $printedHeading) {
                    $this->newLine();
                    $this->warn('Medi runtime failures:');
                    $printedHeading = true;
                }

                $this->line('- import '.($failure['product'] ?? '[unknown product]').' — '.($failure['error'] ?? 'unknown error'));
            }
        }
    }

    private function batchFileName(int $offset, int $limit): string
    {
        $end = $offset + $limit - 1;

        return sprintf('batch-%06d-%06d.json', $offset, $end);
    }

    private function productName(array $scraped): string
    {
        return is_string($scraped['name'] ?? null) && trim($scraped['name']) !== ''
            ? trim($scraped['name'])
            : '[unnamed Medi product]';
    }

    private function productUrl(array $scraped): ?string
    {
        foreach (['canonical_url', 'source_url'] as $key) {
            if (is_string($scraped[$key] ?? null) && trim($scraped[$key]) !== '') {
                return trim($scraped[$key]);
            }
        }

        return null;
    }

    private function relativePathOption(string $name, string $default): string
    {
        $value = $this->option($name);
        $path = is_string($value) && trim($value) !== '' ? trim($value) : $default;
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new InvalidArgumentException('Invalid --'.$name.' relative storage path.');
        }

        return rtrim($path, '/');
    }

    private function productStatusOption(): ProductStatus
    {
        $value = trim((string) $this->option('status'));
        $status = ProductStatus::tryFrom($value !== '' ? $value : ProductStatus::DRAFT->value);

        if ($status === null) {
            throw new InvalidArgumentException('Invalid --status value. Use draft, active, or archived.');
        }

        return $status;
    }

    private function positiveIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(1, (int) $value);
    }

    private function nonNegativeIntOption(string $name, int $default): int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function nullablePositiveIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function nullableNonNegativeIntOption(string $name): ?int
    {
        $value = $this->option($name);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return max(0, (int) $value);
    }

    private function imageLimitOption(): ?int
    {
        $value = $this->option('image-limit');

        if (! is_string($value) || trim($value) === '') {
            return 5;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }
}
