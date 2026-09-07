<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Antar\AntarPriceUpdatePlanner;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;

final class PlanAntarPriceUpdateCommand extends Command
{
    protected $signature = 'antar:price-update-plan
        {--save=scrapers/antar/price-update-plan-2026-09-01.json : Save read-only price-plan evidence under storage/app. Use an empty value to skip saving.}
        {--show-review : Print all unresolved/ambiguous supplier price matches.}
        {--show-changes : Print products whose current price/VAT/currency differs from the supplier list.}';

    protected $description = 'Plan Antar catalogue price updates from the supplier list effective 1 September 2026 without database writes.';

    public function __construct(
        private readonly AntarPriceUpdatePlanner $planner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Antar supplier price update plan');
        $this->line('Database writes: NO');
        $this->line('Network requests: NO');

        try {
            $plan = $this->planner->build();
        } catch (\Throwable $exception) {
            $this->error('Unable to build Antar price update plan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $supplier = (array) ($plan['supplier_price_list'] ?? []);
        $supplierSummary = (array) ($plan['supplier_summary'] ?? []);
        $database = (array) ($plan['database_summary'] ?? []);

        $this->line('Supplier source: '.($supplier['source_file'] ?? 'unknown'));
        $this->line('Supplier XLSX SHA-256: '.($supplier['source_sha256'] ?? 'unknown'));
        $this->line('Effective from: '.($supplier['effective_from'] ?? 'unknown'));
        $this->line('Supplier priced rows: '.($supplierSummary['rows'] ?? 0));
        $this->line('Supplier unique normalized codes: '.($supplierSummary['unique_normalized_codes'] ?? 0));
        $this->line('Safe supplier price codes: '.($supplierSummary['safe_price_codes'] ?? 0));
        $this->line('Ambiguous supplier price codes: '.($supplierSummary['ambiguous_price_codes'] ?? 0));
        $this->line('Existing Antar products: '.($database['products'] ?? 0));
        $this->line('Existing default Antar variants: '.($database['default_variants'] ?? 0));
        $this->line('Matched products: '.($database['matched_products'] ?? 0));
        $this->line('Products requiring price/VAT/currency change: '.($database['changed_products'] ?? 0));
        $this->line('Products already current: '.($database['unchanged_products'] ?? 0));
        $this->line('Products without supplier code: '.($database['missing_supplier_code_products'] ?? 0));
        $this->line('Products without deterministic supplier price: '.($database['unmatched_supplier_price_products'] ?? 0));
        $this->line('Products with ambiguous supplier price: '.($database['ambiguous_supplier_price_products'] ?? 0));
        $this->line('Hard planning errors: '.($plan['hard_error_count'] ?? 0));
        $this->line('Ready for controlled price write: '.(($plan['ready_for_price_write'] ?? false) ? 'YES' : 'NO'));

        if ((bool) $this->option('show-changes')) {
            $changes = array_values(array_filter(
                (array) ($plan['products'] ?? []),
                static fn (mixed $product): bool => is_array($product) && ($product['status'] ?? null) === 'change',
            ));

            if ($changes !== []) {
                $this->newLine();
                $this->info('Planned price changes:');

                foreach ($changes as $product) {
                    $current = (array) ($product['current'] ?? []);
                    $proposed = (array) ($product['proposed'] ?? []);
                    $this->line(sprintf(
                        '- %s | %s | current=%s/%s VAT %s | proposed=%s/%s VAT %s PLN',
                        (string) ($product['normalized_supplier_code'] ?? 'NO-CODE'),
                        (string) ($product['product_name'] ?? ''),
                        $this->money($current['price_net_amount'] ?? null),
                        $this->money($current['price_gross_amount'] ?? null),
                        $current['vat_rate'] ?? 'null',
                        $this->money($proposed['price_net_amount'] ?? null),
                        $this->money($proposed['price_gross_amount'] ?? null),
                        $proposed['vat_rate'] ?? 'null',
                    ));
                }
            }
        }

        if ((bool) $this->option('show-review') && ($plan['review_items'] ?? []) !== []) {
            $this->newLine();
            $this->warn('Review items:');

            foreach ((array) $plan['review_items'] as $reviewItem) {
                $this->line('- '.(string) $reviewItem);
            }
        }

        if (($plan['hard_errors'] ?? []) !== []) {
            $this->newLine();
            $this->error('Hard planning errors:');

            foreach ((array) $plan['hard_errors'] as $error) {
                $this->line('- '.(string) $error);
            }
        }

        $saveOption = trim((string) ($this->option('save') ?? ''));

        if ($saveOption !== '') {
            try {
                $path = $this->resolveStorageAppPath($saveOption);
                $this->writeJson($path, $plan);
            } catch (\Throwable $exception) {
                $this->error('Unable to save Antar price update plan: '.$exception->getMessage());

                return self::FAILURE;
            }

            $this->line('Saved price plan to '.$path);
        }

        if (($plan['hard_error_count'] ?? 0) > 0) {
            $this->error('FAIL: hard structural errors exist. No database writes were performed.');

            return self::FAILURE;
        }

        if (! ($plan['ready_for_price_write'] ?? false)) {
            $this->warn('PASS WITH REVIEW: safe matches were planned, but unresolved or ambiguous Antar products remain. No database writes were performed.');

            return self::SUCCESS;
        }

        $this->info('PASS: every existing Antar product has a deterministic supplier price. No database writes were performed.');

        return self::SUCCESS;
    }

    private function money(mixed $minor): string
    {
        if (! is_numeric($minor)) {
            return 'null';
        }

        return number_format(((int) $minor) / 100, 2, '.', '');
    }

    private function resolveStorageAppPath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        $relativePath = ltrim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '../') || str_starts_with($relativePath, '..')) {
            throw new RuntimeException('The save path must be a safe relative path under storage/app.');
        }

        return storage_path('app/'.$relativePath);
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: '.$directory);
        }

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode price plan JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (file_put_contents($path, $json."\n") === false) {
            throw new RuntimeException('Unable to write price plan JSON: '.$path);
        }
    }
}
