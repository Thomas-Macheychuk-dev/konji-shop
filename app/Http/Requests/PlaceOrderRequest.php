<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $billingAddressSource = $this->input('billing_address_source', 'same_as_shipping');

        $this->merge([
            'billing_address_source' => $billingAddressSource,
            'billing_same_as_shipping' => $billingAddressSource === 'same_as_shipping',
            'terms_accepted' => $this->boolean('terms_accepted'),
        ]);

        if ($billingAddressSource === 'company_address' && $this->user()) {
            $companyAddress = $this->user()->checkoutCompanyBillingAddressDefaults();

            $this->merge([
                'billing_first_name' => $companyAddress['first_name'] ?? null,
                'billing_last_name' => $companyAddress['last_name'] ?? null,
                'billing_company' => $companyAddress['company'] ?? null,
                'billing_address_line_1' => $companyAddress['address_line_1'] ?? null,
                'billing_address_line_2' => $companyAddress['address_line_2'] ?? null,
                'billing_city' => $companyAddress['city'] ?? null,
                'billing_postcode' => $companyAddress['postcode'] ?? null,
                'billing_country_code' => $companyAddress['country_code'] ?? null,
            ]);
        }

        if ($this->user()) {
            $this->merge([
                'email' => (string) $this->user()->email,
                'phone' => (string) ($this->user()->phone_number ?? ''),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc,dns', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],

            'shipping_first_name' => ['required', 'string', 'max:100'],
            'shipping_last_name' => ['required', 'string', 'max:100'],
            'shipping_company' => ['nullable', 'string', 'max:150'],
            'shipping_address_line_1' => ['required', 'string', 'max:255'],
            'shipping_address_line_2' => ['nullable', 'string', 'max:255'],
            'shipping_city' => ['required', 'string', 'max:150'],
            'shipping_postcode' => ['required', 'string', 'max:30'],
            'shipping_country_code' => ['required', 'string', 'size:2', Rule::in(array_keys(config('countries', [])))],

            'billing_address_source' => ['required', Rule::in(['same_as_shipping', 'company_address', 'other'])],
            'billing_same_as_shipping' => ['required', 'boolean'],

            'billing_first_name' => [
                Rule::requiredIf(fn (): bool => $this->input('billing_address_source') === 'other'),
                'nullable',
                'string',
                'max:100',
            ],
            'billing_last_name' => [
                Rule::requiredIf(fn (): bool => $this->input('billing_address_source') === 'other'),
                'nullable',
                'string',
                'max:100',
            ],
            'billing_company' => ['nullable', 'string', 'max:150'],
            'billing_address_line_1' => [
                Rule::requiredIf(fn (): bool => $this->input('billing_address_source') === 'other'),
                'nullable',
                'string',
                'max:255',
            ],
            'billing_address_line_2' => ['nullable', 'string', 'max:255'],
            'billing_city' => [
                Rule::requiredIf(fn (): bool => $this->input('billing_address_source') === 'other'),
                'nullable',
                'string',
                'max:150',
            ],
            'billing_postcode' => [
                Rule::requiredIf(fn (): bool => $this->input('billing_address_source') === 'other'),
                'nullable',
                'string',
                'max:30',
            ],
            'billing_country_code' => [
                Rule::requiredIf(fn (): bool => $this->input('billing_address_source') === 'other'),
                'nullable',
                'string',
                'size:2',
                Rule::in(array_keys(config('countries', []))),
            ],

            'notes' => ['nullable', 'string', 'max:2000'],

            'terms_accepted' => ['accepted'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('billing_address_source') !== 'company_address') {
                return;
            }

            if (! $this->user()) {
                $validator->errors()->add(
                    'billing_address_source',
                    __('Guests cannot use a company billing address.')
                );

                return;
            }

            if (! $this->user()->hasCompanyAddress()) {
                $validator->errors()->add(
                    'billing_address_source',
                    __('Your company address is incomplete.')
                );
            }
        });
    }

    public function attributes(): array
    {
        return [
            'email' => __('E-mail'),
            'phone' => __('Phone'),

            'shipping_first_name' => __('Shipping first name'),
            'shipping_last_name' => __('Shipping last name'),
            'shipping_company' => __('Shipping company'),
            'shipping_address_line_1' => __('Shipping address line 1'),
            'shipping_address_line_2' => __('Shipping address line 2'),
            'shipping_city' => __('Shipping city'),
            'shipping_postcode' => __('Shipping postcode'),
            'shipping_country_code' => __('Shipping country'),

            'billing_address_source' => __('Billing address option'),
            'billing_first_name' => __('Billing first name'),
            'billing_last_name' => __('Billing last name'),
            'billing_company' => __('Billing company'),
            'billing_address_line_1' => __('Billing address line 1'),
            'billing_address_line_2' => __('Billing address line 2'),
            'billing_city' => __('Billing city'),
            'billing_postcode' => __('Billing postcode'),
            'billing_country_code' => __('Billing country'),

            'notes' => __('Order notes'),
            'terms_accepted' => __('Terms and conditions'),
        ];
    }

    public function messages(): array
    {
        return [
            'terms_accepted.accepted' => __('You must accept the terms and conditions to place the order.'),
        ];
    }
}
