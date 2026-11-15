<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesVariantPriceChanges;
use App\Enums\VatRate;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Prices Seni products at the matopat24.pl / seni24.pl shop price plus 20% (owner's feedback, 15 November 2026).
 *
 * The base prices are frozen in resources/import-data/seni24/prices-2026-11-15.json, one per seni24 variant
 * (size and pack), scraped from seni24.pl, which shows the same prices as matopat24.pl. Each variant is set to its
 * base price times the markup, so rerunning the command never stacks the markup.
 */
final class ApplySeniPriceMarkupCommand extends Command
{
    use WritesVariantPriceChanges;

    public const TABLE = 'import-data/seni24/prices-2026-11-15.json';

    public const MARKUP_PERCENT = 20;

    protected $signature = 'seni:apply-price-markup
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back Seni prices at the matopat24/seni24 price + 20%.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollbackChanges((string) $this->option('rollback')) ? self::SUCCESS : self::FAILURE;
        }

        $table = json_decode((string) file_get_contents(resource_path(self::TABLE)), true, flags: JSON_THROW_ON_ERROR);
        $bases = collect($table['variants'])->keyBy('external_variant_id');
        $changes = [];
        $unchanged = 0;
        $skipped = [];
        $matched = [];

        ProductVariant::query()
            ->with('product:id,name')
            ->whereIn('external_variant_id', $bases->keys()->all())
            ->chunkById(200, function ($variants) use ($bases, &$changes, &$unchanged, &$skipped, &$matched): void {
                foreach ($variants as $variant) {
                    $base = $bases->get($variant->external_variant_id);
                    $matched[$variant->external_variant_id] = true;
                    $label = 'variant='.$variant->id.' '.Str::limit((string) $variant->product->name, 70).' ['.$base['label'].']';

                    if (! $variant->vat_rate instanceof VatRate) {
                        $skipped[] = $label.' reason=no VAT rate';

                        continue;
                    }

                    $gross = self::markedUp($base['base_gross_minor']);

                    if ($variant->price_gross_amount === $gross) {
                        $unchanged++;

                        continue;
                    }

                    $changes[] = [
                        'variant_id' => $variant->id,
                        'product_id' => $variant->product_id,
                        'label' => $label,
                        'old_net' => $variant->price_net_amount === null ? null : (int) $variant->price_net_amount,
                        'old_gross' => $variant->price_gross_amount,
                        'new_net' => $variant->vat_rate->netFromGross($gross),
                        'new_gross' => $gross,
                    ];
                }
            });

        $this->line('=== SENI PRICES (matopat24/seni24 + '.self::MARKUP_PERCENT.'%) ===');
        $this->line('TABLE_VARIANTS='.$bases->count().' IN_SHOP='.count($matched).' NOT_IN_SHOP='.($bases->count() - count($matched)));
        $this->line('PRICE_CHANGES='.count($changes).' ALREADY_CURRENT='.$unchanged.' SKIPPED='.count($skipped));

        foreach ($changes as $change) {
            $this->line(sprintf('CHANGE %s gross %s -> %s', $change['label'], $this->money($change['old_gross']), $this->money($change['new_gross'])));
        }

        foreach ($skipped as $line) {
            $this->line('SKIPPED '.$line);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes !== []) {
            $this->writeChangesWithBackup('seni-markup', $changes, ['table' => self::TABLE]);
        }

        return self::SUCCESS;
    }

    public static function markedUp(int $baseGrossMinor): int
    {
        return intdiv($baseGrossMinor * (100 + self::MARKUP_PERCENT) + 50, 100);
    }
}
