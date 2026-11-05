<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesReh4MatPriceChanges;
use App\Enums\VatRate;
use App\Models\ProductVariant;
use App\Services\Reh4Mat\Reh4MatPriceList;
use App\Services\Reh4Mat\Reh4MatSizeVariantSync;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sets Reh4Mat variant prices from the supplier price list (net prices, effective 1 June 2026).
 *
 * Each variant is matched by its SKU, then by the product's parent SKU, then by a list symbol in the
 * product name. The list net price becomes the variant net price and the gross price follows from the
 * variant's VAT rate. Symbols with several prices (adult and children's versions) are resolved by
 * whether the product is a children's one; anything still ambiguous is reported and left alone.
 */
final class ApplyReh4MatPriceListCommand extends Command
{
    use WritesReh4MatPriceChanges;

    protected $signature = 'reh4mat:apply-price-list
        {--list= : Price-list JSON under resources/. Defaults to the 1 June 2026 list.}
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back Reh4Mat prices from the supplier price list.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            if (! $this->rollbackChanges((string) $this->option('rollback'))) {
                return self::FAILURE;
            }

            $this->info('REH4MAT_PRICE_ROLLBACK=PASS');

            return self::SUCCESS;
        }

        try {
            $list = Reh4MatPriceList::load(filled($this->option('list')) ? resource_path((string) $this->option('list')) : null);
        } catch (\Throwable $exception) {
            $this->error('PRICE_LIST_ERROR='.$exception->getMessage());

            return self::FAILURE;
        }

        $changes = [];
        $unchanged = 0;
        $ambiguous = [];
        $missing = [];

        ProductVariant::query()
            ->with(['product' => fn ($query) => $query->select(['id', 'name', 'slug', 'status', 'external_parent_sku'])->withCount('variants')])
            ->whereHas('product', fn ($query) => $query->where('external_source', 'reh4mat'))
            ->orderBy('product_id')
            ->orderBy('id')
            ->chunkById(200, function ($variants) use ($list, &$changes, &$unchanged, &$ambiguous, &$missing): void {
                foreach ($variants as $variant) {
                    [$code, $via] = $this->codeFor($variant, $list);
                    $label = $this->label($variant);

                    if ($code === null) {
                        $missing[] = $label.' sku='.($variant->sku ?? '-');

                        continue;
                    }

                    $forChild = Reh4MatPriceList::isChildText($variant->product->name.' '.$variant->product->slug.' '.$variant->sku);

                    // An accessory named after its product ("Pokrowiec do AM-ADS-R") never takes the product's own price.
                    if (Reh4MatPriceList::isAccessoryName((string) $variant->product->name, $code)) {
                        $via .= '+accessory';
                        $price = $list->accessoryPrice($code, (string) $variant->product->name, $forChild);
                    } else {
                        $colourCode = $list->colourVersion($code, (string) $variant->product->name);

                        if ($colourCode !== null) {
                            [$code, $via] = [$colourCode, $via.'+colour'];
                        }

                        $price = $list->price($code, $forChild);
                    }

                    if ($price['status'] === 'missing') {
                        $missing[] = $label.' sku='.($variant->sku ?? '-').' code='.$code.' ['.$via.']';

                        continue;
                    }

                    if ($price['status'] !== 'match') {
                        $candidates = array_map(
                            static fn (array $row): string => number_format($row['net_minor'] / 100, 2, '.', '').' '.Str::limit((string) $row['description'], 50),
                            $price['candidates'] ?? [],
                        );
                        $ambiguous[] = $label.' code='.$code.' ['.$via.'] candidates: '.implode(' | ', $candidates);

                        continue;
                    }

                    $vatRate = $variant->vat_rate instanceof VatRate ? $variant->vat_rate : VatRate::VAT_8;
                    $newNet = $price['net_minor'];
                    $newGross = $vatRate->grossFromNet($newNet);
                    $oldNet = $variant->price_net_amount === null ? null : (int) $variant->price_net_amount;
                    $oldGross = $variant->price_gross_amount;

                    if ($oldNet === $newNet && $oldGross === $newGross) {
                        $unchanged++;

                        continue;
                    }

                    $changes[] = [
                        'variant_id' => $variant->id,
                        'product_id' => $variant->product_id,
                        'code' => $code,
                        'matched_by' => $via,
                        'label' => $label,
                        'vat_rate' => $vatRate->value,
                        'old_net' => $oldNet,
                        'old_gross' => $oldGross,
                        'new_net' => $newNet,
                        'new_gross' => $newGross,
                    ];
                }
            });

        $meta = $list->meta();
        $increases = count(array_filter($changes, static fn (array $change): bool => $change['old_gross'] !== null && $change['new_gross'] > $change['old_gross']));
        $decreases = count(array_filter($changes, static fn (array $change): bool => $change['old_gross'] !== null && $change['new_gross'] < $change['old_gross']));

        $this->line('=== REH4MAT PRICE LIST ===');
        $this->line('LIST='.($meta['source_file'] ?? '?').' EFFECTIVE='.($meta['effective_from'] ?? '?').' ROWS='.($meta['row_count'] ?? '?'));
        $this->line('PRICE_CHANGES='.count($changes).' (UP='.$increases.' DOWN='.$decreases.' FROM_EMPTY='.(count($changes) - $increases - $decreases).')');
        $this->line('ALREADY_CURRENT='.$unchanged);
        $this->line('AMBIGUOUS='.count($ambiguous));
        $this->line('NOT_IN_LIST='.count($missing));

        foreach ($changes as $change) {
            $this->line(sprintf(
                'CHANGE %s code=%s [%s] gross %s -> %s (net %s -> %s, VAT %d%%)',
                $change['label'],
                $change['code'],
                $change['matched_by'],
                $this->money($change['old_gross']),
                $this->money($change['new_gross']),
                $this->money($change['old_net']),
                $this->money($change['new_net']),
                $change['vat_rate'],
            ));
        }

        foreach ($ambiguous as $line) {
            $this->line('AMBIGUOUS '.$line);
        }

        foreach ($missing as $line) {
            $this->line('NOT_IN_LIST '.$line);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $this->writeChangesWithBackup('reh4mat-price-list', $changes, ['price_list_sha256' => $meta['source_sha256'] ?? null]);
        $this->info('REH4MAT_PRICE_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function codeFor(ProductVariant $variant, Reh4MatPriceList $list): array
    {
        $product = $variant->product;
        $sku = $this->usableSku($variant->sku);
        $fromSku = $sku === null ? null : $list->resolve($sku);

        if ($fromSku !== null) {
            return [$fromSku, 'sku'];
        }

        // Product-level codes describe the variant only when it is the product's only variant,
        // or one of its size/side variants, which all share the product's catalogue price.
        if ($product->variants_count > 1 && ! Reh4MatSizeVariantSync::isOptionVariant($variant)) {
            return [$sku === null ? null : $list->symbolIn($sku), 'sku-text'];
        }

        $parentSku = $this->usableSku($product->external_parent_sku);
        $fromParentSku = $parentSku === null ? null : $list->resolve($parentSku);

        if ($fromParentSku !== null) {
            return [$fromParentSku, 'parent-sku'];
        }

        $fromName = $list->symbolIn((string) $product->name);

        return $fromName === null ? [null, ''] : [$fromName, 'name'];
    }

    private function usableSku(?string $sku): ?string
    {
        $sku = trim((string) $sku);

        return $sku === '' || str_starts_with(strtoupper($sku), 'REH4MAT-') ? null : $sku;
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
