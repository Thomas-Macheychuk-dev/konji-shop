<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Products;

use App\Enums\AttributeDisplayType;
use App\Enums\Currency;
use App\Enums\VatRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Products\ProductImageUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AdminProductStoreController extends Controller
{
    public function __invoke(
        StoreProductRequest $request,
        ProductImageUploadService $imageUploadService,
    ): RedirectResponse {
        $validated = $request->validated();
        $variants = $validated['variants'];
        $categoryId = $validated['category_id'] ?? null;
        $defaultVariantIndex = $validated['default_variant_index'] ?? array_key_first($variants);
        $uploadedImages = $request->file('product_images', []);

        unset($validated['variants'], $validated['category_id'], $validated['default_variant_index'], $validated['product_images']);

        $product = DB::transaction(function () use ($validated, $variants, $categoryId, $defaultVariantIndex, $uploadedImages, $imageUploadService): Product {
            $product = Product::query()->create([
                ...$validated,
                'slug' => $validated['slug'] ?: $this->uniqueSlug($validated['name']),
            ]);

            if ($categoryId !== null) {
                $product->categories()->sync([
                    (int) $categoryId => ['is_primary' => true],
                ]);
            }

            $createdDefaultVariant = null;
            $firstVariant = null;

            foreach ($variants as $index => $variantData) {
                $vatRate = VatRate::from((int) $variantData['vat_rate']);

                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'sku' => $variantData['sku'],
                    'status' => $variantData['status'],
                    'price_net_amount' => $vatRate->netFromGross((int) $variantData['gross_price_amount']),
                    'price_gross_amount' => (int) $variantData['gross_price_amount'],
                    'currency' => $variantData['currency'],
                    'vat_rate' => $vatRate->value,
                    'stock_status' => $variantData['stock_status'],
                    'is_default' => false,
                    'package_weight_grams' => $variantData['package_weight_grams'],
                    'package_length_mm' => $variantData['package_length_mm'],
                    'package_width_mm' => $variantData['package_width_mm'],
                    'package_height_mm' => $variantData['package_height_mm'],
                ]);

                $firstVariant ??= $variant;

                if ((string) $index === (string) $defaultVariantIndex) {
                    $createdDefaultVariant = $variant;
                }

                $attributeValueIds = $this->attributeValueIds($variantData['attributes'] ?? []);

                if ($attributeValueIds !== []) {
                    $variant->attributeValues()->sync($attributeValueIds);
                }
            }

            ($createdDefaultVariant ?? $firstVariant)?->update([
                'is_default' => true,
            ]);

            $imageUploadService->upload($product, $uploadedImages);

            return $product;
        });

        return redirect()
            ->route('admin.products.edit', $product)
            ->with('success', 'Produkt został utworzony.');
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'produkt';
        $slug = $baseSlug;
        $counter = 2;

        while (Product::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $attributes
     * @return list<int>
     */
    private function attributeValueIds(array $attributes): array
    {
        $attributeValueIds = [];

        foreach ($attributes as $attributeData) {
            $name = trim((string) ($attributeData['name'] ?? ''));
            $value = trim((string) ($attributeData['value'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }

            $attribute = Attribute::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                [
                    'name' => $name,
                    'display_type' => $attributeData['display_type'] ?? AttributeDisplayType::SELECT->value,
                ],
            );

            $attributeValue = AttributeValue::query()->updateOrCreate(
                [
                    'attribute_id' => $attribute->id,
                    'slug' => Str::slug($value),
                ],
                [
                    'value' => $value,
                    'sort_order' => count($attributeValueIds),
                ],
            );

            $attributeValueIds[] = $attributeValue->id;
        }

        return $attributeValueIds;
    }
}
