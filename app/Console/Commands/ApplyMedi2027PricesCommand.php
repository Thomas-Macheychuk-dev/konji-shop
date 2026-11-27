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
 *
 * Every listed product is priced and taxed at 8%, the rate medi's lists use for these medical devices; some had
 * been imported at 23% (owner, 27 November 2026). --rollback restores the old VAT rates too.
 */
final class ApplyMedi2027PricesCommand extends Command
{
    use WritesVariantPriceChanges;

    public const TABLE = 'import-data/medi/prices-2027.json';

    public const MARKUP_PERCENT = 20;

    public const VAT = VatRate::VAT_8;

    protected $signature = 'medi:apply-2027-prices
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back medi prices at the 2027 wholesale net + 8% VAT + 20%, and 8% VAT.';

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
        $vatChanges = 0;
        $skipped = [];
        $summary = [];

        foreach ($products as $product) {
            $entry = $entries[$product->name];
            $found[$product->name] = true;
            $old = [];
            $new = [];

            $oldVat = [];

            foreach ($product->variants as $variant) {
                $currentVat = $variant->vat_rate instanceof VatRate ? $variant->vat_rate->value : null;
                $net = self::listNet($entry, (string) $variant->sku);
                $gross = self::shopGross($net, self::VAT);
                $old[] = $variant->price_gross_amount;
                $new[] = $gross;
                $oldVat[$currentVat ?? 'none'] = true;

                if ($variant->price_gross_amount === $gross && $currentVat === self::VAT->value) {
                    $unchanged++;

                    continue;
                }

                if ($currentVat !== self::VAT->value) {
                    $vatChanges++;
                }

                $changes[] = [
                    'variant_id' => $variant->id,
                    'product_id' => $product->id,
                    'label' => 'variant='.$variant->id.' '.$product->name.' ['.$variant->sku.']',
                    'old_net' => $variant->price_net_amount === null ? null : (int) $variant->price_net_amount,
                    'old_gross' => $variant->price_gross_amount,
                    'old_vat' => $currentVat,
                    'new_net' => self::VAT->netFromGross($gross),
                    'new_gross' => $gross,
                    'new_vat' => self::VAT->value,
                ];
            }

            if ($new !== []) {
                $vat = implode('/', array_map(fn ($rate): string => $rate.'%', array_keys($oldVat)));
                $summary[] = sprintf('PRODUCT #%d %s (%s): %s -> %s, VAT %s -> %d%%, %d variants', $product->id, $product->name, $entry['list'], $this->range($old), $this->range($new), $vat, self::VAT->value, count($new));
            }
        }

        $this->line('=== MEDI PRICES 2027 (wholesale net + VAT + '.self::MARKUP_PERCENT.'%) ===');
        $this->line(sprintf('LISTED_PRODUCTS=%d IN_SHOP=%d PRICE_CHANGES=%d VAT_TO_8=%d ALREADY_CURRENT=%d SKIPPED=%d', count($entries), count($found), count($changes), $vatChanges, $unchanged, count($skipped)));

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
