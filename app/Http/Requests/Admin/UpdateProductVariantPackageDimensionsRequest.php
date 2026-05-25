<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateProductVariantPackageDimensionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $variants = $this->input('variants', []);

        if (! is_array($variants)) {
            return;
        }

        $normalized = [];

        foreach ($variants as $variantId => $data) {
            if (! is_array($data)) {
                continue;
            }

            $normalized[$variantId] = [
                'package_weight_grams' => $this->nullableInteger($data['package_weight_grams'] ?? null),
                'package_length_mm' => $this->nullableInteger($data['package_length_mm'] ?? null),
                'package_width_mm' => $this->nullableInteger($data['package_width_mm'] ?? null),
                'package_height_mm' => $this->nullableInteger($data['package_height_mm'] ?? null),
            ];
        }

        $this->merge([
            'variants' => $normalized,
        ]);
    }

    public function rules(): array
    {
        return [
            'variants' => ['required', 'array'],

            'variants.*.package_weight_grams' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'variants.*.package_length_mm' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'variants.*.package_width_mm' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'variants.*.package_height_mm' => ['nullable', 'integer', 'min:1', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Product|null $product */
            $product = $this->route('product');

            if (! $product instanceof Product) {
                return;
            }

            foreach (array_keys($this->input('variants', [])) as $variantId) {
                if (! $product->variants()->whereKey((int) $variantId)->exists()) {
                    $validator->errors()->add(
                        'variants',
                        __('One of the selected variants does not belong to this product.')
                    );

                    return;
                }
            }
        });
    }

    public function attributes(): array
    {
        return [
            'variants.*.package_weight_grams' => __('Package weight'),
            'variants.*.package_length_mm' => __('Package length'),
            'variants.*.package_width_mm' => __('Package width'),
            'variants.*.package_height_mm' => __('Package height'),
        ];
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : (int) $value;
    }
}
