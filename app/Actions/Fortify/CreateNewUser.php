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
            // The address is optional here: checkout asks for it with the first order.
            'street' => ['nullable', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'apartment_number' => ['nullable', 'string', 'max:50'],
            'city' => ['nullable', 'string', 'max:255'],
            'postcode' => ['nullable', 'string', 'max:32'],
            'country' => [
                'nullable',
                'string',
                'size:2',
                Rule::in(array_keys(config('countries'))),
            ],
            // Required: checkout takes a logged-in customer's phone from the account.
            'phone_number' => ['required', 'string', 'max:32'],
            'terms_accepted' => ['accepted'],

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
        ], [
            'terms_accepted.accepted' => 'Zaakceptuj regulamin sklepu, aby założyć konto.',
        ])->validate();

        $wantsCompanyInvoice = filter_var($input['wants_company_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return User::create([
            'name' => trim($input['first_name'].' '.$input['last_name']),
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'email' => $input['email'],
            'street' => $input['street'] ?? null,
            'house_number' => $input['house_number'] ?? null,
            'apartment_number' => $input['apartment_number'] ?? null,
            'city' => $input['city'] ?? null,
            'postcode' => $input['postcode'] ?? null,
            'country' => ($input['country'] ?? null) ?: 'PL',
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
