<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\VatRate;
use App\Models\ProductVariant;
use App\Services\Reh4Mat\Reh4MatPriceList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

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
    protected $signature = 'reh4mat:apply-price-list
        {--list= : Price-list JSON under resources/. Defaults to the 1 June 2026 list.}
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back Reh4Mat prices from the supplier price list.';

    private const BACKUP_DIRECTORY = 'catalogue-repairs';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
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

        $backupPath = self::BACKUP_DIRECTORY.'/reh4mat-price-list-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'price_list_sha256' => $meta['source_sha256'] ?? null,
            'changes' => $changes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $written = $this->writePrices($changes, 'old', 'new');

        $this->info('BACKUP='.$backupPath);
        $this->info('WRITTEN_PRICES='.$written);
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

        // Product-level codes describe the variant only when it is the product's only variant.
        if ($product->variants_count > 1) {
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

    private function money(?int $minor): string
    {
        return $minor === null ? '(empty)' : number_format($minor / 100, 2, '.', '');
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);
        $restored = $this->writePrices($backup['changes'], 'new', 'old');

        $this->info('RESTORED_PRICES='.$restored.' OF '.count($backup['changes']));
        $this->info('REH4MAT_PRICE_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    /**
     * Write the $to prices over each variant whose prices still equal $from; variants edited since are left alone.
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function writePrices(array $changes, string $from, string $to): int
    {
        return DB::transaction(function () use ($changes, $from, $to): int {
            $written = 0;

            foreach ($changes as $change) {
                $variant = ProductVariant::query()->lockForUpdate()->find($change['variant_id']);

                if ($variant === null) {
                    throw new RuntimeException('Variant disappeared: '.$change['variant_id']);
                }

                $currentNet = $variant->price_net_amount === null ? null : (int) $variant->price_net_amount;

                if ($currentNet !== $change[$from.'_net'] || $variant->price_gross_amount !== $change[$from.'_gross']) {
                    $this->warn('SKIPPED_CHANGED_SINCE variant='.$change['variant_id']);

                    continue;
                }

                // Saved through the model so storefront cache invalidation observers run.
                $variant->price_net_amount = $change[$to.'_net'];
                $variant->price_gross_amount = $change[$to.'_gross'];
                $variant->save();
                $written++;
            }

            return $written;
        });
    }
}
