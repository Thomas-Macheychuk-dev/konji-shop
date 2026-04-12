<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'email:rfc,dns',
                'max:255',
                Rule::unique(User::class),
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

            'password' => $this->passwordRules(),
        ])->validate();

        $wantsCompanyInvoice = filter_var($input['wants_company_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return User::create([
            'name' => trim($input['first_name'].' '.$input['last_name']),
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'street' => $input['street'],
            'house_number' => $input['house_number'],
            'apartment_number' => $input['apartment_number'] ?? null,
            'city' => $input['city'],
            'postcode' => $input['postcode'],
            'country' => $input['country'],
            'phone_number' => $input['phone_number'],
            'password' => Hash::make($input['password']),

            'wants_company_invoice' => $wantsCompanyInvoice,
            'company_name' => $wantsCompanyInvoice ? ($input['company_name'] ?? null) : null,
            'company_tax_id' => $wantsCompanyInvoice ? ($input['company_tax_id'] ?? null) : null,
            'company_street' => $wantsCompanyInvoice ? ($input['company_street'] ?? null) : null,
            'company_house_number' => $wantsCompanyInvoice ? ($input['company_house_number'] ?? null) : null,
            'company_apartment_number' => $wantsCompanyInvoice ? ($input['company_apartment_number'] ?? null) : null,
            'company_city' => $wantsCompanyInvoice ? ($input['company_city'] ?? null) : null,
            'company_postcode' => $wantsCompanyInvoice ? ($input['company_postcode'] ?? null) : null,
            'company_country' => $wantsCompanyInvoice ? ($input['company_country'] ?? null) : null,
        ]);
    }
}
