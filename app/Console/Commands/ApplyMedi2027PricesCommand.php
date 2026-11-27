<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesVariantPriceChanges;
use App\Enums\VatRate;
use App\Models\Product;
use Illuminate\Console\Command;

/**
 * Prices medi products from medi Polska's 2027 wholesale lists (valid from 1 January 2027): wholesale net plus
 * VAT plus 20%, in whole złoty (owner, 27 November 2026).
 *
 * The wholesale prices are frozen in resources/import-data/medi/prices-2027.json, one per shop product name, read
 * from the orthopaedics and footsupport PDFs. Where one shop product holds models with different list prices
 * (Genumedi with an extra-wide thigh), "variants" gives those by SKU prefix. Products not in the lists (compression,
 * accessories, items medi no longer lists) keep their prices.
 */
final class ApplyMedi2027PricesCommand extends Command
{
    use WritesVariantPriceChanges;

    public const TABLE = 'import-data/medi/prices-2027.json';

    public const MARKUP_PERCENT = 20;

    protected $signature = 'medi:apply-2027-prices
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back medi prices at the 2027 wholesale net + VAT + 20%.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollbackChanges((string) $this->option('rollback')) ? self::SUCCESS : self::FAILURE;
        }

        $table = json_decode((string) file_get_contents(resource_path(self::TABLE)), true, flags: JSON_THROW_ON_ERROR);
        $entries = $table['products'];
        $products = Product::query()
            ->where('external_source', 'medi')
            ->whereIn('name', array_keys($entries))
            ->with('variants')
            ->orderBy('name')
            ->get();
        $found = [];
        $changes = [];
        $unchanged = 0;
        $skipped = [];
        $summary = [];

        foreach ($products as $product) {
            $entry = $entries[$product->name];
            $found[$product->name] = true;
            $old = [];
            $new = [];

            foreach ($product->variants as $variant) {
                if (! $variant->vat_rate instanceof VatRate) {
                    $skipped[] = 'variant='.$variant->id.' '.$product->name.' reason=no VAT rate';

                    continue;
                }

                $net = self::listNet($entry, (string) $variant->sku);
                $gross = self::shopGross($net, $variant->vat_rate);
                $old[] = $variant->price_gross_amount;
                $new[] = $gross;

                if ($variant->price_gross_amount === $gross) {
                    $unchanged++;

                    continue;
                }

                $changes[] = [
                    'variant_id' => $variant->id,
                    'product_id' => $product->id,
                    'label' => 'variant='.$variant->id.' '.$product->name.' ['.$variant->sku.']',
                    'old_net' => $variant->price_net_amount === null ? null : (int) $variant->price_net_amount,
                    'old_gross' => $variant->price_gross_amount,
                    'new_net' => $variant->vat_rate->netFromGross($gross),
                    'new_gross' => $gross,
                ];
            }

            if ($new !== []) {
                $summary[] = sprintf('PRODUCT #%d %s (%s): %s -> %s, %d variants', $product->id, $product->name, $entry['list'], $this->range($old), $this->range($new), count($new));
            }
        }

        $this->line('=== MEDI PRICES 2027 (wholesale net + VAT + '.self::MARKUP_PERCENT.'%) ===');
        $this->line(sprintf('LISTED_PRODUCTS=%d IN_SHOP=%d PRICE_CHANGES=%d ALREADY_CURRENT=%d SKIPPED=%d', count($entries), count($found), count($changes), $unchanged, count($skipped)));

        foreach ($summary as $line) {
            $this->line($line);
        }

        foreach (array_diff(array_keys($entries), array_keys($found)) as $name) {
            $this->warn('NOT_IN_SHOP '.$name);
        }

        foreach ($skipped as $line) {
            $this->line('SKIPPED '.$line);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes !== []) {
            $this->writeChangesWithBackup('medi-prices-2027', $changes, ['table' => self::TABLE]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{net: int|float, variants?: array<string, int|float>}  $entry
     */
    public static function listNet(array $entry, string $sku): float
    {
        $net = (float) $entry['net'];
        $longest = 0;

        foreach ($entry['variants'] ?? [] as $prefix => $variantNet) {
            if (str_starts_with(strtoupper($sku), strtoupper((string) $prefix)) && strlen((string) $prefix) > $longest) {
                $net = (float) $variantNet;
                $longest = strlen((string) $prefix);
            }
        }

        return $net;
    }

    /** Wholesale net in złoty to the shop's gross price in grosze: net x (1 + VAT) x 1.20, rounded to whole złoty. */
    public static function shopGross(float $net, VatRate $vat): int
    {
        return (int) round($net * $vat->multiplier() * (100 + self::MARKUP_PERCENT) / 100) * 100;
    }

    /**
     * @param  list<int|null>  $amounts
     */
    private function range(array $amounts): string
    {
        $amounts = array_map(fn (?int $amount): int => (int) $amount, $amounts);
        $min = $this->money(min($amounts));
        $max = $this->money(max($amounts));

        return $min === $max ? $min : $min.'-'.$max;
    }
}
