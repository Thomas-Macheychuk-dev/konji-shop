@extends('layouts.storefront')

@section('content')
    <div class="mx-auto w-full max-w-3xl py-10" x-data="{ wantsCompanyInvoice: @js(old('wants_company_invoice', $user->wants_company_invoice)) }">
        <div class="mb-8">
            <x-auth-header
                :title="__('Account details')"
                :description="__('Update your personal and address information below')"
            />
        </div>

        @if (session('success'))
            <div class="my-4 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <form method="POST" action="{{ route('account.details.update') }}" class="flex flex-col gap-6">
            @csrf
            @method('PATCH')

            <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-2">
                <div class="min-h-[4rem]">
                    <flux:input
                        name="first_name"
                        :label="__('First name')"
                        :value="old('first_name', $user->first_name)"
                        type="text"
                        required
                        autofocus
                        autocomplete="given-name"
                        :placeholder="__('First name')"
                    />
                </div>

                <div class="min-h-[4rem]">
                    <flux:input
                        name="last_name"
                        :label="__('Last name')"
                        :value="old('last_name', $user->last_name)"
                        type="text"
                        required
                        autocomplete="family-name"
                        :placeholder="__('Last name')"
                    />
                </div>
            </div>

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email', $user->email)"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <flux:input
                name="street"
                :label="__('Street')"
                :value="old('street', $user->street)"
                type="text"
                required
                autocomplete="address-line1"
                :placeholder="__('Street')"
            />

            <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-2">
                <div class="min-h-[4rem]">
                    <flux:input
                        name="house_number"
                        :label="__('House number')"
                        :value="old('house_number', $user->house_number)"
                        type="text"
                        required
                        autocomplete="address-line2"
                        :placeholder="__('House number')"
                    />
                </div>

                <div class="min-h-[4rem]">
                    <flux:input
                        name="apartment_number"
                        :label="__('Apartment number')"
                        :value="old('apartment_number', $user->apartment_number)"
                        type="text"
                        autocomplete="address-line2"
                        :placeholder="__('Apartment number (optional)')"
                    />
                </div>
            </div>

            <flux:input
                name="city"
                :label="__('City / Town / Village')"
                :value="old('city', $user->city)"
                type="text"
                required
                autocomplete="address-level2"
                :placeholder="__('City / Town / Village')"
            />

            <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-2">
                <div class="min-h-[4rem]">
                    <flux:input
                        name="postcode"
                        :label="__('Postcode')"
                        :value="old('postcode', $user->postcode)"
                        type="text"
                        required
                        autocomplete="postal-code"
                        :placeholder="__('Postcode')"
                    />
                </div>

                <div class="min-h-[4rem] flex flex-col gap-2">
                    <label for="country" class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        {{ __('Country') }}
                    </label>

                    <select
                        id="country"
                        name="country"
                        required
                        autocomplete="country-name"
                        class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <option value="">{{ __('Select a country') }}</option>

                        @foreach (config('countries') as $code => $label)
                            <option value="{{ $code }}" @selected(old('country', $user->country ?? 'PL') === $code)>
                                {{ __($label) }}
                            </option>
                        @endforeach
                    </select>

                    @error('country')
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <flux:input
                name="phone_number"
                :label="__('Phone number')"
                :value="old('phone_number', $user->phone_number)"
                type="text"
                required
                autocomplete="tel"
                :placeholder="__('Phone number')"
            />

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-900/50">
                <label class="flex items-start gap-3">
                    <input
                        type="checkbox"
                        name="wants_company_invoice"
                        value="1"
                        x-model="wantsCompanyInvoice"
                        @checked(old('wants_company_invoice', $user->wants_company_invoice))
                        class="mt-1 rounded border-zinc-300 text-zinc-900 shadow-sm focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-900"
                    >

                    <div>
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                            {{ __('I want invoices issued to a company') }}
                        </span>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('Enable this if you want to store company billing details on your account.') }}
                        </p>
                    </div>
                </label>
            </div>

            <div x-show="wantsCompanyInvoice" x-cloak class="flex flex-col gap-6 rounded-2xl border border-zinc-200 p-6 dark:border-zinc-800">
                <div>
                    <h2 class="text-base font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ __('Company details') }}
                    </h2>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('Provide the company information that should appear on invoices.') }}
                    </p>
                </div>

                <div class="min-h-[4rem]">
                    <flux:input
                        name="company_name"
                        :label="__('Company name')"
                        :value="old('company_name', $user->company_name)"
                        type="text"
                        :placeholder="__('Company name')"
                    />
                </div>

                <div class="min-h-[4rem]">
                    <flux:input
                        name="company_tax_id"
                        :label="__('Tax ID / VAT number')"
                        :value="old('company_tax_id', $user->company_tax_id)"
                        type="text"
                        :placeholder="__('Tax ID / VAT number')"
                    />
                </div>

                <div class="min-h-[4rem]">
                    <flux:input
                        name="company_street"
                        :label="__('Company street')"
                        :value="old('company_street', $user->company_street)"
                        type="text"
                        :placeholder="__('Company street')"
                    />
                </div>

                <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-2">
                    <div class="min-h-[4rem]">
                        <flux:input
                            name="company_house_number"
                            :label="__('Company house number')"
                            :value="old('company_house_number', $user->company_house_number)"
                            type="text"
                            :placeholder="__('Company house number')"
                        />
                    </div>

                    <div class="min-h-[4rem]">
                        <flux:input
                            name="company_apartment_number"
                            :label="__('Company apartment number')"
                            :value="old('company_apartment_number', $user->company_apartment_number)"
                            type="text"
                            :placeholder="__('Company apartment number (optional)')"
                        />
                    </div>
                </div>

                <div class="min-h-[4rem]">
                    <flux:input
                        name="company_city"
                        :label="__('Company city / town / village')"
                        :value="old('company_city', $user->company_city)"
                        type="text"
                        :placeholder="__('Company city / town / village')"
                    />
                </div>

                <div class="grid grid-cols-1 items-start gap-6 md:grid-cols-2">
                    <div class="min-h-[4rem]">
                        <flux:input
                            name="company_postcode"
                            :label="__('Company postcode')"
                            :value="old('company_postcode', $user->company_postcode)"
                            type="text"
                            :placeholder="__('Company postcode')"
                        />
                    </div>

                    <div class="min-h-[4rem] flex flex-col gap-2">
                        <label for="company_country" class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            {{ __('Company country') }}
                        </label>

                        <select
                            id="company_country"
                            name="company_country"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                        >
                            <option value="">{{ __('Select a country') }}</option>

                            @foreach (config('countries') as $code => $label)
                                <option value="{{ $code }}" @selected(old('company_country', $user->company_country ?? 'PL') === $code)>
                                    {{ __($label) }}
                                </option>
                            @endforeach
                        </select>

                        @error('company_country')
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full md:w-auto">
                    {{ __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </div>
@endsection
