<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesVariantPriceChanges;
use App\Enums\VatRate;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use JsonException;

/**
 * Sets supplier product prices from a resolved price table under resources/import-data/supplier-prices.
 *
 * The tables are built from the supplier price lists by scripts/catalogue/supplier-prices-2026 and hold one
 * entry per supplier product (external_id) with the final net and gross price and the list VAT rate. Every
 * variant of a matched product gets that price and the list VAT rate; the preview shows each VAT change.
 */
final class ApplySupplierPriceListCommand extends Command
{
    use WritesVariantPriceChanges;

    private const LIST_DIRECTORY = 'import-data/supplier-prices';

    protected $signature = 'catalogue:apply-supplier-prices
        {list? : Price table name under resources/import-data/supplier-prices, e.g. timago-2026 or vermeiren-2026.}
        {--write : Apply the price changes after writing a private backup. Otherwise preview only.}
        {--rollback= : Restore the prices from a backup written by --write.}';

    protected $description = 'Preview, apply or roll back supplier prices from a resolved price table.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            if (! $this->rollbackChanges((string) $this->option('rollback'))) {
                return self::FAILURE;
            }

            $this->info('SUPPLIER_PRICE_ROLLBACK=PASS');

            return self::SUCCESS;
        }

        $table = $this->loadTable((string) $this->argument('list'));

        if ($table === null) {
            return self::FAILURE;
        }

        $entries = collect($table['entries'])->keyBy('external_id');
        $changes = [];
        $unchanged = 0;
        $vatChanges = 0;
        $missing = [];
        $matchedProducts = [];

        ProductVariant::query()
            ->with('product:id,name,status,external_id')
            ->whereHas('product', fn ($query) => $query->where('external_source', $table['external_source']))
            ->orderBy('product_id')
            ->orderBy('id')
            ->chunkById(200, function ($variants) use ($entries, &$changes, &$unchanged, &$vatChanges, &$missing, &$matchedProducts): void {
                foreach ($variants as $variant) {
                    $entry = $entries->get((string) $variant->product->external_id);
                    $label = $this->label($variant);

                    if ($entry === null) {
                        $missing[] = $label.' sku='.($variant->sku ?? '-');

                        continue;
                    }

                    $matchedProducts[$entry['external_id']] = true;
                    $oldVat = $variant->vat_rate instanceof VatRate ? $variant->vat_rate->value : null;
                    $oldNet = $variant->price_net_amount === null ? null : (int) $variant->price_net_amount;
                    $oldGross = $variant->price_gross_amount;

                    if ($oldNet === $entry['net_minor'] && $oldGross === $entry['gross_minor'] && $oldVat === $entry['vat']) {
                        $unchanged++;

                        continue;
                    }

                    if ($oldVat !== $entry['vat']) {
                        $vatChanges++;
                    }

                    $changes[] = [
                        'variant_id' => $variant->id,
                        'product_id' => $variant->product_id,
                        'list_row' => $entry['list_row'],
                        'label' => $label,
                        'old_net' => $oldNet,
                        'old_gross' => $oldGross,
                        'old_vat' => $oldVat,
                        'new_net' => $entry['net_minor'],
                        'new_gross' => $entry['gross_minor'],
                        'new_vat' => $entry['vat'],
                    ];
                }
            });

        $increases = count(array_filter($changes, static fn (array $change): bool => $change['old_gross'] !== null && $change['new_gross'] > $change['old_gross']));
        $decreases = count(array_filter($changes, static fn (array $change): bool => $change['old_gross'] !== null && $change['new_gross'] < $change['old_gross']));
        $absent = $entries->keys()->diff(array_keys($matchedProducts));

        $this->line('=== SUPPLIER PRICE LIST ===');
        $this->line(sprintf('SUPPLIER=%s LIST=%s EFFECTIVE=%s', $table['supplier'], $table['source_file'] ?? '?', $table['effective_from'] ?? '?'));
        $this->line('PRICING='.($table['pricing'] ?? '?'));
        $this->line('LIST_ENTRIES='.$entries->count().' LIST_UNRESOLVED='.count($table['unresolved'] ?? []).' ENTRIES_WITHOUT_PRODUCT='.$absent->count());
        $this->line('PRICE_CHANGES='.count($changes).' (UP='.$increases.' DOWN='.$decreases.' FROM_EMPTY='.(count($changes) - $increases - $decreases).')');
        $this->line('ALREADY_CURRENT='.$unchanged);
        $this->line('VAT_CHANGES='.$vatChanges);
        $this->line('NOT_IN_LIST='.count($missing));

        foreach ($changes as $change) {
            $this->line(sprintf(
                'CHANGE %s [%s] gross %s -> %s (net %s -> %s, VAT %s)',
                $change['label'],
                $change['list_row'],
                $this->money($change['old_gross']),
                $this->money($change['new_gross']),
                $this->money($change['old_net']),
                $this->money($change['new_net']),
                $change['old_vat'] === $change['new_vat']
                    ? $change['new_vat'].'%'
                    : ($change['old_vat'] === null ? '(empty)' : $change['old_vat'].'%').' -> '.$change['new_vat'].'%',
            ));
        }

        foreach ($missing as $line) {
            $this->line('NOT_IN_LIST '.$line);
        }

        foreach ($absent as $externalId) {
            $entry = $entries->get($externalId);
            $this->line(sprintf('ENTRY_WITHOUT_PRODUCT external_id=%s sku=%s %s', $externalId, $entry['sku'] ?? '-', Str::limit((string) $entry['name'], 70)));
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $this->writeChangesWithBackup('supplier-prices-'.$table['supplier'], $changes, ['price_list' => $table['source_file'] ?? null]);
        $this->info('SUPPLIER_PRICE_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTable(string $name): ?array
    {
        $available = collect(glob(resource_path(self::LIST_DIRECTORY.'/*.json')) ?: [])
            ->map(static fn (string $path): string => basename($path, '.json'))
            ->values();

        if (! $available->contains($name)) {
            $this->error('PRICE_LIST_ERROR=Choose one of: '.$available->implode(', '));

            return null;
        }

        try {
            $table = json_decode((string) file_get_contents(resource_path(self::LIST_DIRECTORY.'/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('PRICE_LIST_ERROR='.$exception->getMessage());

            return null;
        }

        if (! is_array($table['entries'] ?? null) || ($table['currency'] ?? null) !== 'PLN' || blank($table['external_source'] ?? null)) {
            $this->error('PRICE_LIST_ERROR=The table must hold PLN entries for one external source.');

            return null;
        }

        foreach ($table['entries'] as $entry) {
            $valid = is_string($entry['external_id'] ?? null)
                && is_int($entry['net_minor'] ?? null) && $entry['net_minor'] > 0
                && is_int($entry['gross_minor'] ?? null) && $entry['gross_minor'] >= $entry['net_minor']
                && VatRate::tryFrom($entry['vat'] ?? -1) !== null;

            if (! $valid) {
                $this->error('PRICE_LIST_ERROR=Invalid entry: '.json_encode($entry, JSON_UNESCAPED_UNICODE));

                return null;
            }
        }

        return $table;
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
