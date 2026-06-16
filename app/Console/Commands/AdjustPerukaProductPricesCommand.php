<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VatRate;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Peruka\PerukaPriceCalculator;
use Illuminate\Console\Command;

final class AdjustPerukaProductPricesCommand extends Command
{
    protected $signature = 'peruka:adjust-prices
        {--data=scrapers/peruka/full-peruka-product-data.json : Peruka product-data JSON path. Relative paths are resolved under storage/app.}
        {--from= : Alias for --data.}
        {--dry-run : Show changes without writing to the database.}
        {--limit= : Maximum number of Peruka JSON products to inspect.}
        {--offset=0 : Number of Peruka JSON products to skip before inspecting.}
        {--show-products : Print products that would be/were updated.}';

    protected $description = 'Lower already-imported Peruka product prices by 6% from the original scraped JSON prices. The command is idempotent.';

    public function handle(): int
    {
        $dataOption = is_string($this->option('from')) && trim((string) $this->option('from')) !== ''
            ? (string) $this->option('from')
            : (string) $this->option('data');

        $dataPath = $this->resolvePath($dataOption);
        $products = $this->loadProducts($dataPath);

        if ($products === []) {
            $this->error('No Peruka products found in data file: '.$dataPath);

            return self::FAILURE;
        }

        $offset = $this->nonNegativeIntOption('offset', 0);
        $limit = $this->nullablePositiveIntOption('limit');
        $selectedProducts = array_slice($products, $offset, $limit);
        $dryRun = (bool) $this->option('dry-run');
        $showProducts = (bool) $this->option('show-products');

        $this->info('Adjusting Peruka prices from: '.$dataPath);
        $this->line('Available JSON products: '.count($products));
        $this->line('Offset: '.$offset);
        $this->line('Selected JSON products: '.count($selectedProducts));
        $this->line('Reduction: '.PerukaPriceCalculator::DISCOUNT_PERCENTAGE.'%');
        $this->line('Mode: '.($dryRun ? 'dry-run' : 'database update'));

        if ($selectedProducts === []) {
            $this->warn('No products selected after offset/limit.');

            return self::FAILURE;
        }

        $updated = 0;
        $unchanged = 0;
        $missingProducts = 0;
        $missingVariants = 0;
        $missingPrices = 0;

        foreach ($selectedProducts as $productData) {
            if (! is_array($productData)) {
                continue;
            }

            $externalId = $this->stringOrNull($productData['external_product_id'] ?? null)
                ?: $this->stringOrNull($productData['sku'] ?? null);
            $name = $this->stringOrNull($productData['name'] ?? null) ?: '[unnamed Peruka product]';
            $targetGrossAmount = PerukaPriceCalculator::adjustedGrossMinorFromSource($productData['price_gross_amount'] ?? null);

            if ($externalId === null) {
                $missingProducts++;

                if ($showProducts) {
                    $this->warn('Missing external ID for JSON product: '.$name);
                }

                continue;
            }

            if ($targetGrossAmount === null) {
                $missingPrices++;

                if ($showProducts) {
                    $this->warn('Missing price for Peruka product '.$externalId.': '.$name);
                }

                continue;
            }

            $product = Product::query()
                ->where('external_source', 'peruka')
                ->where('external_id', $externalId)
                ->first();

            if ($product === null) {
                $missingProducts++;

                if ($showProducts) {
                    $this->warn('Imported Peruka product not found for external ID '.$externalId.': '.$name);
                }

                continue;
            }

            $variant = $this->resolveVariant($product, $externalId);

            if ($variant === null) {
                $missingVariants++;

                if ($showProducts) {
                    $this->warn('Default variant not found for Peruka product '.$externalId.': '.$product->name);
                }

                continue;
            }

            $vatRate = $variant->vat_rate instanceof VatRate ? $variant->vat_rate : VatRate::VAT_23;
            $targetNetAmount = $vatRate->netFromGross($targetGrossAmount);
            $currentGrossAmount = $variant->price_gross_amount;
            $currentNetAmount = $variant->price_net_amount;

            if ($currentGrossAmount === $targetGrossAmount && $currentNetAmount === $targetNetAmount) {
                $unchanged++;
                continue;
            }

            $updated++;

            if ($showProducts) {
                $this->line(sprintf(
                    'Product #%d %s (%s): %s -> %s gross',
                    $product->id,
                    $product->name,
                    $externalId,
                    $this->formatMinorAmount($currentGrossAmount),
                    $this->formatMinorAmount($targetGrossAmount),
                ));
            }

            if (! $dryRun) {
                $variant->forceFill([
                    'price_gross_amount' => $targetGrossAmount,
                    'price_net_amount' => $targetNetAmount,
                ])->save();
            }
        }

        $this->info(($dryRun ? 'Products that would be updated: ' : 'Updated Peruka product prices: ').$updated);
        $this->line('Already correct / unchanged: '.$unchanged);
        $this->line('Missing imported products: '.$missingProducts);
        $this->line('Missing default variants: '.$missingVariants);
        $this->line('Missing source prices: '.$missingPrices);

        return self::SUCCESS;
    }

    private function resolveVariant(Product $product, string $externalId): ?ProductVariant
    {
        $externalVariantId = 'peruka-'.$externalId.'-default';

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('external_variant_id', $externalVariantId)
            ->first();

        if ($variant !== null) {
            return $variant;
        }

        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_default', true)
            ->first();

        if ($variant !== null) {
            return $variant;
        }

        return ProductVariant::query()
            ->where('product_id', $product->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadProducts(string $path): array
    {
        if (! is_file($path)) {
            $this->error('Peruka product-data file not found: '.$path);

            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! isset($decoded['products']) || ! is_array($decoded['products'])) {
            $this->error('Peruka product-data file does not contain a products array: '.$path);

            return [];
        }

        $products = [];
        $seen = [];

        foreach ($decoded['products'] as $product) {
            if (! is_array($product)) {
                continue;
            }

            $dedupeKey = $this->stringOrNull($product['external_product_id'] ?? null)
                ?: $this->stringOrNull($product['sku'] ?? null)
                    ?: $this->stringOrNull($product['source_url'] ?? null)
                        ?: $this->stringOrNull($product['canonical_url'] ?? null);

            if ($dedupeKey !== null && isset($seen[$dedupeKey])) {
                continue;
            }

            if ($dedupeKey !== null) {
                $seen[$dedupeKey] = true;
            }

            $products[] = $product;
        }

        return $products;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return storage_path('app/scrapers/peruka/full-peruka-product-data.json');
        }

        if (str_starts_with($path, '/')) {
            return $path;
        }

        return storage_path('app/'.ltrim($path, '/'));
    }

    private function nonNegativeIntOption(string $option, int $default): int
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    private function nullablePositiveIntOption(string $option): ?int
    {
        $value = $this->option($option);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return max(1, (int) $value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function formatMinorAmount(?int $amount): string
    {
        if ($amount === null) {
            return '[missing]';
        }

        return number_format($amount / 100, 2, '.', '').' PLN';
    }
}
