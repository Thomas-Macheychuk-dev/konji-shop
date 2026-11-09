<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AttributeDisplayType;
use App\Enums\VatRate;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Splits supplier products priced per size or configuration into shop variants.
 *
 * Tables live in resources/import-data/supplier-prices/variants and are built by
 * scripts/catalogue/supplier-prices-2026. A product is split only while it still has its single importer
 * variant without attribute values: that variant keeps its id and SKU and takes the first table row; one new
 * variant per other row copies its status, stock status and package data. Every variant carries one value of
 * the table's attribute (e.g. Rozmiar), which the product page shows as the option selector.
 *
 * Tables from gross-only sources (timago-online-2026: medians of Polish shop prices) carry rows with
 * "vat": null and "net_minor": null; those take the VAT rate the product already has.
 *
 * Note that re-running the Timago or Vermeiren importer resets a product to a single default variant.
 */
final class ApplySupplierVariantsCommand extends Command
{
    private const LIST_DIRECTORY = 'import-data/supplier-prices/variants';

    protected $signature = 'catalogue:apply-supplier-variants
        {list? : Variant table name under resources/import-data/supplier-prices/variants, e.g. timago-2026.}
        {--write : Create the variants after writing a private backup. Otherwise preview only.}
        {--rollback= : Undo a --write from its backup: remove the created variants and restore the old price.}';

    protected $description = 'Preview, apply or roll back supplier size/configuration variants from a resolved table.';

    public function handle(): int
    {
        if (filled($this->option('rollback'))) {
            return $this->rollback((string) $this->option('rollback'));
        }

        $table = $this->loadTable((string) $this->argument('list'));

        if ($table === null) {
            return self::FAILURE;
        }

        $plans = [];
        $current = 0;
        $skipped = [];
        $missing = [];

        foreach ($table['products'] as $entry) {
            $product = $this->findProduct($table['external_source'], $entry['external_id']);

            if ($product === null) {
                $missing[] = sprintf('external_id=%s sku=%s %s', $entry['external_id'], $entry['sku'] ?? '-', Str::limit((string) $entry['name'], 70));

                continue;
            }

            $entry = $this->withVatRates($product, $entry);

            if ($entry === null) {
                $skipped[] = $this->label($product).' reason=no VAT rate on the variant for gross-only prices';

                continue;
            }

            $state = $this->state($product, $entry);

            if ($state === 'current') {
                $current++;
            } elseif ($state === 'split') {
                $plans[] = ['product' => $product, 'entry' => $entry];
            } else {
                $skipped[] = $this->label($product).' '.$state;
            }
        }

        $newVariants = array_sum(array_map(static fn (array $plan): int => count($plan['entry']['variants']) - 1, $plans));

        $this->line('=== SUPPLIER VARIANTS ===');
        $this->line(sprintf('SUPPLIER=%s LIST=%s EFFECTIVE=%s', $table['supplier'], $table['source_file'] ?? '?', $table['effective_from'] ?? '?'));
        $this->line('PRICING='.($table['pricing'] ?? '?'));
        $this->line(sprintf(
            'TABLE_PRODUCTS=%d TO_SPLIT=%d NEW_VARIANTS=%d ALREADY_CURRENT=%d SKIPPED=%d PRODUCT_MISSING=%d',
            count($table['products']), count($plans), $newVariants, $current, count($skipped), count($missing),
        ));

        foreach ($plans as $plan) {
            $default = $plan['product']->variants->sole();
            $this->line(sprintf('SPLIT %s by %s', $this->label($plan['product']), $plan['entry']['attribute']));

            foreach ($plan['entry']['variants'] as $index => $row) {
                $this->line(sprintf(
                    '  %s %s [%s] gross %s -> %s (net %s, VAT %s)',
                    $index === 0 ? 'KEEP variant='.$default->id : 'NEW',
                    $row['value'],
                    $row['list_row'],
                    $index === 0 ? $this->money($default->price_gross_amount) : 'new',
                    $this->money($row['gross_minor']),
                    $this->money($row['net_minor']),
                    $this->vatChange($index === 0 ? $default->vat_rate : null, $row['vat']),
                ));
            }
        }

        foreach ($skipped as $line) {
            $this->line('SKIPPED '.$line);
        }

        foreach ($missing as $line) {
            $this->line('PRODUCT_MISSING '.$line);
        }

        if (! $this->option('write')) {
            $this->line('MODE=READ_ONLY');

            return self::SUCCESS;
        }

        if ($plans === []) {
            $this->info('NOTHING_TO_WRITE');

            return self::SUCCESS;
        }

        $this->write($table, $plans);
        $this->info('SUPPLIER_VARIANT_WRITE=PASS');

        return self::SUCCESS;
    }

