<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\StockStatus;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProductVariantStockStatusRequest extends FormRequest
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
                'stock_status' => $data['stock_status'] ?? null,
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
            'variants.*.stock_status' => ['required', 'string', Rule::in(StockStatus::options())],
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
            'variants.*.stock_status' => __('Stock status'),
        ];
    }
}
