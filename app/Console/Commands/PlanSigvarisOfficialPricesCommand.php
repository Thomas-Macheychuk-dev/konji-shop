<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sigvaris\SigvarisOfficialPricePlanner;
use Illuminate\Console\Command;
use JsonException;

final class PlanSigvarisOfficialPricesCommand extends Command
{
    protected $signature = 'sigvaris:price-plan
        {--from=scrapers/sigvaris/import-map.json : Approved Sigvaris import-map JSON path. Relative paths resolve under storage/app.}
        {--expected-sha256= : Exact approved Sigvaris import-map SHA-256. Required.}
        {--save=scrapers/sigvaris/official-price-plan-2026-01-21.json : Evidence JSON path. Relative paths resolve under storage/app. Use an empty value to skip saving.}
        {--show-unmatched : Print all products that cannot be priced deterministically from the supplied official lists.}';

    protected $description = 'Build a read-only Sigvaris price plan from the official 21 January 2026 price lists; never writes catalogue prices.';

    public function __construct(
        private readonly SigvarisOfficialPricePlanner $planner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $sourcePath = $this->resolvePath((string) $this->option('from'));
        $expectedSha256 = $this->normalizedSha256((string) ($this->option('expected-sha256') ?? ''));

        $this->info('Sigvaris official price plan');
        $this->line('Database writes: NO');
        $this->line('Network requests: NO');
        $this->line('Price-list effective date: 2026-01-21');
        $this->line('Formula: (base net + (base net × VAT)) × 1.20');
        $this->line('Source: '.$sourcePath);

        if ($expectedSha256 === null) {
            $this->error('BLOCKED: --expected-sha256 must be a 64-character SHA-256 fingerprint.');

            return self::FAILURE;
        }

        if (! hash_equals(SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256, $expectedSha256)) {
            $this->error('BLOCKED: this price plan is tied to the approved Sigvaris import-map fingerprint.');
            $this->line('Planner fingerprint: '.SigvarisOfficialPricePlanner::IMPORT_MAP_SHA256);
            $this->line('Provided fingerprint: '.$expectedSha256);

            return self::FAILURE;
        }

        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            $this->error('Unable to read Sigvaris import map: '.$sourcePath);

            return self::FAILURE;
        }

        $actualSha256 = hash_file('sha256', $sourcePath);

        if (! is_string($actualSha256)) {
            $this->error('Unable to calculate Sigvaris import-map SHA-256.');

            return self::FAILURE;
        }

        $actualSha256 = strtolower($actualSha256);
        $this->line('Import-map SHA-256: '.$actualSha256);

        if (! hash_equals($expectedSha256, $actualSha256)) {
            $this->error('BLOCKED: Sigvaris import-map SHA-256 does not match the approved fingerprint.');
            $this->line('Expected: '.$expectedSha256);
            $this->line('Actual:   '.$actualSha256);

            return self::FAILURE;
        }

        $map = $this->loadJson($sourcePath);

        if ($map === null) {
            return self::FAILURE;
        }

        try {
            $plan = $this->planner->build($map);
        } catch (\Throwable $exception) {
            $this->error('Unable to build Sigvaris official price plan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $plan['import_map_sha256'] = $actualSha256;
        $plan['generated_at'] = now()->toIso8601String();

        $this->newLine();
        $this->info('=== SIGVARIS OFFICIAL PRICE PLAN RESULT ===');
        $this->line('Mapped products: '.count(array_filter($map['products'] ?? [], 'is_array')));
        $this->line('Matched products: '.$plan['matched_product_count'].'/226');
        $this->line('Matched variants: '.$plan['matched_variant_count'].'/14991');
        $this->line('Unmatched products: '.$plan['unmatched_product_count']);
        $this->line('Unmatched variants: '.$plan['unmatched_variant_count']);
        $this->line('Planning errors: '.$plan['error_count']);
        $this->line('Ready for price-write implementation: '.($plan['ready_for_price_write_implementation'] ? 'YES' : 'NO'));

        if ((bool) $this->option('show-unmatched') && $plan['unmatched_products'] !== []) {
            $this->newLine();
            $this->warn('Products not deterministically covered by the supplied price lists:');

            foreach ($plan['unmatched_products'] as $product) {
                $this->line(sprintf(
                    '- %s | %s | variants=%d | %s',
                    $product['external_id'],
                    $product['name'],
                    $product['variant_count'],
                    $product['reason'],
                ));
            }
        }

        if ($plan['errors'] !== []) {
            $this->newLine();
            $this->error('Planning errors:');

            foreach ($plan['errors'] as $error) {
                $this->line('- '.$error);
            }
        }

        $saveOption = trim((string) ($this->option('save') ?? ''));

        if ($saveOption !== '') {
            $savePath = $this->resolvePath($saveOption);

            if (! $this->saveJson($savePath, $plan)) {
                return self::FAILURE;
            }

            $this->line('Saved evidence JSON to '.$savePath);
        }

        if ($plan['error_count'] > 0) {
            $this->error('FAIL: hard price-planning errors exist. No catalogue writes were performed.');

            return self::FAILURE;
        }

        if ($plan['unmatched_product_count'] > 0) {
            $this->warn('PASS WITH REVIEW: deterministic prices were planned for the covered catalogue, but unmatched products remain.');
            $this->warn('No catalogue writes were performed. Do not implement a full price write until the unmatched products are explicitly resolved or excluded.');

            return self::SUCCESS;
        }

        $this->info('PASS: all Sigvaris products are deterministically priced from the supplied official lists. No catalogue writes were performed.');

        return self::SUCCESS;
    }

    private function resolvePath(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return storage_path('app/scrapers/sigvaris/import-map.json');
        }

        return str_starts_with($value, '/')
            ? $value
            : storage_path('app/'.ltrim($value, '/'));
    }

    /** @return array<string, mixed>|null */
    private function loadJson(string $path): ?array
    {
        try {
            $decoded = json_decode(
                (string) file_get_contents($path),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->error('Invalid JSON in Sigvaris import map: '.$exception->getMessage());

            return null;
        }

        if (! is_array($decoded)) {
            $this->error('Sigvaris import-map root must be a JSON object.');

            return null;
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private function saveJson(string $path, array $data): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error('Unable to create price-plan evidence directory: '.$directory);

            return false;
        }

        try {
            $encoded = json_encode(
                $data,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            $this->error('Unable to encode price-plan evidence JSON: '.$exception->getMessage());

            return false;
        }

        if (! is_string($encoded) || file_put_contents($path, $encoded.PHP_EOL) === false) {
            $this->error('Unable to save price-plan evidence JSON: '.$path);

            return false;
        }

        return true;
    }

    private function normalizedSha256(string $value): ?string
    {
        $value = strtolower(trim($value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }
}
