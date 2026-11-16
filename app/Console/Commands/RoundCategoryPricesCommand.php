<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesVariantPriceChanges;
use App\Enums\VatRate;
use App\Models\Category;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Rounds gross prices to whole złoty in the equipment and clothing categories, e.g. 125,03 zł -> 125 zł
 * and 1 522,80 zł -> 1 523 zł (owner's feedback, 15 November 2026). Each named category counts with all
 * of its subcategories; the net price follows from the variant's VAT rate. Prices under --min (10 zł by default)
 * keep their grosze: on a 0,65 zł catheter or 1,30 zł syringe, rounding would move the price by 20-50%.
 */
final class RoundCategoryPricesCommand extends Command
{
    use WritesVariantPriceChanges;

    public const DEFAULT_CATEGORIES = [
        'Mobilność i transport',
        'Sprzęt diagnostyczny i medyczny',
        'Opieka nad pacjentem',
        'Rehabilitacja i ćwiczenia',
        'Odzież medyczna i zawodowa',
    ];

    protected $signature = 'catalogue:round-prices
        {category?* : Category names (with their subcategories). Defaults to the equipment and clothing categories.}
        {--min=10 : Leave prices under this many złoty unrounded.}
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back rounding gross prices to whole złoty in the given categories.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollbackChanges((string) $this->option('rollback')) ? self::SUCCESS : self::FAILURE;
        }

        $names = $this->argument('category') ?: self::DEFAULT_CATEGORIES;
        $roots = Category::query()->whereIn('name', $names)->get(['id', 'name']);
        $unknown = array_diff($names, $roots->pluck('name')->all());

        if ($unknown !== []) {
            $this->error('UNKNOWN_CATEGORIES='.implode(' | ', $unknown));

            return self::FAILURE;
        }

        $categoryIds = $this->withDescendants($roots->pluck('id')->all());
        $minimumMinor = (int) round((float) $this->option('min') * 100);
        $changes = [];
        $skipped = [];

        ProductVariant::query()
            ->with('product:id,name')
            ->whereNotNull('price_gross_amount')
            ->whereRaw('price_gross_amount % 100 <> 0')
            ->where('price_gross_amount', '>=', $minimumMinor)
            ->whereHas('product.categories', fn ($query) => $query->whereIn('categories.id', $categoryIds))
            ->chunkById(200, function ($variants) use (&$changes, &$skipped): void {
                foreach ($variants as $variant) {
                    $label = 'variant='.$variant->id.' '.Str::limit((string) $variant->product->name, 80);

                    if (! $variant->vat_rate instanceof VatRate) {
                        $skipped[] = $label.' reason=no VAT rate';

                        continue;
                    }

                    $gross = (int) (round($variant->price_gross_amount / 100, 0, PHP_ROUND_HALF_UP) * 100);

                    if ($gross <= 0) {
                        $skipped[] = $label.' reason=rounds to zero';

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

        $this->line('=== ROUND PRICES TO WHOLE ZŁOTY ===');
        $this->line('CATEGORIES='.implode(' | ', $names).' (with '.count($categoryIds).' categories in all)');
        $this->line('MIN_PRICE='.$this->money($minimumMinor).' (cheaper prices keep their grosze)');
        $this->line('PRICE_CHANGES='.count($changes).' SKIPPED='.count($skipped));

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
            $this->writeChangesWithBackup('round-prices', $changes, ['categories' => $names]);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function withDescendants(array $ids): array
    {
        $all = $ids;

        while ($ids !== []) {
            $ids = Category::query()->whereIn('parent_id', $ids)->whereNotIn('id', $all)->pluck('id')->all();
            $all = [...$all, ...$ids];
        }

        return $all;
    }
}
