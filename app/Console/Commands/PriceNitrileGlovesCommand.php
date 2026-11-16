<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesVariantPriceChanges;
use App\Enums\ProductStatus;
use App\Enums\VatRate;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Raises non-sterile nitrile glove prices to the shop's minimum box prices: blue and black 16 zł,
 * every other colour 18 zł (owner's feedback, 15 November 2026). Prices already above the minimum,
 * sterile gloves and gloves whose colour the name does not say are left alone.
 */
final class PriceNitrileGlovesCommand extends Command
{
    use WritesVariantPriceChanges;

    public const BLUE_OR_BLACK_MINOR = 1600;

    public const OTHER_COLOUR_MINOR = 1800;

    private const BLUE_OR_BLACK = '/niebiesk|granatow|\bblue\b|\bnavy\b|czarn|\bblack\b/iu';

    private const OTHER_COLOURS = '/różow|\bpink\b|magenta|fiolet|violet|purple|zielon|\bgreen\b|pomarańcz|\borange\b|biał|\bwhite\b|żółt|\byellow\b|czerwon|\bred\b|szar|\bgr[ae]y\b|beż|brąz|kolorow|tutti|lawend|turkus/iu';

    protected $signature = 'catalogue:price-nitrile-gloves
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back the minimum nitrile glove prices (blue/black 16 zł, other colours 18 zł).';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollbackChanges((string) $this->option('rollback')) ? self::SUCCESS : self::FAILURE;
        }

        $changes = [];
        $kept = 0;
        $skipped = [];

        ProductVariant::query()
            ->with(['product:id,name,status', 'attributeValues:id,value'])
            ->whereHas('product', fn ($query) => $query
                ->where('status', '!=', ProductStatus::ARCHIVED->value)
                ->where('name', 'like', '%kawic%')
                ->where(fn ($query) => $query->where('name', 'like', '%nitryl%')->orWhere('name', 'like', '%nitril%')))
            ->chunkById(200, function ($variants) use (&$changes, &$kept, &$skipped): void {
                foreach ($variants as $variant) {
                    $name = (string) $variant->product->name;
                    $label = 'variant='.$variant->id.' '.Str::limit($name, 80);

                    if (preg_match('/jałow|steryl/iu', $name)) {
                        $skipped[] = $label.' reason=sterile';

                        continue;
                    }

                    $minimum = $this->minimumFor($variant->attributeValues->pluck('value')->implode(' ').' '.$name);

                    if ($minimum === null) {
                        $skipped[] = $label.' reason=colour not in the name';

                        continue;
                    }

                    if (! $variant->vat_rate instanceof VatRate) {
                        $skipped[] = $label.' reason=no VAT rate';

                        continue;
                    }

                    if ($variant->price_gross_amount !== null && $variant->price_gross_amount >= $minimum) {
                        $kept++;

                        continue;
                    }

                    $changes[] = [
                        'variant_id' => $variant->id,
                        'product_id' => $variant->product_id,
                        'label' => $label,
                        'old_net' => $variant->price_net_amount === null ? null : (int) $variant->price_net_amount,
                        'old_gross' => $variant->price_gross_amount,
                        'new_net' => $variant->vat_rate->netFromGross($minimum),
                        'new_gross' => $minimum,
                    ];
                }
            });

        $this->line('=== NITRILE GLOVE PRICES ===');
        $this->line('PRICE_CHANGES='.count($changes).' ALREADY_AT_OR_ABOVE_MINIMUM='.$kept.' SKIPPED='.count($skipped));

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
            $this->writeChangesWithBackup('nitrile-gloves', $changes, []);
        }

        return self::SUCCESS;
    }

    private function minimumFor(string $text): ?int
    {
        if (preg_match(self::OTHER_COLOURS, $text)) {
            return self::OTHER_COLOUR_MINOR;
        }

        return preg_match(self::BLUE_OR_BLACK, $text) ? self::BLUE_OR_BLACK_MINOR : null;
    }
}
