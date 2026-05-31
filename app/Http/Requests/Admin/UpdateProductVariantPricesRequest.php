<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use App\Enums\VatRate;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateProductVariantPricesRequest extends FormRequest
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

            $grossPrice = $data['gross_price'] ?? null;

            $normalized[$variantId] = [
                'gross_price' => $this->normalizeDecimalString($grossPrice),
                'gross_price_amount' => $this->decimalToMinorUnits($grossPrice),
                'currency' => $data['currency'] ?? null,
                'vat_rate' => $data['vat_rate'] ?? null,
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

            'variants.*.gross_price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'variants.*.gross_price_amount' => ['required', 'integer', 'min:1', 'max:99999999'],
            'variants.*.currency' => ['required', 'string', Rule::in(Currency::options())],
            'variants.*.vat_rate' => ['required', 'integer', Rule::in(VatRate::options())],
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
            'variants.*.gross_price' => __('Gross price'),
            'variants.*.currency' => __('Currency'),
            'variants.*.vat_rate' => __('VAT rate'),
        ];
    }

    private function normalizeDecimalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace(',', '.', (string) $value));

        return $value === '' ? null : $value;
    }

    private function decimalToMinorUnits(mixed $value): ?int
    {
        $value = $this->normalizeDecimalString($value);

        if ($value === null || ! is_numeric($value)) {
            return null;
        }

        return (int) round(((float) $value) * 100);
    }
}
