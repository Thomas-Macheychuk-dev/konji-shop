@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-zinc-900">Checkout</h1>
            <p class="mt-2 text-sm text-zinc-600">
                Enter your details and review your order before placing it.
            </p>
        </div>

        @if (session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @php
            $customerEmail = old('email', auth()->user()?->email ?? '');
        @endphp

        <form method="POST" action="{{ route('checkout.place') }}">
            @csrf

            <div class="grid grid-cols-1 gap-8 lg:grid-cols-[1fr_380px]">
                <section class="space-y-6">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Contact details</h2>

                        <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="email" class="mb-2 block text-sm font-medium text-zinc-700">
                                    E-mail
                                </label>
                                <input
                                    id="email"
                                    type="email"
                                    name="email"
                                    value="{{ $customerEmail }}"
                                    autocomplete="email"
                                    class="@error('email') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('email')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="phone" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Phone
                                </label>
                                <input
                                    id="phone"
                                    type="text"
                                    name="phone"
                                    value="{{ old('phone') }}"
                                    autocomplete="tel"
                                    class="@error('phone') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('phone')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Shipping address</h2>

                        <div class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="shipping_first_name" class="mb-2 block text-sm font-medium text-zinc-700">
                                    First name
                                </label>
                                <input
                                    id="shipping_first_name"
                                    type="text"
                                    name="shipping_first_name"
                                    value="{{ old('shipping_first_name') }}"
                                    autocomplete="given-name"
                                    class="@error('shipping_first_name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_first_name')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="shipping_last_name" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Last name
                                </label>
                                <input
                                    id="shipping_last_name"
                                    type="text"
                                    name="shipping_last_name"
                                    value="{{ old('shipping_last_name') }}"
                                    autocomplete="family-name"
                                    class="@error('shipping_last_name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_last_name')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="shipping_company" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Company
                                    <span class="text-zinc-400">(optional)</span>
                                </label>
                                <input
                                    id="shipping_company"
                                    type="text"
                                    name="shipping_company"
                                    value="{{ old('shipping_company') }}"
                                    autocomplete="organization"
                                    class="@error('shipping_company') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_company')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="shipping_address_line_1" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Address line 1
                                </label>
                                <input
                                    id="shipping_address_line_1"
                                    type="text"
                                    name="shipping_address_line_1"
                                    value="{{ old('shipping_address_line_1') }}"
                                    autocomplete="address-line1"
                                    class="@error('shipping_address_line_1') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_address_line_1')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="shipping_address_line_2" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Address line 2
                                    <span class="text-zinc-400">(optional)</span>
                                </label>
                                <input
                                    id="shipping_address_line_2"
                                    type="text"
                                    name="shipping_address_line_2"
                                    value="{{ old('shipping_address_line_2') }}"
                                    autocomplete="address-line2"
                                    class="@error('shipping_address_line_2') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_address_line_2')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="shipping_city" class="mb-2 block text-sm font-medium text-zinc-700">
                                    City
                                </label>
                                <input
                                    id="shipping_city"
                                    type="text"
                                    name="shipping_city"
                                    value="{{ old('shipping_city') }}"
                                    autocomplete="address-level2"
                                    class="@error('shipping_city') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_city')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="shipping_postcode" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Postcode
                                </label>
                                <input
                                    id="shipping_postcode"
                                    type="text"
                                    name="shipping_postcode"
                                    value="{{ old('shipping_postcode') }}"
                                    autocomplete="postal-code"
                                    class="@error('shipping_postcode') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_postcode')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="shipping_country_code" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Country code
                                </label>
                                <input
                                    id="shipping_country_code"
                                    type="text"
                                    name="shipping_country_code"
                                    value="{{ old('shipping_country_code', 'PL') }}"
                                    maxlength="2"
                                    autocomplete="country"
                                    class="@error('shipping_country_code') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 uppercase text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('shipping_country_code')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div
                        class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm"
                        x-data="{ billingSame: {{ old('billing_same_as_shipping', '1') ? 'true' : 'false' }} }"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <h2 class="text-lg font-semibold text-zinc-900">Billing address</h2>

                            <label class="inline-flex items-center gap-3 text-sm text-zinc-700">
                                <input
                                    type="hidden"
                                    name="billing_same_as_shipping"
                                    :value="billingSame ? 1 : 0"
                                >

                                <input
                                    type="checkbox"
                                    x-model="billingSame"
                                    class="h-4 w-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900"
                                >

                                <span>Same as shipping</span>
                            </label>
                        </div>

                        <div x-show="!billingSame" x-cloak class="mt-5 grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div>
                                <label for="billing_first_name" class="mb-2 block text-sm font-medium text-zinc-700">
                                    First name
                                </label>
                                <input
                                    id="billing_first_name"
                                    type="text"
                                    name="billing_first_name"
                                    value="{{ old('billing_first_name') }}"
                                    autocomplete="given-name"
                                    class="@error('billing_first_name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_first_name')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="billing_last_name" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Last name
                                </label>
                                <input
                                    id="billing_last_name"
                                    type="text"
                                    name="billing_last_name"
                                    value="{{ old('billing_last_name') }}"
                                    autocomplete="family-name"
                                    class="@error('billing_last_name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_last_name')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="billing_company" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Company
                                    <span class="text-zinc-400">(optional)</span>
                                </label>
                                <input
                                    id="billing_company"
                                    type="text"
                                    name="billing_company"
                                    value="{{ old('billing_company') }}"
                                    autocomplete="organization"
                                    class="@error('billing_company') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_company')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="billing_address_line_1" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Address line 1
                                </label>
                                <input
                                    id="billing_address_line_1"
                                    type="text"
                                    name="billing_address_line_1"
                                    value="{{ old('billing_address_line_1') }}"
                                    autocomplete="address-line1"
                                    class="@error('billing_address_line_1') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_address_line_1')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="billing_address_line_2" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Address line 2
                                    <span class="text-zinc-400">(optional)</span>
                                </label>
                                <input
                                    id="billing_address_line_2"
                                    type="text"
                                    name="billing_address_line_2"
                                    value="{{ old('billing_address_line_2') }}"
                                    autocomplete="address-line2"
                                    class="@error('billing_address_line_2') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_address_line_2')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="billing_city" class="mb-2 block text-sm font-medium text-zinc-700">
                                    City
                                </label>
                                <input
                                    id="billing_city"
                                    type="text"
                                    name="billing_city"
                                    value="{{ old('billing_city') }}"
                                    autocomplete="address-level2"
                                    class="@error('billing_city') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_city')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="billing_postcode" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Postcode
                                </label>
                                <input
                                    id="billing_postcode"
                                    type="text"
                                    name="billing_postcode"
                                    value="{{ old('billing_postcode') }}"
                                    autocomplete="postal-code"
                                    class="@error('billing_postcode') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_postcode')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="sm:col-span-2">
                                <label for="billing_country_code" class="mb-2 block text-sm font-medium text-zinc-700">
                                    Country code
                                </label>
                                <input
                                    id="billing_country_code"
                                    type="text"
                                    name="billing_country_code"
                                    value="{{ old('billing_country_code', 'PL') }}"
                                    maxlength="2"
                                    autocomplete="country"
                                    class="@error('billing_country_code') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 uppercase text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >
                                @error('billing_country_code')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Order notes</h2>

                        <div class="mt-5">
                            <label for="notes" class="mb-2 block text-sm font-medium text-zinc-700">
                                Notes
                                <span class="text-zinc-400">(optional)</span>
                            </label>
                            <textarea
                                id="notes"
                                name="notes"
                                rows="4"
                                class="@error('notes') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                            >{{ old('notes') }}</textarea>
                            @error('notes')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </section>

                <aside>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Order summary</h2>

                        <div class="mt-5 space-y-4">
                            @foreach ($cart->items as $item)
                                @php
                                    $product = $item->product;
                                    $variant = $item->variant;
                                    $imageUrl = $item->meta['image_url'] ?? $product?->mainImage?->url;
                                    $unitPrice = $item->currentUnitPriceAmount();
                                    $lineTotal = $item->currentLineTotalAmount();
                                    $variantName =
                                        $variant && $variant->relationLoaded('attributeValues') && $variant->attributeValues->isNotEmpty()
                                            ? $variant->attributeValues->pluck('value')->filter()->implode(' / ')
                                            : ($item->meta['variant_name'] ?? null);
                                @endphp

                                <div class="flex gap-3 border-b border-zinc-100 pb-4 last:border-b-0 last:pb-0">
                                    <div class="h-16 w-16 shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50">
                                        @if ($imageUrl)
                                            <img
                                                src="{{ $imageUrl }}"
                                                alt="{{ $product?->name ?? 'Product image' }}"
                                                class="h-full w-full object-cover"
                                            >
                                        @endif
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-zinc-900">
                                            {{ $product?->name ?? 'Product' }}
                                        </p>

                                        @if ($variantName)
                                            <p class="mt-1 text-xs text-zinc-500">
                                                {{ $variantName }}
                                            </p>
                                        @endif

                                        <p class="mt-1 text-xs text-zinc-500">
                                            Quantity: {{ $item->quantity }}
                                        </p>
                                    </div>

                                    <div class="text-right">
                                        @if ($lineTotal !== null)
                                            <p class="text-sm font-medium text-zinc-900">
                                                {{ number_format($lineTotal / 100, 2, ',', ' ') }} {{ $variant?->currency?->value ?? $cart->currency }}
                                            </p>
                                            <p class="mt-1 text-xs text-zinc-500">
                                                {{ number_format(($unitPrice ?? 0) / 100, 2, ',', ' ') }} each
                                            </p>
                                        @else
                                            <p class="text-sm font-medium text-red-600">
                                                Price unavailable
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <dl class="mt-6 space-y-3 text-sm text-zinc-700">
                            <div class="flex items-center justify-between">
                                <dt>Items</dt>
                                <dd>{{ $cart->items->sum('quantity') }}</dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt>Subtotal</dt>
                                <dd class="font-medium text-zinc-900">
                                    {{ number_format($subtotal / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                </dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt>Shipping</dt>
                                <dd class="font-medium text-zinc-900">
                                    {{ number_format($shipping / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                </dd>
                            </div>

                            @if ($discount > 0)
                                <div class="flex items-center justify-between">
                                    <dt>Discount</dt>
                                    <dd class="font-medium text-zinc-900">
                                        -{{ number_format($discount / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                    </dd>
                                </div>
                            @endif

                            <div class="border-t border-zinc-200 pt-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-base font-semibold text-zinc-900">Total</dt>
                                    <dd class="text-base font-semibold text-zinc-900">
                                        {{ number_format($total / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                    </dd>
                                </div>
                            </div>
                        </dl>

                        <div class="mt-6">
                            <label class="inline-flex items-start gap-3 text-sm text-zinc-700">
                                <input
                                    type="checkbox"
                                    name="terms_accepted"
                                    value="1"
                                    {{ old('terms_accepted') ? 'checked' : '' }}
                                    class="mt-1 h-4 w-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900"
                                >
                                <span>
                                    I accept the terms and conditions and confirm that the entered order details are correct.
                                </span>
                            </label>
                            @error('terms_accepted')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="mt-6 space-y-3">
                            <button
                                type="submit"
                                class="inline-flex w-full items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
                            >
                                Place order
                            </button>

                            <a
                                href="{{ route('cart.show') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                            >
                                Back to cart
                            </a>
                        </div>

                        <p class="mt-4 text-xs text-zinc-500">
                            Prices shown here reflect current product variant prices at the time of checkout.
                        </p>
                    </div>
                </aside>
            </div>
        </form>
    </div>
@endsection
