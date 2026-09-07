<?php

declare(strict_types=1);

namespace App\Services\Antar;

use App\Enums\Currency;
use App\Models\Product;
use App\Models\ProductVariant;

final class AntarPriceUpdatePlanner
{
    public function __construct(
        private readonly AntarSupplierPriceList $supplierPriceList,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $priceList = $this->supplierPriceList->load();
        $safeIndex = $priceList['safe_index'];
        $ambiguousIndex = $priceList['ambiguous_index'];

        $products = Product::query()
            ->where('external_source', 'antar')
            ->with(['variants' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->get();

        $plans = [];
        $reviewItems = [];
        $hardErrors = [];
        $matched = 0;
        $changed = 0;
        $unchanged = 0;
        $missingSku = 0;
        $unmatched = 0;
        $ambiguous = 0;
        $defaultVariants = 0;

        foreach ($products as $product) {
            $variants = $product->variants;
            $defaultVariant = $variants->first(fn (ProductVariant $variant): bool => $variant->is_default);
            $defaultVariantCount = $variants->filter(fn (ProductVariant $variant): bool => $variant->is_default)->count();

            if ($variants->count() !== 1 || $defaultVariantCount !== 1 || ! $defaultVariant instanceof ProductVariant) {
                $hardErrors[] = sprintf(
                    '%s | product_id=%d: expected exactly one live default Antar variant; found variants=%d, default_variants=%d.',
                    $product->name,
                    $product->id,
                    $variants->count(),
                    $defaultVariantCount,
                );

                $plans[] = $this->basePlan($product, null, 'structural_error', null);

                continue;
            }

            $defaultVariants++;
            $normalizedCode = $this->supplierPriceList->normalizeCode($product->external_parent_sku);

            if ($normalizedCode === null) {
                $missingSku++;
                $reviewItems[] = $product->name.': existing Antar product has no external_parent_sku; supplier price cannot be matched.';
                $plans[] = $this->basePlan($product, $defaultVariant, 'missing_supplier_code', null);

                continue;
            }

            if (isset($ambiguousIndex[$normalizedCode])) {
                $ambiguous++;
                $sourceRows = implode(', ', $ambiguousIndex[$normalizedCode]['source_rows']);
                $reviewItems[] = sprintf(
                    '%s | %s: supplier price list has multiple prices for this code (source rows %s); do not guess a price.',
                    $product->name,
                    $normalizedCode,
                    $sourceRows,
                );
                $plans[] = $this->basePlan($product, $defaultVariant, 'ambiguous_supplier_price', $normalizedCode);

                continue;
            }

            $supplier = $safeIndex[$normalizedCode] ?? null;

            if (! is_array($supplier)) {
                $unmatched++;
                $reviewItems[] = sprintf(
                    '%s | %s: no deterministic price exists in the 1 September 2026 Antar supplier list.',
                    $product->name,
                    $normalizedCode,
                );
                $plans[] = $this->basePlan($product, $defaultVariant, 'supplier_price_not_found', $normalizedCode);

                continue;
            }

            $matched++;
            $currentCurrency = $defaultVariant->currency?->value;
            $currentVat = $defaultVariant->vat_rate?->value;
            $isChanged = $defaultVariant->price_net_amount !== $supplier['net_minor']
                || $defaultVariant->price_gross_amount !== $supplier['gross_minor']
                || $currentVat !== $supplier['vat_rate']
                || $currentCurrency !== Currency::PLN->value;

            if ($isChanged) {
                $changed++;
            } else {
                $unchanged++;
            }

            $plan = $this->basePlan($product, $defaultVariant, $isChanged ? 'change' : 'unchanged', $normalizedCode);
            $plan['supplier'] = [
                'source_rows' => $supplier['source_rows'],
                'descriptions' => $supplier['descriptions'],
                'effective_from' => AntarSupplierPriceList::EFFECTIVE_FROM,
            ];
            $plan['proposed'] = [
                'price_net_amount' => $supplier['net_minor'],
                'price_gross_amount' => $supplier['gross_minor'],
                'vat_rate' => $supplier['vat_rate'],
                'currency' => Currency::PLN->value,
            ];
            $plans[] = $plan;
        }

        $ready = $hardErrors === []
            && $products->count() > 0
            && $matched === $products->count();

        return [
            'schema_version' => 'konji.antar.price-update-plan.v1',
            'source' => 'antar',
            'database_writes' => false,
            'supplier_price_list' => $priceList['metadata'],
            'supplier_summary' => $priceList['summary'],
            'database_summary' => [
                'products' => $products->count(),
                'default_variants' => $defaultVariants,
                'matched_products' => $matched,
                'changed_products' => $changed,
                'unchanged_products' => $unchanged,
                'missing_supplier_code_products' => $missingSku,
                'unmatched_supplier_price_products' => $unmatched,
                'ambiguous_supplier_price_products' => $ambiguous,
            ],
            'hard_error_count' => count($hardErrors),
            'hard_errors' => $hardErrors,
            'review_item_count' => count($reviewItems),
            'review_items' => $reviewItems,
            'ready_for_price_write' => $ready,
            'products' => $plans,
        ];
    }

    /** @return array<string, mixed> */
    private function basePlan(Product $product, ?ProductVariant $variant, string $status, ?string $normalizedCode): array
    {
        return [
            'product_id' => $product->id,
            'product_external_id' => $product->external_id,
            'product_name' => $product->name,
            'external_parent_sku' => $product->external_parent_sku,
            'normalized_supplier_code' => $normalizedCode,
            'variant_id' => $variant?->id,
            'variant_external_id' => $variant?->external_variant_id,
            'variant_sku' => $variant?->sku,
            'status' => $status,
            'current' => $variant === null ? null : [
                'price_net_amount' => $variant->price_net_amount,
                'price_gross_amount' => $variant->price_gross_amount,
                'vat_rate' => $variant->vat_rate?->value,
                'currency' => $variant->currency?->value,
            ],
            'proposed' => null,
            'supplier' => null,
        ];
    }
}
