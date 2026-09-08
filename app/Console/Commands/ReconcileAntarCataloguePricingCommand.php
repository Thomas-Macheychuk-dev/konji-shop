<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Antar\AntarPriceReconciliation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

final class ReconcileAntarCataloguePricingCommand extends Command
{
    protected $signature = 'antar:price-reconcile
        {--from=scrapers/antar/product-data.json : Antar product-data JSON path on the local disk.}
        {--save=scrapers/antar/price-reconciliation-2026-09-01.json : Save reconciliation evidence on the local disk. Use an empty value to skip saving.}
        {--show-review : Print products requiring manual commercial review.}
        {--show-excluded : Print products excluded because no approved deterministic supplier price mapping exists.}
        {--show-eligible : Print the eligible priced cohort and reconciliation method.}';

    protected $description = 'Reconcile scraped Antar products to the 1 September 2026 supplier price list without database writes or fuzzy matching.';

    public function __construct(
        private readonly AntarPriceReconciliation $reconciliation,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Antar catalogue price reconciliation');
        $this->line('Database writes: NO');
        $this->line('Network requests: NO');
        $this->line('Fuzzy/name substring matching: NO');

        try {
            $from = $this->relativePath((string) $this->option('from'));
            $disk = Storage::disk('local');

            if (! $disk->exists($from)) {
                throw new RuntimeException('Antar product-data file not found on local disk: '.$from);
            }

            $raw = $disk->get($from);
            $sourceSha256 = hash('sha256', $raw);
            $source = $this->decodeSource($raw);
            $result = $this->reconciliation->build($source, $sourceSha256);
        } catch (\Throwable $exception) {
            $this->error('Unable to reconcile Antar catalogue pricing: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = (array) ($result['summary'] ?? []);
        $supplier = (array) ($result['supplier_price_list'] ?? []);

        $this->line('Source product data: '.$from);
        $this->line('Source product-data SHA-256: '.$sourceSha256);
        $this->line('Supplier source: '.($supplier['source_file'] ?? 'unknown'));
        $this->line('Supplier effective from: '.($supplier['effective_from'] ?? 'unknown'));
        $this->line('Scraped products: '.($summary['source_products'] ?? 0));
        $this->line('Scraped products with SKU: '.($summary['source_products_with_sku'] ?? 0));
        $this->line('Scraped products without SKU: '.($summary['source_products_without_sku'] ?? 0));
        $this->line('Eligible priced products: '.($summary['eligible_priced_products'] ?? 0));
        $this->line('  Exact supplier-code matches: '.($summary['exact_price_matches'] ?? 0));
        $this->line('  Explicit approved SKU aliases: '.($summary['explicit_sku_alias_matches'] ?? 0));
        $this->line('  Explicit approved URL recoveries: '.($summary['explicit_url_recovery_matches'] ?? 0));
        $this->line('  Explicit supplier-row overrides: '.($summary['explicit_supplier_row_override_matches'] ?? 0));
        $this->line('Manual price review: '.($summary['manual_price_review'] ?? 0));
        $this->line('  Ambiguous supplier prices: '.($summary['ambiguous_supplier_price_products'] ?? 0));
        $this->line('  Explicit suffix/variant reviews: '.($summary['explicit_manual_review_products'] ?? 0));
        $this->line('Excluded unpriced products: '.($summary['excluded_unpriced_products'] ?? 0));
        $this->line('Hard errors: '.($summary['hard_errors'] ?? 0));
        $this->line('Ready for selective priced import: '.(($result['ready_for_selective_priced_import'] ?? false) ? 'YES' : 'NO'));
        $this->line('Ready for full Antar catalogue import: '.(($result['ready_for_full_catalogue_import'] ?? false) ? 'YES' : 'NO'));

        if ((bool) $this->option('show-eligible')) {
            $this->printEligible((array) ($result['eligible_priced_products'] ?? []));
        }

        if ((bool) $this->option('show-review')) {
            $this->printReview((array) ($result['manual_price_review'] ?? []));
        }

        if ((bool) $this->option('show-excluded')) {
            $this->printExcluded((array) ($result['excluded_unpriced_products'] ?? []));
        }

        if (($result['hard_errors'] ?? []) !== []) {
            $this->newLine();
            $this->error('Hard reconciliation errors:');

            foreach ((array) $result['hard_errors'] as $error) {
                $this->line('- '.(string) $error);
            }
        }

        $save = trim((string) ($this->option('save') ?? ''));

        if ($save !== '') {
            try {
                $save = $this->relativePath($save);
                $this->writeJson($save, $result);
                $this->line('Saved reconciliation to '.Storage::disk('local')->path($save));
            } catch (\Throwable $exception) {
                $this->error('Unable to save Antar price reconciliation: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        if (($summary['hard_errors'] ?? 0) > 0) {
            $this->error('FAIL: reconciliation has hard errors. No database writes were performed.');

            return self::FAILURE;
        }

        if (! ($result['ready_for_full_catalogue_import'] ?? false)) {
            $this->warn('PASS WITH REVIEW: a deterministic priced cohort is available, while unresolved products remain excluded or in manual review. No database writes were performed.');

            return self::SUCCESS;
        }

        $this->info('PASS: the full scraped Antar catalogue has deterministic approved pricing. No database writes were performed.');

        return self::SUCCESS;
    }

    /** @param array<int, mixed> $rows */
    private function printEligible(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->info('Eligible priced products:');

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $proposed = (array) ($row['proposed'] ?? []);
            $this->line(sprintf(
                '- %s | %s -> %s | %s | %s/%s VAT %s PLN',
                (string) ($row['name'] ?? ''),
                (string) ($row['normalized_scraped_sku'] ?? 'NO-SKU'),
                (string) ($row['normalized_supplier_code'] ?? ''),
                (string) ($row['match_method'] ?? ''),
                $this->money($proposed['price_net_amount'] ?? null),
                $this->money($proposed['price_gross_amount'] ?? null),
                (string) ($proposed['vat_rate'] ?? ''),
            ));
        }
    }

    /** @param array<int, mixed> $rows */
    private function printReview(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn('Manual price review:');

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->line(sprintf(
                '- %s | SKU=%s | %s | candidates=%s | %s',
                (string) ($row['name'] ?? ''),
                (string) ($row['normalized_scraped_sku'] ?? 'NO-SKU'),
                (string) ($row['reason'] ?? ''),
                implode(', ', array_filter((array) ($row['candidate_supplier_codes'] ?? []), 'is_string')),
                (string) ($row['note'] ?? ''),
            ));
        }
    }

    /** @param array<int, mixed> $rows */
    private function printExcluded(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->warn('Excluded unpriced products:');

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $this->line(sprintf(
                '- %s | SKU=%s | %s',
                (string) ($row['name'] ?? ''),
                (string) ($row['normalized_scraped_sku'] ?? 'NO-SKU'),
                (string) ($row['reason'] ?? ''),
            ));
        }
    }

    private function money(mixed $minor): string
    {
        if (! is_numeric($minor)) {
            return 'null';
        }

        return number_format(((int) $minor) / 100, 2, '.', '');
    }

    private function relativePath(string $value): string
    {
        $value = ltrim(trim(str_replace('\\', '/', $value)), '/');

        if ($value === '' || str_contains($value, '../') || str_starts_with($value, '..')) {
            throw new RuntimeException('Path must be a safe relative path on the Laravel local disk.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function decodeSource(string $raw): array
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Antar product-data JSON is invalid: '.$exception->getMessage(), 0, $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['products'] ?? null)) {
            throw new RuntimeException('Antar product-data JSON must contain a products array.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $relativePath, array $payload): void
    {
        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode Antar reconciliation JSON: '.$exception->getMessage(), 0, $exception);
        }

        if (! Storage::disk('local')->put($relativePath, $json."\n")) {
            throw new RuntimeException('Unable to write reconciliation file on local disk: '.$relativePath);
        }
    }
}
