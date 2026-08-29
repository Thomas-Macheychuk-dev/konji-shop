<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Neoxmed\NeoxmedPricedMapBuilder;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class BuildNeoxmedPricedMapCommand extends Command
{
    protected $signature = 'neoxmed:priced-map
        {--from=scrapers/neoxmed/import-map.json : Frozen NeoxMed structural import map under storage/app.}
        {--approvals=scrapers/neoxmed/commercial-approvals.json : Explicit NeoxMed commercial approvals under storage/app.}
        {--save=scrapers/neoxmed/priced-map.json : Save validated priced map under storage/app.}
        {--write-template : Generate the 77-row commercial approvals template and stop.}
        {--overwrite-template : Allow --write-template to replace an existing approvals file.}
        {--json : Print the complete template/priced map JSON.}
        {--show-review : Print blocking and non-blocking review items.}';

    protected $description = 'Build a NeoxMed priced import map from explicit human-approved PLN prices, VAT and missing-media approvals without database writes.';

    public function __construct(private readonly NeoxmedPricedMapBuilder $builder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $importPath = $this->storageAppPath((string) $this->option('from'));
        $importRaw = $this->readFile($importPath, 'NeoxMed import map');
        $importMap = $this->decode($importRaw, $importPath);
        $importSha = hash('sha256', $importRaw);
        $approvalsRelative = (string) $this->option('approvals');
        $approvalsPath = $this->storageAppPath($approvalsRelative);

        if ((bool) $this->option('write-template')) {
            if (is_file($approvalsPath) && ! (bool) $this->option('overwrite-template')) {
                throw new RuntimeException('NeoxMed commercial approvals file already exists; refusing to overwrite without --overwrite-template: '.$approvalsPath);
            }

            $template = $this->builder->buildApprovalTemplate($importMap, $importSha);
            $this->saveJson($approvalsPath, $template);

            if ((bool) $this->option('json')) {
                $this->line($this->encode($template));
            } else {
                $this->info('Generated NeoxMed commercial approvals template.');
                $this->line('Import map: '.$importPath);
                $this->line('Import map SHA-256: '.$importSha);
                $this->line('Approval rows: '.count($template['products'] ?? []));
                $this->line('Database writes: NO');
                $this->line('Template: '.$approvalsPath);
                $this->warn('Fill explicit net/gross PLN prices and VAT for every row. Do not guess values. K-01 also requires an approved HTTPS product-media URL.');
            }

            return self::SUCCESS;
        }

        $approvalRaw = $this->readFile($approvalsPath, 'NeoxMed commercial approvals');
        $approvals = $this->decode($approvalRaw, $approvalsPath);
        $result = $this->builder->build($importMap, $importSha, $approvals, hash('sha256', $approvalRaw));

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->printSummary($result, $importPath, $approvalsPath);
        }

        $save = trim((string) $this->option('save'));
        if ($save !== '') {
            $this->saveJson($this->storageAppPath($save), $result);
            if (! (bool) $this->option('json')) {
                $this->info('Saved priced mapping to '.$this->storageAppPath($save));
            }
        }

        return ($result['errors'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function printSummary(array $result, string $importPath, string $approvalsPath): void
    {
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];

        $this->info('NeoxMed priced mapping dry-run');
        $this->line('Import map: '.$importPath);
        $this->line('Commercial approvals: '.$approvalsPath);
        $this->line('Database writes: NO');
        $this->line('Images downloaded: NO');
        $this->line('Source products: '.($result['source_product_count'] ?? 0));
        $this->line('Approval rows: '.($result['approval_row_count'] ?? 0));
        $this->line('Mapped products: '.($result['mapped_product_count'] ?? 0));
        $this->line('Approved products: '.($summary['approved_products'] ?? 0));
        $this->line('Products without approved price: '.($summary['products_without_price'] ?? 0));
        $this->line('Products without approved VAT: '.($summary['products_without_vat'] ?? 0));
        $this->line('Gross/VAT mismatches: '.($summary['gross_vat_mismatches'] ?? 0));
        $this->line('Required media missing: '.($summary['required_media_missing'] ?? 0));
        $this->line('Approved media overrides: '.($summary['media_overrides'] ?? 0));
        $this->line('Currency: '.($summary['currency'] ?? 'PLN'));
        $this->line('VAT distribution: '.json_encode($summary['vat_rate_counts'] ?? [], JSON_UNESCAPED_SLASHES));
        $this->line('Hard errors: '.count($result['errors'] ?? []));
        $this->line('Blocking review items: '.count($result['blocking_review_items'] ?? []));
        $this->line('Ready for database write: '.(($result['ready_for_database_write'] ?? false) ? 'YES' : 'NO'));

        if (($result['errors'] ?? []) !== []) {
            $this->newLine();
            $this->error('Hard errors:');
            foreach ($result['errors'] as $error) {
                $this->line('- '.$error);
            }
        }

        if ((bool) $this->option('show-review')) {
            if (($result['blocking_review_items'] ?? []) !== []) {
                $this->newLine();
                $this->warn('Blocking review items:');
                foreach ($result['blocking_review_items'] as $item) {
                    $this->line('- '.$item);
                }
            }

            if (($result['review_items'] ?? []) !== []) {
                $this->newLine();
                $this->warn('Review items:');
                foreach ($result['review_items'] as $item) {
                    $this->line('- '.$item);
                }
            }
        }

        $this->newLine();
        if (($result['ready_for_database_write'] ?? false) === true) {
            $this->info('PASS: NeoxMed priced map is commercially complete and ready for a separate local database-write implementation patch.');
        } elseif (($result['errors'] ?? []) === []) {
            $this->warn('PASS WITH REVIEW: approvals are structurally valid but commercial/media blockers remain; database writes stay disabled.');
        } else {
            $this->error('FAIL: correct the commercial approval errors before any NeoxMed database-write implementation.');
        }
    }

    private function readFile(string $path, string $label): string
    {
        if (! is_file($path)) {
            throw new RuntimeException($label.' JSON not found: '.$path);
        }

        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException('Unable to read '.$label.' JSON: '.$path);
        }

        return $contents;
    }

    /** @return array<string, mixed> */
    private function decode(string $raw, string $path): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON at '.$path.': '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('JSON root must be an object: '.$path);
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function saveJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create NeoxMed priced-map directory: '.$directory);
        }

        if (file_put_contents($path, $this->encode($data), LOCK_EX) === false) {
            throw new RuntimeException('Unable to save NeoxMed JSON: '.$path);
        }
    }

    private function storageAppPath(string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', ltrim(trim($relativePath), '/'));
        if ($relativePath === '' || in_array('..', explode('/', $relativePath), true)) {
            throw new RuntimeException('NeoxMed storage/app relative path is empty or unsafe.');
        }

        return storage_path('app/'.$relativePath);
    }

    /** @param array<string, mixed> $data */
    private function encode(array $data): string
    {
        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode NeoxMed priced-map JSON.');
        }

        return $encoded;
    }
}
