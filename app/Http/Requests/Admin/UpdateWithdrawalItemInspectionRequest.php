<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\WithdrawalItemCondition;
use App\Models\WithdrawalRequestItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UpdateWithdrawalItemInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'inspection_hygienic_seal_broken' => $this->nullableBoolean('inspection_hygienic_seal_broken'),
            'inspection_signs_of_use' => $this->boolean('inspection_signs_of_use'),
            'inspection_soiling' => $this->boolean('inspection_soiling'),
            'inspection_resellable' => $this->nullableBoolean('inspection_resellable'),
            'inspection_commercial_value' => $this->normalizeDecimalString($this->input('inspection_commercial_value')),
            'inspection_commercial_value_amount' => $this->decimalToMinorUnits($this->input('inspection_commercial_value')),
            'value_reduction' => $this->normalizeDecimalString($this->input('value_reduction')) ?? '0',
            'value_reduction_amount' => $this->decimalToMinorUnits($this->input('value_reduction')) ?? 0,
        ]);
    }

    public function rules(): array
    {
        return [
            'inspection_condition' => ['required', Rule::enum(WithdrawalItemCondition::class)],
            'inspection_hygienic_seal_broken' => ['nullable', 'boolean'],
            'inspection_signs_of_use' => ['required', 'boolean'],
            'inspection_soiling' => ['required', 'boolean'],
            'inspection_missing_parts' => ['nullable', 'string', 'max:2000'],
            'inspection_resellable' => ['nullable', 'boolean'],
            'inspection_commercial_value' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'inspection_commercial_value_amount' => ['nullable', 'integer', 'min:0'],
            'value_reduction' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'value_reduction_amount' => ['required', 'integer', 'min:0'],
            'value_reduction_basis' => ['nullable', 'string', 'max:5000'],
            'photos' => ['nullable', 'array', 'max:10'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $item = $this->route('item');
                $reduction = (int) $this->input('value_reduction_amount');

                if ($item instanceof WithdrawalRequestItem && $reduction > (int) $item->line_gross_amount) {
                    $validator->errors()->add(
                        'value_reduction',
                        'Zmniejszenie wartości nie może przekraczać kwoty brutto pozycji.'
                    );
                }

                if ($reduction > 0 && trim((string) $this->input('value_reduction_basis')) === '') {
                    $validator->errors()->add(
                        'value_reduction_basis',
                        'Podaj podstawę wyliczenia zmniejszenia wartości (art. 34 ust. 4 ustawy o prawach konsumenta).'
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectionAttributes(): array
    {
        $basis = trim((string) $this->validated('value_reduction_basis'));
        $missingParts = trim((string) $this->validated('inspection_missing_parts'));

        return [
            'inspection_condition' => $this->validated('inspection_condition'),
            'inspection_hygienic_seal_broken' => $this->validated('inspection_hygienic_seal_broken'),
            'inspection_signs_of_use' => (bool) $this->validated('inspection_signs_of_use'),
            'inspection_soiling' => (bool) $this->validated('inspection_soiling'),
            'inspection_missing_parts' => $missingParts === '' ? null : $missingParts,
            'inspection_resellable' => $this->validated('inspection_resellable'),
            'inspection_commercial_value_amount' => $this->validated('inspection_commercial_value_amount'),
            'value_reduction_amount' => (int) $this->validated('value_reduction_amount'),
            'value_reduction_basis' => $basis === '' ? null : $basis,
        ];
    }

    public function attributes(): array
    {
        return [
            'inspection_condition' => 'stan towaru',
            'inspection_hygienic_seal_broken' => 'zabezpieczenie higieniczne',
            'inspection_missing_parts' => 'brakujące elementy',
            'inspection_resellable' => 'możliwość ponownej sprzedaży',
            'inspection_commercial_value' => 'wartość handlowa po zwrocie',
            'value_reduction' => 'zmniejszenie wartości',
            'value_reduction_basis' => 'podstawa wyliczenia zmniejszenia wartości',
            'photos' => 'zdjęcia',
            'photos.*' => 'zdjęcie',
        ];
    }

    private function nullableBoolean(string $key): ?bool
    {
        $value = $this->input($key);

        if ($value === null || $value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
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