    /**
     * 'split' when the product still has its single plain variant, 'current' when it already carries exactly
     * the table's variants and prices, otherwise the reason it is left alone.
     *
     * @param  array<string, mixed>  $entry
     */
    private function state(Product $product, array $entry): string
    {
        $variants = $product->variants;

        if ($variants->count() === 1 && $variants->first()->attributeValues->isEmpty()) {
            return 'split';
        }

        $attributeKey = $this->attributeKey($entry['attribute']);
        $actual = $variants
            ->map(function (ProductVariant $variant) use ($attributeKey): ?string {
                $value = $variant->attributeValues->firstWhere('attribute.external_attribute_id', $attributeKey);

                return $value === null ? null : implode('|', [
                    $value->slug,
                    $variant->price_net_amount === null ? '' : (int) $variant->price_net_amount,
                    $variant->price_gross_amount ?? '',
                    $variant->vat_rate instanceof VatRate ? $variant->vat_rate->value : '',
                ]);
            })
            ->sort()
            ->values()
            ->all();
        $expected = collect($entry['variants'])
            ->map(fn (array $row): string => implode('|', [$this->valueSlug($row['value']), $row['net_minor'], $row['gross_minor'], $row['vat']]))
            ->sort()
            ->values()
            ->all();

        return $actual === $expected
            ? 'current'
            : 'reason=has '.$variants->count().' variants that differ from the table';
    }

