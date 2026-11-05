<?php

declare(strict_types=1);

namespace App\Services\Reh4Mat;

use App\Enums\AttributeDisplayType;
use App\Enums\ProductVariantStatus;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Turns a Reh4Mat product's single default variant into one variant per size and side.
 *
 * The default variant is the template: each option variant copies its price, VAT and stock, and the
 * default is archived (never deleted) so carts and orders that point at it keep working. Prices on
 * existing option variants are never overwritten with an empty price, because Reh4Mat imports carry
 * none and the price-list commands fill them in afterwards.
 */
final class Reh4MatSizeVariantSync
{
    public const SIZE_ATTRIBUTE = 'reh4mat-rozmiar';

    public const SIDE_ATTRIBUTE = 'reh4mat-strona';

    /** Side code => shopper-facing value, in display order. */
    public const SIDES = ['p' => 'Prawa', 'l' => 'Lewa'];

    /**
     * @return list<array{external_variant_id: string, size: ?string, side: ?string}>
     */
    public function plan(Product $product, Reh4MatSizeOptions $options): array
    {
        if (! $options->needsVariants()) {
            return [];
        }

        $sizes = $options->sizes;
        usort($sizes, static fn (string $a, string $b): int => Reh4MatSizeOptions::sortOrder($a) <=> Reh4MatSizeOptions::sortOrder($b));

        $planned = [];

        foreach ($sizes === [] ? [null] : $sizes as $size) {
            foreach ($options->sided ? array_keys(self::SIDES) : [null] as $side) {
                $planned[] = [
                    'external_variant_id' => self::externalVariantId($product, $size, $side),
                    'size' => $size,
                    'side' => $side,
                ];
            }
        }

        return $planned;
    }

    public static function isOptionVariant(ProductVariant $variant): bool
    {
        return preg_match('/^reh4mat-.+-opt-/', (string) $variant->external_variant_id) === 1;
    }

    /**
     * @return array{option_variant_ids: list<int>, created_variant_ids: list<int>, archived_variant_ids: list<int>}
     */
    public function apply(Product $product, ProductVariant $template, Reh4MatSizeOptions $options): array
    {
        $planned = $this->plan($product, $options);
        $result = ['option_variant_ids' => [], 'created_variant_ids' => [], 'archived_variant_ids' => []];

        if ($planned === []) {
            return $result;
        }

        foreach ($planned as $index => $option) {
            $variant = ProductVariant::withTrashed()
                ->where('product_id', $product->id)
                ->where('external_variant_id', $option['external_variant_id'])
                ->first();

            $isNew = $variant === null;

            if ($variant === null) {
                $variant = new ProductVariant([
                    'product_id' => $product->id,
                    'external_variant_id' => $option['external_variant_id'],
                ]);
            } elseif ($variant->trashed()) {
                $variant->restore();
            }

            $variant->fill([
                'sku' => $this->uniqueSku($this->optionSku((string) $template->sku, $option['size'], $option['side']), $option['external_variant_id']),
                'currency' => $template->currency,
                'vat_rate' => $template->vat_rate,
                'stock_status' => $template->stock_status,
                'is_default' => $index === 0,
            ]);

            if ($isNew || $variant->status === ProductVariantStatus::ARCHIVED) {
                $variant->status = $template->status === ProductVariantStatus::ARCHIVED ? ProductVariantStatus::DRAFT : $template->status;
            }

            if ($isNew || $template->price_gross_amount !== null) {
                $variant->price_net_amount = $template->price_net_amount;
                $variant->price_gross_amount = $template->price_gross_amount;
            }

            $variant->save();
            $variant->attributeValues()->sync($this->attributeValueIds($option['size'], $option['side']));

            $result['option_variant_ids'][] = $variant->id;

            if ($isNew) {
                $result['created_variant_ids'][] = $variant->id;
            }
        }

        $stale = ProductVariant::query()
            ->where('product_id', $product->id)
            ->whereKeyNot($result['option_variant_ids'])
            ->where('status', '!=', ProductVariantStatus::ARCHIVED->value)
            ->get();

        foreach ($stale as $variant) {
            // Saved through the model so storefront cache invalidation observers run.
            $variant->status = ProductVariantStatus::ARCHIVED;
            $variant->is_default = false;
            $variant->save();
            $result['archived_variant_ids'][] = $variant->id;
        }

        return $result;
    }

    private static function externalVariantId(Product $product, ?string $size, ?string $side): string
    {
        return 'reh4mat-'.$product->external_id.'-opt-'
            .($size === null ? 'any' : Str::slug(str_replace('/', ' ', $size)))
            .'-'.($side ?? 'any');
    }

    private function optionSku(string $baseSku, ?string $size, ?string $side): string
    {
        return implode('-', array_filter([
            $baseSku,
            $size === null ? null : str_replace('/', '-', $size),
            $side === null ? null : strtoupper($side),
        ], static fn (?string $part): bool => $part !== null && $part !== ''));
    }

    private function uniqueSku(string $sku, string $externalVariantId): string
    {
        $candidate = $sku;
        $suffix = 2;

        while (ProductVariant::withTrashed()
            ->where('sku', $candidate)
            ->where(fn ($query) => $query->whereNull('external_variant_id')->orWhere('external_variant_id', '!=', $externalVariantId))
            ->exists()) {
            $candidate = $sku.'-'.$suffix++;
        }

        return $candidate;
    }

    /**
     * @return list<int>
     */
    private function attributeValueIds(?string $size, ?string $side): array
    {
        $ids = [];

        if ($size !== null) {
            $ids[] = $this->attributeValue(
                $this->attribute(self::SIZE_ATTRIBUTE, 'Rozmiar'),
                $size,
                Reh4MatSizeOptions::sortOrder($size),
            )->id;
        }

        if ($side !== null) {
            $ids[] = $this->attributeValue(
                $this->attribute(self::SIDE_ATTRIBUTE, 'Strona'),
                self::SIDES[$side],
                (int) array_search($side, array_keys(self::SIDES), true) + 1,
            )->id;
        }

        return $ids;
    }

    private function attribute(string $externalId, string $name): Attribute
    {
        return Attribute::query()->firstOrCreate(
            ['external_attribute_id' => $externalId],
            ['name' => $name, 'slug' => $externalId, 'display_type' => AttributeDisplayType::SELECT],
        );
    }

    private function attributeValue(Attribute $attribute, string $value, int $sortOrder): AttributeValue
    {
        $externalOptionId = $attribute->external_attribute_id.'-'.Str::slug(str_replace('/', ' ', $value));

        $attributeValue = AttributeValue::query()->firstOrCreate(
            ['attribute_id' => $attribute->id, 'external_option_id' => $externalOptionId],
            ['value' => $value, 'slug' => Str::slug(str_replace('/', ' ', $value)), 'sort_order' => $sortOrder],
        );

        if ((int) $attributeValue->sort_order !== $sortOrder) {
            $attributeValue->update(['sort_order' => $sortOrder]);
        }

        return $attributeValue;
    }
}
