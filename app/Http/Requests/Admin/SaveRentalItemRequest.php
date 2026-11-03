<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\RentalItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class SaveRentalItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => $this->nullableString($this->input('name')),
            'details' => $this->nullableString($this->input('details')),
            'deposit_min' => $this->nullableString($this->input('deposit_min')),
            'deposit_max' => $this->nullableString($this->input('deposit_max')),
            'monthly_min' => $this->nullableString($this->input('monthly_min')),
            'monthly_max' => $this->nullableString($this->input('monthly_max')),
            'sort_order' => $this->nullableString($this->input('sort_order')),
            'nfz_purchase' => $this->boolean('nfz_purchase'),
            'is_active' => $this->boolean('is_active'),
            'remove_image' => $this->boolean('remove_image'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'group' => ['required', 'string', Rule::in(array_keys(RentalItem::GROUPS))],
            'details' => ['nullable', 'string', 'max:2000'],
            'deposit_min' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'deposit_max' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'monthly_min' => ['required', 'integer', 'min:0', 'max:1000000'],
            'monthly_max' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'nfz_purchase' => ['boolean'],
            'is_active' => ['boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('deposit_max') !== null && $this->input('deposit_min') === null) {
                $validator->errors()->add('deposit_max', 'Podaj kaucję minimalną albo usuń kaucję maksymalną.');
            }

            foreach (['deposit', 'monthly'] as $price) {
                $min = $this->input($price.'_min');
                $max = $this->input($price.'_max');

                if ($min !== null && $max !== null && (int) $max < (int) $min) {
                    $validator->errors()->add($price.'_max', 'Kwota „do” nie może być mniejsza niż kwota „od”.');
                }
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesForModel(): array
    {
        $validated = $this->safe()->except(['image', 'remove_image']);

        foreach (['deposit_min', 'deposit_max', 'monthly_min', 'monthly_max', 'sort_order'] as $field) {
            $validated[$field] = isset($validated[$field]) ? (int) $validated[$field] : null;
        }

        return $validated;
    }

    public function attributes(): array
    {
        return [
            'name' => 'nazwa',
            'group' => 'rodzaj sprzętu',
            'details' => 'opis',
            'deposit_min' => 'kaucja od',
            'deposit_max' => 'kaucja do',
            'monthly_min' => 'opłata miesięczna od',
            'monthly_max' => 'opłata miesięczna do',
            'sort_order' => 'kolejność',
            'image' => 'zdjęcie',
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
