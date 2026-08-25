<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Armedical\ArmedicalProductionPreflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;

final class PreflightArmedicalProductionCommand extends Command
{
    public const APPROVED_PRICED_MAP_SHA256 = '9617b3d1a5d549c7b590ea6c252cd0ded430cf1a31571bb8853c6dbe20a2ad20';
    public const APPROVED_PRODUCT_DATA_SHA256 = '05e939acaa6251e8c9e5abfd14383a2b85d5b471db556868b5040b631c434da8';
    public const APPROVED_SUPPLIER_XLS_SHA256 = 'ac97003ad885025e665961d05afe1ed2d74d88a53b4aa9b413896f292a282893';

    protected $signature = 'armedical:production-preflight
        {--from=scrapers/armedical/import-map-priced.json : Frozen priced ARmedical map. Relative paths resolve on the Laravel local disk.}
        {--expected-source-products=200}
        {--expected-planned-variants=506}
        {--expected-eligible-products=187}
        {--expected-eligible-variants=459}
        {--expected-excluded-products=13}
        {--expected-unmatched-variants=47}
        {--expected-images=923}
        {--expected-documents=318}
        {--expected-vat-8-variants=451}
        {--expected-vat-23-variants=8}
        {--expected-review-items=6}
        {--expected-blocking-review-items=1}
        {--expected-supplier-rows=245}
        {--expected-supplier-unique-codes=241}
        {--expected-existing-products=0}
        {--expected-existing-variants=0}
        {--expected-existing-images=0}
        {--expected-existing-document-links=0}
        {--expected-sha256='.self::APPROVED_PRICED_MAP_SHA256.'}
        {--expected-product-data-sha256='.self::APPROVED_PRODUCT_DATA_SHA256.'}
        {--expected-supplier-xls-sha256='.self::APPROVED_SUPPLIER_XLS_SHA256.'}
        {--minimum-free-mib=500 : Minimum free space required on the production public storage volume.}
        {--probe-images=3 : Number of eligible source image URLs to fetch into memory. Use 0 to skip.}
        {--probe-documents=3 : Number of eligible source document URLs to fetch into memory. Use 0 to skip.}
        {--probe-timeout=20 : Timeout in seconds for each network probe.}
        {--allow-non-production : Permit a local/staging rehearsal.}
        {--save= : Optional evidence JSON path on the Laravel local disk. No report is written unless supplied.}
        {--show-checks : Print every preflight check.}
        {--show-review : Print review items retained from the priced map.}';

    protected $description = 'Run the read-only production readiness gate for the frozen resolved ARmedical cohort.';

