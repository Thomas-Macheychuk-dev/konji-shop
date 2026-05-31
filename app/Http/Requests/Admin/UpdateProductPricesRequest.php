<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Currency;
use App\Enums\VatRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $grossPrice = $this->input('gross_price');

        $this->merge([
            'gross_price' => $this->normalizeDecimalString($grossPrice),
            'gross_price_amount' => $this->decimalToMinorUnits($grossPrice),
        ]);
    }

    public function rules(): array
    {
        return [
            'gross_price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'gross_price_amount' => ['required', 'integer', 'min:1', 'max:99999999'],
            'currency' => ['required', 'string', Rule::in(Currency::options())],
            'vat_rate' => ['required', 'integer', Rule::in(VatRate::options())],
        ];
    }

    public function grossPriceAmount(): int
    {
        return (int) $this->validated('gross_price_amount');
    }

    public function attributes(): array
    {
        return [
            'gross_price' => __('Gross price'),
            'currency' => __('Currency'),
            'vat_rate' => __('VAT rate'),
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