    /**
     * @param  array<string, mixed>  $table
     * @param  list<array{product: Product, entry: array<string, mixed>}>  $plans
     */
    private function write(array $table, array $plans): void
    {
        $records = DB::transaction(function () use ($plans): array {
            $records = [];

            foreach ($plans as $plan) {
                $records[] = $this->split($plan['product'], $plan['entry']);
            }

            return $records;
        });

        $backupPath = 'catalogue-repairs/supplier-variants-'.$table['supplier'].'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.json';
        Storage::disk('local')->put($backupPath, json_encode([
            'command' => $this->getName(),
            'created_at' => now()->toIso8601String(),
            'price_list' => $table['source_file'] ?? null,
            'products' => $records,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->info('BACKUP='.$backupPath);
        $this->info('SPLIT_PRODUCTS='.count($records).' CREATED_VARIANTS='.array_sum(array_map(static fn (array $record): int => count($record['created_variant_ids']), $records)));
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function split(Product $product, array $entry): array
    {
        $default = ProductVariant::query()->lockForUpdate()->findOrFail($product->variants->sole()->id);

        if ($default->attributeValues()->exists() || ProductVariant::query()->where('product_id', $product->id)->count() !== 1) {
            throw new RuntimeException('Product '.$product->id.' changed since the preview; run the preview again.');
        }

        $attributeKey = $this->attributeKey($entry['attribute']);
        $attribute = Attribute::query()->firstOrCreate(
            ['external_attribute_id' => $attributeKey],
            ['name' => $entry['attribute'], 'slug' => $attributeKey, 'display_type' => AttributeDisplayType::SELECT],
        );
        $existingProductValueIds = $product->attributeValues()->pluck('attribute_values.id')->all();
        $first = $entry['variants'][0];
        $record = [
            'product_id' => $product->id,
            'default_variant' => [
                'id' => $default->id,
                'old_net' => $default->price_net_amount === null ? null : (int) $default->price_net_amount,
                'old_gross' => $default->price_gross_amount,
                'old_vat' => $default->vat_rate instanceof VatRate ? $default->vat_rate->value : null,
                'new_net' => $first['net_minor'],
                'new_gross' => $first['gross_minor'],
                'new_vat' => $first['vat'],
            ],
            'attribute_value_ids' => [],
            'created_variant_ids' => [],
            'attached_product_value_ids' => [],
        ];

        foreach ($entry['variants'] as $index => $row) {
            $sortOrder = $this->sortOrder($row['value'], $index);
            $value = AttributeValue::query()->firstOrCreate(
                ['attribute_id' => $attribute->id, 'slug' => $this->valueSlug($row['value'])],
                ['value' => $row['value'], 'sort_order' => $sortOrder],
            );

            if ((int) $value->sort_order !== $sortOrder) {
                $value->update(['sort_order' => $sortOrder]);
            }

            $record['attribute_value_ids'][] = $value->id;

            if ($index === 0) {
                $variant = $default;
                $variant->price_net_amount = $row['net_minor'];
                $variant->price_gross_amount = $row['gross_minor'];
                $variant->vat_rate = VatRate::from($row['vat']);
                $variant->save();
            } else {
                $suffix = $this->valueSlug($row['value']);
                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'external_variant_id' => $this->freeExternalVariantId($product->id, ($default->external_variant_id ?? 'supplier-'.$product->id).'-'.$suffix),
                    'sku' => $default->sku === null ? null : $this->freeSku($default->sku.'-'.Str::upper($suffix)),
                    'status' => $default->status,
                    'price_net_amount' => $row['net_minor'],
                    'price_gross_amount' => $row['gross_minor'],
                    'currency' => $default->currency,
                    'vat_rate' => VatRate::from($row['vat']),
                    'stock_status' => $default->stock_status,
                    'is_default' => false,
                    'package_weight_grams' => $default->package_weight_grams,
                    'package_length_mm' => $default->package_length_mm,
                    'package_width_mm' => $default->package_width_mm,
                    'package_height_mm' => $default->package_height_mm,
                ]);
                $record['created_variant_ids'][] = $variant->id;
            }

            $variant->attributeValues()->syncWithoutDetaching([$value->id]);

            if (! in_array($value->id, $existingProductValueIds, true)) {
                $product->attributeValues()->syncWithoutDetaching([$value->id]);
                $record['attached_product_value_ids'][] = $value->id;
                $existingProductValueIds[] = $value->id;
            }
        }

        // Pivot changes fire no model events; touching the product clears its storefront cache.
        $product->touch();

        return $record;
    }

    private function rollback(string $backupPath): int
    {
        if (! Storage::disk('local')->exists($backupPath)) {
            $this->error('BACKUP_NOT_FOUND='.$backupPath);

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($backupPath), true, flags: JSON_THROW_ON_ERROR);

        $restored = DB::transaction(function () use ($backup): int {
            $restored = 0;

            foreach ($backup['products'] as $record) {
                $old = $record['default_variant'];
                $default = ProductVariant::query()->lockForUpdate()->find($old['id']);
                $created = ProductVariant::query()->whereKey($record['created_variant_ids'])->get();
                $currentNet = $default?->price_net_amount === null ? null : (int) $default->price_net_amount;
                $currentVat = $default?->vat_rate instanceof VatRate ? $default->vat_rate->value : null;

                if ($default === null
                    || $created->count() !== count($record['created_variant_ids'])
                    || $currentNet !== $old['new_net']
                    || $default->price_gross_amount !== $old['new_gross']
                    || $currentVat !== $old['new_vat']) {
                    $this->warn('SKIPPED_CHANGED_SINCE product='.$record['product_id']);

                    continue;
                }

                // Soft delete: order history and carts may still point at the created variants.
                $created->each(fn (ProductVariant $variant) => $variant->delete());
                $default->attributeValues()->detach($record['attribute_value_ids']);
                $default->price_net_amount = $old['old_net'];
                $default->price_gross_amount = $old['old_gross'];
                $default->vat_rate = $old['old_vat'] === null ? null : VatRate::from($old['old_vat']);
                $default->save();

                $product = Product::query()->find($record['product_id']);
                $product?->attributeValues()->detach($record['attached_product_value_ids']);
                $product?->touch();
                $restored++;
            }

            return $restored;
        });

        $this->info('RESTORED_PRODUCTS='.$restored.' OF '.count($backup['products']));
        $this->info('SUPPLIER_VARIANT_ROLLBACK=PASS');

        return self::SUCCESS;
    }

    private function findProduct(string $source, string $externalId): ?Product
    {
        return Product::query()
            ->where('external_source', $source)
            ->where('external_id', $externalId)
            ->with(['variants' => fn ($query) => $query->orderBy('id')->with('attributeValues.attribute')])
            ->first();
    }

    /**
     * Rows from gross-only sources (online shop prices) carry "vat": null; they take the VAT rate of the
     * product's default variant and derive the net price from it. Null when that variant has no rate.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private function withVatRates(Product $product, array $entry): ?array
    {
        $default = $product->variants->firstWhere('is_default', true) ?? $product->variants->first();
        $rate = $default?->vat_rate instanceof VatRate ? $default->vat_rate : null;

        foreach ($entry['variants'] as $index => $row) {
            if ($row['vat'] !== null) {
                continue;
            }

            if ($rate === null) {
                return null;
            }

            $entry['variants'][$index]['vat'] = $rate->value;
            $entry['variants'][$index]['net_minor'] = $rate->netFromGross($row['gross_minor']);
        }

        return $entry;
    }

    /**
     * The supplier variants get their own attributes (like reh4mat-rozmiar), so their value order is theirs.
     */
    private function attributeKey(string $name): string
    {
        return 'supplier-'.Str::slug($name);
    }

    /**
     * The product page orders option values by sort_order. Letter sizes are shared by several products, so they
     * follow one size ladder; other values keep the table order.
     */
    private function sortOrder(string $value, int $index): int
    {
        $ladder = ['XS' => 10, 'S' => 20, 'S+' => 25, 'M' => 30, 'M+' => 35, 'L' => 40, 'XL' => 50, 'XXL' => 60];

        return $ladder[$value] ?? $index + 1;
    }

    /**
     * Str::slug drops "+", which would make S and S+ the same value.
     */
    private function valueSlug(string $value): string
    {
        return Str::slug(str_replace('+', ' plus ', $value));
    }

    private function freeSku(string $candidate): string
    {
        $sku = Str::limit($candidate, 240, '');

        for ($n = 2; ProductVariant::withTrashed()->where('sku', $sku)->exists(); $n++) {
            $sku = Str::limit($candidate, 240, '').'-'.$n;
        }

        return $sku;
    }

    private function freeExternalVariantId(int $productId, string $candidate): string
    {
        $id = Str::limit($candidate, 240, '');

        for ($n = 2; ProductVariant::withTrashed()->where('product_id', $productId)->where('external_variant_id', $id)->exists(); $n++) {
            $id = Str::limit($candidate, 240, '').'-'.$n;
        }

        return $id;
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
            $this->error('VARIANT_LIST_ERROR=Choose one of: '.$available->implode(', '));

            return null;
        }

        try {
            $table = json_decode((string) file_get_contents(resource_path(self::LIST_DIRECTORY.'/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error('VARIANT_LIST_ERROR='.$exception->getMessage());

            return null;
        }

        if (! is_array($table['products'] ?? null) || ($table['currency'] ?? null) !== 'PLN' || blank($table['external_source'] ?? null)) {
            $this->error('VARIANT_LIST_ERROR=The table must hold PLN products for one external source.');

            return null;
        }

        foreach ($table['products'] as $product) {
            $rows = $product['variants'] ?? null;
            $valid = is_string($product['external_id'] ?? null)
                && filled($product['attribute'] ?? null)
                // One row is allowed: a product sold in several versions of which only one is priced keeps that
                // version visible as its single variant.
                && is_array($rows) && count($rows) >= 1;

            foreach ($valid ? $rows : [] as $row) {
                $keepsVat = array_key_exists('vat', $row) && $row['vat'] === null;
                $valid = $valid
                    && filled($row['value'] ?? null)
                    && is_int($row['gross_minor'] ?? null) && $row['gross_minor'] > 0
                    && ($keepsVat
                        ? ($row['net_minor'] ?? null) === null
                        : is_int($row['net_minor'] ?? null) && $row['net_minor'] > 0
                            && $row['gross_minor'] >= $row['net_minor']
                            && VatRate::tryFrom($row['vat'] ?? -1) !== null);
            }

            $slugs = $valid ? array_map(fn (array $row): string => $this->valueSlug($row['value']), $rows) : [];

            if (! $valid || count(array_unique($slugs)) !== count($slugs) || in_array('', $slugs, true)) {
                $this->error('VARIANT_LIST_ERROR=Invalid product: '.json_encode($product, JSON_UNESCAPED_UNICODE));

                return null;
            }
        }

        return $table;
    }

    private function label(Product $product): string
    {
        $status = $product->status;

        return sprintf(
            'product=%d [%s] %s',
            $product->id,
            $status instanceof \BackedEnum ? $status->value : (string) $status,
            Str::limit((string) $product->name, 70),
        );
    }

    private function vatChange(?VatRate $old, int $new): string
    {
        return $old === null || $old->value === $new ? $new.'%' : $old->value.'% -> '.$new.'%';
    }

    private function money(?int $minor): string
    {
        return $minor === null ? '(empty)' : number_format($minor / 100, 2, '.', '');
    }
}
