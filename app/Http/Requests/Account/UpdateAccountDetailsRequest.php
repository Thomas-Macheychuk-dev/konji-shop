<?php

declare(strict_types=1);

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateAccountDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email:rfc,dns',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()?->id),
            ],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['required', 'string', 'max:50'],
            'apartment_number' => ['nullable', 'string', 'max:50'],
            'city' => ['required', 'string', 'max:255'],
            'postcode' => ['required', 'string', 'max:32'],
            'country' => [
                'required',
                'string',
                'size:2',
                Rule::in(array_keys(config('countries'))),
            ],
            'phone_number' => ['required', 'string', 'max:32'],

            'wants_company_invoice' => ['nullable', 'boolean'],
            'company_name' => ['nullable', 'required_if:wants_company_invoice,1', 'string', 'max:255'],
            'company_tax_id' => ['nullable', 'required_if:wants_company_invoice,1', 'string', 'max:100'],
            'company_street' => ['nullable', 'required_if:wants_company_invoice,1', 'string', 'max:255'],
            'company_house_number' => ['nullable', 'required_if:wants_company_invoice,1', 'string', 'max:50'],
            'company_apartment_number' => ['nullable', 'string', 'max:50'],
            'company_city' => ['nullable', 'required_if:wants_company_invoice,1', 'string', 'max:255'],
            'company_postcode' => ['nullable', 'required_if:wants_company_invoice,1', 'string', 'max:50'],
            'company_country' => [
                'nullable',
                'required_if:wants_company_invoice,1',
                'string',
                'size:2',
                Rule::in(array_keys(config('countries'))),
            ],
        ];
    }
}
