<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\AttributeDisplayType;
use App\Enums\CategoryStatus;
use App\Enums\Currency;
use App\Enums\ProductStatus;
use App\Enums\ProductVariantStatus;
use App\Enums\StockStatus;
use App\Enums\VatRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Illuminate\Support\Str;

final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $variants = $this->input('variants', []);
        $normalizedVariants = [];

        if (is_array($variants)) {
            foreach ($variants as $index => $data) {
                if (! is_array($data) || ! $this->variantRowHasData($data)) {
                    continue;
                }

                $grossPrice = $data['gross_price'] ?? null;

                $normalizedVariants[$index] = [
                    'sku' => $this->nullableString($data['sku'] ?? null),
                    'status' => $data['status'] ?? null,
                    'gross_price' => $this->normalizeDecimalString($grossPrice),
                    'gross_price_amount' => $this->decimalToMinorUnits($grossPrice),
                    'currency' => $data['currency'] ?? null,
                    'vat_rate' => $data['vat_rate'] ?? null,
                    'stock_status' => $data['stock_status'] ?? null,
                    'package_weight_grams' => $this->nullableInteger($data['package_weight_grams'] ?? null),
                    'package_length_mm' => $this->nullableInteger($data['package_length_mm'] ?? null),
                    'package_width_mm' => $this->nullableInteger($data['package_width_mm'] ?? null),
                    'package_height_mm' => $this->nullableInteger($data['package_height_mm'] ?? null),
                    'attributes' => $this->normalizedAttributes($data['attributes'] ?? []),
                ];
            }
        }

        $this->merge([
            'slug' => $this->normalizeSlug($this->input('slug')),
            'variants' => $normalizedVariants,
            'default_variant_index' => $this->nullableInteger($this->input('default_variant_index')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('products', 'slug')],
            'short_description' => ['nullable', 'string', 'max:5000'],
            'description' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:5000'],
            'status' => ['required', 'string', Rule::in(ProductStatus::options())],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')
                    ->where('status', CategoryStatus::ACTIVE->value)
                    ->whereNull('deleted_at'),
            ],
            'default_variant_index' => ['nullable', 'integer'],
            'product_images' => ['nullable', 'array', 'max:10'],
            'product_images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],

            'variants' => ['required', 'array', 'min:1'],
            'variants.*.sku' => ['required', 'string', 'max:255', Rule::unique('product_variants', 'sku')],
            'variants.*.status' => ['required', 'string', Rule::in(ProductVariantStatus::options())],
            'variants.*.gross_price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'variants.*.gross_price_amount' => ['required', 'integer', 'min:1', 'max:99999999'],
            'variants.*.currency' => ['required', 'string', Rule::in(Currency::options())],
            'variants.*.vat_rate' => ['required', 'integer', Rule::in(VatRate::options())],
            'variants.*.stock_status' => ['required', 'string', Rule::in(StockStatus::options())],
            'variants.*.package_weight_grams' => ['required', 'integer', 'min:1', 'max:100000'],
            'variants.*.package_length_mm' => ['required', 'integer', 'min:1', 'max:5000'],
            'variants.*.package_width_mm' => ['required', 'integer', 'min:1', 'max:5000'],
            'variants.*.package_height_mm' => ['required', 'integer', 'min:1', 'max:5000'],

            'variants.*.attributes' => ['array', 'max:5'],
            'variants.*.attributes.*.name' => ['required_with:variants.*.attributes.*.value', 'nullable', 'string', 'max:255'],
            'variants.*.attributes.*.value' => ['required_with:variants.*.attributes.*.name', 'nullable', 'string', 'max:255'],
            'variants.*.attributes.*.display_type' => ['nullable', 'string', Rule::in(AttributeDisplayType::options())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $variants = $this->input('variants', []);
            $defaultVariantIndex = $this->input('default_variant_index');

            if ($defaultVariantIndex !== null && is_array($variants) && ! array_key_exists((string) $defaultVariantIndex, $variants) && ! array_key_exists((int) $defaultVariantIndex, $variants)) {
                $validator->errors()->add(
                    'default_variant_index',
                    'Wybrany wariant domyślny musi być jednym z uzupełnionych wariantów.'
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'name' => __('Product name'),
            'slug' => 'Slug',
            'short_description' => __('Short description'),
            'description' => __('Product HTML description'),
            'seo_title' => __('SEO title'),
            'seo_description' => __('SEO description'),
            'status' => __('Product status'),
            'category_id' => __('Product category'),
            'default_variant_index' => 'wariant domyślny',
            'product_images' => 'zdjęcia produktu',
            'product_images.*' => 'zdjęcie produktu',
            'variants' => 'warianty',
            'variants.*.sku' => 'SKU wariantu',
            'variants.*.status' => 'status wariantu',
            'variants.*.gross_price' => __('Gross price'),
            'variants.*.currency' => __('Currency'),
            'variants.*.vat_rate' => __('VAT rate'),
            'variants.*.stock_status' => __('Stock status'),
            'variants.*.package_weight_grams' => __('Package weight'),
            'variants.*.package_length_mm' => __('Package length'),
            'variants.*.package_width_mm' => __('Package width'),
            'variants.*.package_height_mm' => __('Package height'),
            'variants.*.attributes.*.name' => 'nazwa atrybutu',
            'variants.*.attributes.*.value' => 'wartość atrybutu',
            'variants.*.attributes.*.display_type' => 'typ wyświetlania atrybutu',
        ];
    }

    private function variantRowHasData(array $data): bool
    {
        foreach ([
            'sku',
            'gross_price',
            'package_weight_grams',
            'package_length_mm',
            'package_width_mm',
            'package_height_mm',
        ] as $field) {
            if ($this->nullableString($data[$field] ?? null) !== null) {
                return true;
            }
        }

        foreach (($data['attributes'] ?? []) as $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            if ($this->nullableString($attribute['name'] ?? null) !== null || $this->nullableString($attribute['value'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private function normalizedAttributes(mixed $attributes): array
    {
        if (! is_array($attributes)) {
            return [];
        }

        $normalized = [];

        foreach ($attributes as $index => $attribute) {
            if (! is_array($attribute)) {
                continue;
            }

            $name = $this->nullableString($attribute['name'] ?? null);
            $value = $this->nullableString($attribute['value'] ?? null);

            if ($name === null && $value === null) {
                continue;
            }

            $normalized[$index] = [
                'name' => $name,
                'value' => $value,
                'display_type' => $attribute['display_type'] ?? AttributeDisplayType::SELECT->value,
            ];
        }

        return $normalized;
    }

    private function normalizeDecimalString(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        return str_replace(',', '.', $value);
    }

    private function decimalToMinorUnits(mixed $value): ?int
    {
        $value = $this->normalizeDecimalString($value);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }

    private function nullableInteger(mixed $value): ?int
    {
        $value = $this->nullableString($value);

        return $value === null ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeSlug(mixed $value): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null) {
            return null;
        }

        return Str::slug($value);
    }
}
