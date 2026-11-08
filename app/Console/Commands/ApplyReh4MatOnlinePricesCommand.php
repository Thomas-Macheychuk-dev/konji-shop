<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesVariantPriceChanges;
use App\Enums\VatRate;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prices Reh4Mat variants that the supplier price list does not cover, from the median of Polish shop prices.
 *
 * The reference JSON lists, per variant SKU, the gross shop prices found online with their sources; the median
 * becomes the variant's gross price and the net price follows from its VAT rate. Variants that already have a
 * price are never changed, so list prices from reh4mat:apply-price-list always win.
 */
final class ApplyReh4MatOnlinePricesCommand extends Command
{
    use WritesVariantPriceChanges;

    public const DEFAULT_PATH = 'import-data/reh4mat/online-prices-2026-11.json';

    protected $signature = 'reh4mat:apply-online-prices
        {--file= : Online price JSON, a path or one under resources/. Defaults to the November 2026 collection.}
        {--write : Apply the prices after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back median online shop prices for unpriced Reh4Mat variants.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            if (! $this->rollbackChanges((string) $this->option('rollback'))) {
                return self::FAILURE;
            }

            $this->info('REH4MAT_ONLINE_PRICE_ROLLBACK=PASS');

            return self::SUCCESS;
        }

        $file = (string) $this->option('file');
        $path = $file !== '' && is_file($file) ? $file : resource_path($file !== '' ? $file : self::DEFAULT_PATH);
        $raw = is_file($path) ? file_get_contents($path) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        if (! is_array($data['products'] ?? null) || ($data['price_basis'] ?? null) !== 'gross' || ($data['currency'] ?? null) !== 'PLN') {
            $this->error('ONLINE_PRICES_ERROR=gross PLN products expected in '.$path);

            return self::FAILURE;
        }

        $changes = [];
        $alreadyPriced = [];
        $noPrice = [];
        $notFound = [];

        foreach ($data['products'] as $entry) {
            $sku = (string) $entry['sku'];
            $grossPrices = array_map(static fn (array $source): int => (int) round($source['gross_pln'] * 100), $entry['sources'] ?? []);
            sort($grossPrices);

            $variant = ProductVariant::query()
                ->with('product:id,name,status')
                ->where('sku', $sku)
                ->whereHas('product', fn ($query) => $query->where('external_source', 'reh4mat'))
                ->first();

            if ($variant === null) {
                $notFound[] = 'sku='.$sku;

                continue;
            }

            $label = $this->label($variant);

            if ($grossPrices === []) {
                $noPrice[] = $label.' sku='.$sku;

                continue;
            }

            if ($variant->price_gross_amount !== null) {
                $alreadyPriced[] = $label.' sku='.$sku.' gross '.$this->money($variant->price_gross_amount);

                continue;
            }

            $vatRate = $variant->vat_rate instanceof VatRate ? $variant->vat_rate : VatRate::VAT_8;
            $newGross = self::median($grossPrices);

            $changes[] = [
                'variant_id' => $variant->id,
                'product_id' => $variant->product_id,
                'sku' => $sku,
                'label' => $label,
                'vat_rate' => $vatRate->value,
                'shop_prices' => $grossPrices,
                'old_net' => $variant->price_net_amount === null ? null : (int) $variant->price_net_amount,
                'old_gross' => null,
                'new_net' => $vatRate->netFromGross($newGross),
                'new_gross' => $newGross,
            ];
        }

        $this->line('=== REH4MAT ONLINE PRICES ===');
        $this->line('FILE='.basename($path).' COLLECTED='.($data['collected_at'] ?? '?').' PRODUCTS='.count($data['products']));
        $this->line('PRICE_CHANGES='.count($changes));
        $this->line('ALREADY_PRICED='.count($alreadyPriced));
        $this->line('NO_ONLINE_PRICE='.count($noPrice));
        $this->line('SKU_NOT_FOUND='.count($notFound));

        foreach ($changes as $change) {
            $this->line(sprintf(
                'CHANGE %s sku=%s gross (empty) -> %s (net %s, VAT %d%%) median of %s',
                $change['label'],
                $change['sku'],
                $this->money($change['new_gross']),
                $this->money($change['new_net']),
                $change['vat_rate'],
                implode(' / ', array_map(fn (int $gross): string => $this->money($gross), $change['shop_prices'])),
            ));
        }

        foreach ($alreadyPriced as $line) {
            $this->line('ALREADY_PRICED '.$line);
        }

        foreach ($noPrice as $line) {
            $this->line('NO_ONLINE_PRICE '.$line);
        }

        foreach ($notFound as $line) {
            $this->line('SKU_NOT_FOUND '.$line);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $this->writeChangesWithBackup('reh4mat-online-prices', $changes, ['online_prices_file' => basename($path)]);
        $this->info('REH4MAT_ONLINE_PRICE_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * Median in minor units; with an even count, the mean of the two middle prices rounded to the nearest grosz.
     *
     * @param  non-empty-list<int>  $prices
     */
    private static function median(array $prices): int
    {
        sort($prices);
        $middle = intdiv(count($prices), 2);

        return count($prices) % 2 === 1 ? $prices[$middle] : (int) round(($prices[$middle - 1] + $prices[$middle]) / 2);
    }

    private function label(ProductVariant $variant): string
    {
        $status = $variant->product->status;

        return sprintf(
            'product=%d variant=%d [%s] %s',
            $variant->product_id,
            $variant->id,
            $status instanceof \BackedEnum ? $status->value : (string) $status,
            Str::limit((string) $variant->product->name, 70),
        );
    }
}
