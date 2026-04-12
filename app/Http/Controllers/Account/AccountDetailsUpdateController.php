<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Requests\Account\UpdateAccountDetailsRequest;
use Illuminate\Http\RedirectResponse;

final class AccountDetailsUpdateController
{
    public function __invoke(UpdateAccountDetailsRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $wantsCompanyInvoice = filter_var($validated['wants_company_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $user->fill([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
            'email' => $validated['email'],
            'street' => $validated['street'],
            'house_number' => $validated['house_number'],
            'apartment_number' => $validated['apartment_number'] ?? null,
            'city' => $validated['city'],
            'postcode' => $validated['postcode'],
            'country' => $validated['country'],
            'phone_number' => $validated['phone_number'],

            'wants_company_invoice' => $wantsCompanyInvoice,
            'company_name' => $wantsCompanyInvoice ? ($validated['company_name'] ?? null) : null,
            'company_tax_id' => $wantsCompanyInvoice ? ($validated['company_tax_id'] ?? null) : null,
            'company_street' => $wantsCompanyInvoice ? ($validated['company_street'] ?? null) : null,
            'company_house_number' => $wantsCompanyInvoice ? ($validated['company_house_number'] ?? null) : null,
            'company_apartment_number' => $wantsCompanyInvoice ? ($validated['company_apartment_number'] ?? null) : null,
            'company_city' => $wantsCompanyInvoice ? ($validated['company_city'] ?? null) : null,
            'company_postcode' => $wantsCompanyInvoice ? ($validated['company_postcode'] ?? null) : null,
            'company_country' => $wantsCompanyInvoice ? ($validated['company_country'] ?? null) : null,
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return redirect()
            ->route('account.details.show')
            ->with('success', 'Your account details have been updated.');
    }
}
