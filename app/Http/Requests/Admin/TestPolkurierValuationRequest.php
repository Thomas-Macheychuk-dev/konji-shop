<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TestPolkurierValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'courier_code' => strtoupper((string) $this->input('courier_code', 'UPS')),
            'recipient_country' => strtoupper((string) $this->input('recipient_country', 'PL')),
        ]);
    }

    public function rules(): array
    {
        return [
            'courier_code' => [
                'required',
                'string',
                Rule::in([
                    'UPS',
                    'DPD',
                    'INPOST',
                    'INPOST_PACZKOMAT',
                ]),
            ],
            'recipient_postcode' => ['required', 'string', 'max:20'],
            'recipient_country' => ['required', 'string', 'size:2'],
        ];
    }

    public function attributes(): array
    {
        return [
            'courier_code' => __('Courier'),
            'recipient_postcode' => __('Recipient postcode'),
            'recipient_country' => __('Recipient country'),
        ];
    }
}
