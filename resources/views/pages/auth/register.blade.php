<x-layouts::auth :title="__('Register')">
    <div class="flex flex-col gap-6" x-data="{ wantsCompanyInvoice: @js(old('wants_company_invoice', false)) }">
        <x-auth-header :title="__('Create an account')" :description="__('Enter your details below to create your account')" />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-6">
            @csrf

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <flux:input
                    name="first_name"
                    :label="__('First name')"
                    :value="old('first_name')"
                    type="text"
                    required
                    autofocus
                    autocomplete="given-name"
                    :placeholder="__('First name')"
                />

                <flux:input
                    name="last_name"
                    :label="__('Last name')"
                    :value="old('last_name')"
                    type="text"
                    required
                    autocomplete="family-name"
                    :placeholder="__('Last name')"
                />
            </div>

            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autocomplete="email"
                placeholder="email@example.com"
            />

            <flux:input
                name="street"
                :label="__('Street')"
                :value="old('street')"
                type="text"
                required
                autocomplete="address-line1"
                :placeholder="__('Street')"
            />

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <flux:input
                    name="house_number"
                    :label="__('House number')"
                    :value="old('house_number')"
                    type="text"
                    required
                    autocomplete="address-line2"
                    :placeholder="__('House number')"
                />

                <flux:input
                    name="apartment_number"
                    :label="__('Apartment number')"
                    :value="old('apartment_number')"
                    type="text"
                    autocomplete="address-line2"
                    :placeholder="__('Apartment number (optional)')"
                />
            </div>

            <flux:input
                name="city"
                :label="__('City / Town / Village')"
                :value="old('city')"
                type="text"
                required
                autocomplete="address-level2"
                :placeholder="__('City / Town / Village')"
            />

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <flux:input
                    name="postcode"
                    :label="__('Postcode')"
                    :value="old('postcode')"
                    type="text"
                    required
                    autocomplete="postal-code"
                    :placeholder="__('Postcode')"
                />

                <div class="flex flex-col gap-2">
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
                            <option value="{{ $code }}" @selected(old('country', 'PL') === $code)>
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
                :value="old('phone_number')"
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
                        @checked(old('wants_company_invoice'))
                        class="mt-1 rounded border-zinc-300 text-zinc-900 shadow-sm focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-900"
                    >

                    <div>
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                            {{ __('I want invoices issued to a company') }}
                        </span>
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('Enable this if you want to provide company billing details during registration.') }}
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

                <flux:input
                    name="company_name"
                    :label="__('Company name')"
                    :value="old('company_name')"
                    type="text"
                    ::required="wantsCompanyInvoice"
                    :placeholder="__('Company name')"
                />

                <flux:input
                    name="company_tax_id"
                    :label="__('Tax ID / VAT number')"
                    :value="old('company_tax_id')"
                    type="text"
                    :placeholder="__('Tax ID / VAT number')"
                />

                <flux:input
                    name="company_street"
                    :label="__('Company street')"
                    :value="old('company_street')"
                    type="text"
                    :placeholder="__('Company street')"
                />

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <flux:input
                        name="company_house_number"
                        :label="__('Company house number')"
                        :value="old('company_house_number')"
                        type="text"
                        :placeholder="__('Company house number')"
                    />

                    <flux:input
                        name="company_apartment_number"
                        :label="__('Company apartment number')"
                        :value="old('company_apartment_number')"
                        type="text"
                        :placeholder="__('Company apartment number (optional)')"
                    />
                </div>

                <flux:input
                    name="company_city"
                    :label="__('Company city / town / village')"
                    :value="old('company_city')"
                    type="text"
                    :placeholder="__('Company city / town / village')"
                />

                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <flux:input
                        name="company_postcode"
                        :label="__('Company postcode')"
                        :value="old('company_postcode')"
                        type="text"
                        :placeholder="__('Company postcode')"
                    />

                    <div class="flex flex-col gap-2">
                        <label for="company_country" class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            {{ __('Company country') }}
                        </label>

                        <select
                            id="company_country"
                            name="company_country"
                            autocomplete="country-name"
                            class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
                        >
                            <option value="">{{ __('Select a country') }}</option>

                            @foreach (config('countries') as $code => $label)
                                <option value="{{ $code }}" @selected(old('company_country', 'PL') === $code)>
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

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:input
                name="password_confirmation"
                :label="__('Confirm password')"
                type="password"
                required
                autocomplete="new-password"
                :placeholder="__('Confirm password')"
                viewable
            />

            <div class="flex items-center justify-end">
                <flux:button type="submit" variant="primary" class="w-full" data-test="register-user-button">
                    {{ __('Create account') }}
                </flux:button>
            </div>
        </form>

        <div class="space-x-1 rtl:space-x-reverse text-center text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __('Already have an account?') }}</span>
            <flux:link :href="route('login')" wire:navigate>{{ __('Log in') }}</flux:link>
        </div>
    </div>
</x-layouts::auth>