    public function __construct(private readonly ArmedicalProductionPreflight $preflight)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! app()->environment('production', 'testing') && ! (bool) $this->option('allow-non-production')) {
            $this->error('BLOCKED: armedical:production-preflight is intended for production. Use --allow-non-production only for a rehearsal.');
            return self::FAILURE;
        }

        $path = $this->resolvePath((string) $this->option('from'));
        $map = $this->loadMap($path);
        if ($map === null) {
            return self::FAILURE;
        }

        $sha256 = hash_file('sha256', $path);
        if (! is_string($sha256) || $sha256 === '') {
            $this->error('Unable to calculate ARmedical priced-map SHA-256: '.$path);
            return self::FAILURE;
        }

        $expected = [
            'source_products' => $this->nonNegativeInt('expected-source-products', 200),
            'planned_variants' => $this->nonNegativeInt('expected-planned-variants', 506),
            'eligible_products' => $this->nonNegativeInt('expected-eligible-products', 187),
            'eligible_variants' => $this->nonNegativeInt('expected-eligible-variants', 459),
            'excluded_products' => $this->nonNegativeInt('expected-excluded-products', 13),
            'unmatched_variants' => $this->nonNegativeInt('expected-unmatched-variants', 47),
            'images' => $this->nonNegativeInt('expected-images', 923),
            'documents' => $this->nonNegativeInt('expected-documents', 318),
            'vat_8_variants' => $this->nonNegativeInt('expected-vat-8-variants', 451),
            'vat_23_variants' => $this->nonNegativeInt('expected-vat-23-variants', 8),
            'review_items' => $this->nonNegativeInt('expected-review-items', 6),
            'blocking_review_items' => $this->nonNegativeInt('expected-blocking-review-items', 1),
            'supplier_rows' => $this->nonNegativeInt('expected-supplier-rows', 245),
            'supplier_unique_codes' => $this->nonNegativeInt('expected-supplier-unique-codes', 241),
            'existing_products' => $this->nonNegativeInt('expected-existing-products', 0),
            'existing_variants' => $this->nonNegativeInt('expected-existing-variants', 0),
            'existing_images' => $this->nonNegativeInt('expected-existing-images', 0),
            'existing_document_links' => $this->nonNegativeInt('expected-existing-document-links', 0),
            'sha256' => $this->nullableString($this->option('expected-sha256')),
            'product_data_sha256' => $this->nullableString($this->option('expected-product-data-sha256')),
            'supplier_xls_sha256' => $this->nullableString($this->option('expected-supplier-xls-sha256')),
        ];

        $report = $this->preflight->inspect(
            map: $map,
            expected: $expected,
            mapSha256: $sha256,
            minimumFreeMiB: $this->nonNegativeInt('minimum-free-mib', 500),
            probeImageCount: $this->nonNegativeInt('probe-images', 3),
            probeDocumentCount: $this->nonNegativeInt('probe-documents', 3),
            probeTimeoutSeconds: max(1, $this->nonNegativeInt('probe-timeout', 20)),
        );

        $metrics = is_array($report['metrics'] ?? null) ? $report['metrics'] : [];
        $errors = array_values(array_filter($report['errors'] ?? [], 'is_string'));
        $review = array_values(array_filter($report['review_items'] ?? [], 'is_string'));
        $sourceBlocking = array_values(array_filter($report['source_blocking_review_items'] ?? [], 'is_string'));

        $this->info('ARmedical production readiness preflight');
        $this->line('Environment: '.app()->environment());
        $this->line('Source: '.$path);
        $this->line('Priced-map SHA-256: '.$sha256);
        $this->line('Database writes: NO');
        $this->line('Filesystem writes: NO');
        $this->line('Network probes: in-memory only');
        $this->line('Report file writes: '.($this->nullableString($this->option('save')) !== null ? 'REQUESTED' : 'NO'));
        $this->newLine();
        $this->line('Source products: '.($metrics['source_products'] ?? 0));
        $this->line('Planned variants: '.($metrics['planned_variants'] ?? 0));
        $this->line('Eligible products: '.($metrics['eligible_products'] ?? 0));
        $this->line('Eligible variants: '.($metrics['eligible_variants'] ?? 0));
        $this->line('Excluded unresolved products: '.($metrics['excluded_products'] ?? 0));
        $this->line('Unmatched variants: '.($metrics['unmatched_variants'] ?? 0));
        $this->line('Eligible mapped images: '.($metrics['images'] ?? 0));
        $this->line('Eligible mapped documents: '.($metrics['documents'] ?? 0));
        $this->line('8% VAT variants: '.($metrics['vat_8_variants'] ?? 0));
        $this->line('23% VAT variants: '.($metrics['vat_23_variants'] ?? 0));
        $this->line('Hard preflight errors: '.count($errors));
        $this->line('Priced-map review items: '.count($review));
        $this->line('Source blocking review items retained in excluded cohort: '.count($sourceBlocking));

        if ((bool) $this->option('show-checks') || $errors !== []) {
            $this->newLine();
            $this->info('Preflight checks:');
            foreach (($report['checks'] ?? []) as $check) {
                if (! is_array($check)) {
                    continue;
                }
                $this->line(sprintf('- [%s] %s | %s', $check['status'] ?? '?', $check['name'] ?? '?', $check['message'] ?? ''));
            }
        }

        if ((bool) $this->option('show-review')) {
            if ($review !== []) {
                $this->newLine();
                $this->warn('Review items:');
                foreach ($review as $item) {
                    $this->line('- '.$item);
                }
            }
            if ($sourceBlocking !== []) {
                $this->newLine();
                $this->warn('Blocking source-data items retained only in excluded products:');
                foreach ($sourceBlocking as $item) {
                    $this->line('- '.$item);
                }
            }
        }

        $save = $this->nullableString($this->option('save'));
        if ($save !== null) {
            $savePath = str_starts_with($save, '/')
                ? $save
                : Storage::disk('local')->path(ltrim($save, '/'));
            $directory = dirname($savePath);
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                $this->error('Unable to create ARmedical preflight report directory: '.$directory);
                return self::FAILURE;
            }
            $payload = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($savePath, $payload) === false) {
                $this->error('Unable to save ARmedical preflight report: '.$savePath);
                return self::FAILURE;
            }
            $this->line('Saved evidence report to '.$savePath);
        }

        if ($errors === []) {
            $this->info('PASS: ARmedical production preflight is ready for the controlled production execution patch. No catalogue or media writes were performed.');
            return self::SUCCESS;
        }

        $this->error('FAIL: ARmedical production preflight has hard errors. Do not perform production ARmedical writes.');
        return self::FAILURE;
    }

    /** @return array<string,mixed>|null */
    private function loadMap(string $path): ?array
    {
        if (! is_file($path)) {
            $this->error('ARmedical priced import-map not found: '.$path);
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('Invalid ARmedical priced import-map JSON: '.$exception->getMessage());
            return null;
        }
        if (! is_array($decoded) || ($decoded['source'] ?? null) !== 'armedical') {
            $this->error('ARmedical priced import-map root/source is invalid.');
            return null;
        }
        return $decoded;
    }

    private function resolvePath(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            $value = 'scrapers/armedical/import-map-priced.json';
        }
        if (str_starts_with($value, '/')) {
            return $value;
        }

        $relative = ltrim($value, '/');
        $localPath = Storage::disk('local')->path($relative);

        if (is_file($localPath)) {
            return $localPath;
        }

        // Compatibility fallback for older deployments that placed scraper
        // artefacts directly under storage/app instead of the local disk root.
        return storage_path('app/'.$relative);
    }

    private function nonNegativeInt(string $name, int $default): int
    {
        $value = $this->option($name);
        return is_numeric($value) ? max(0, (int) $value) : $default;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }
}
