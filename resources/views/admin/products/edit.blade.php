@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <a href="{{ route('admin.products.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                ← Back to products
            </a>

            <div class="mt-3 flex items-start justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                        {{ $product->name }}
                    </h1>

                    <p class="mt-2 text-sm text-zinc-600">
                        Edit product details, default picture, prices, and package data for variants.
                    </p>
                </div>

                <a
                    href="{{ route('products.show', $product) }}"
                    class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                    View product
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @php
            $currentDefaultImage = $product->selectedDefaultImage();
            $currentDefaultImageSelection = old('default_image');

            if (! $currentDefaultImageSelection && $currentDefaultImage instanceof \App\Models\ProductImage) {
                $currentDefaultImageSelection = \App\Models\Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE . ':' . $currentDefaultImage->id;
            }

            if (! $currentDefaultImageSelection && $currentDefaultImage instanceof \App\Models\ProductAttributeValueImage) {
                $currentDefaultImageSelection = \App\Models\Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE . ':' . $currentDefaultImage->id;
            }

            $hasSelectableImages = $product->images->isNotEmpty() || $product->attributeValueImages->isNotEmpty();
        @endphp

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Product details</h2>

                <form
                    method="POST"
                    action="{{ route('admin.products.update', $product) }}"
                    class="mt-4 space-y-5"
                >
                    @csrf
                    @method('PATCH')

                    <div>
                        <label for="name" class="mb-2 block text-sm font-medium text-zinc-700">
                            Product name
                        </label>

                        <input
                            id="name"
                            type="text"
                            name="name"
                            value="{{ old('name', $product->name) }}"
                            required
                            maxlength="255"
                            class="@error('name') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >

                        @error('name')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="status" class="mb-2 block text-sm font-medium text-zinc-700">
                            Product status
                        </label>

                        <select
                            id="status"
                            name="status"
                            required
                            class="@error('status') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                            @foreach (\App\Enums\ProductStatus::cases() as $status)
                                <option
                                    value="{{ $status->value }}"
                                    @selected(old('status', $product->status->value) === $status->value)
                                >
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>

                        @error('status')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <dl class="space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Slug</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->slug }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">External source</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->external_source ?: '—' }}</dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">External ID</dt>
                            <dd class="font-medium text-zinc-900">{{ $product->external_id ?: '—' }}</dd>
                        </div>
                    </dl>

                    <div class="flex justify-end border-t border-zinc-200 pt-5">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Save product
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-2">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
                    <div class="w-full lg:w-64">
                        <h2 class="text-lg font-semibold text-zinc-900">Default product picture</h2>

                        <p class="mt-2 text-sm text-zinc-500">
                            This image is used as the product fallback/default image before a customer chooses a variant.
                        </p>

                        <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50">
                            @if ($currentDefaultImage)
                                <img
                                    src="{{ $currentDefaultImage->url }}"
                                    alt="{{ $currentDefaultImage->alt_text ?: $product->name }}"
                                    class="aspect-square w-full object-cover"
                                >
                            @else
                                <div class="flex aspect-square w-full items-center justify-center text-sm text-zinc-500">
                                    No image selected
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="flex-1">
                        <form
                            method="POST"
                            action="{{ route('admin.products.default-image.update', $product) }}"
                            class="space-y-5"
                        >
                            @csrf
                            @method('PATCH')

                            @if ($hasSelectableImages)
                                @if ($product->images->isNotEmpty())
                                    <div>
                                        <h3 class="text-sm font-semibold text-zinc-900">Product images</h3>

                                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                            @foreach ($product->images as $image)
                                                @php
                                                    $selectionValue = \App\Models\Product::DEFAULT_IMAGE_TYPE_PRODUCT_IMAGE . ':' . $image->id;
                                                    $isSelected = $currentDefaultImageSelection === $selectionValue;
                                                @endphp

                                                <label
                                                    for="default-product-image-{{ $image->id }}"
                                                    class="block cursor-pointer overflow-hidden rounded-xl border bg-white transition hover:border-zinc-400 {{ $isSelected ? 'border-zinc-900 ring-2 ring-zinc-900/10' : 'border-zinc-200' }}"
                                                >
                                                    <input
                                                        id="default-product-image-{{ $image->id }}"
                                                        type="radio"
                                                        name="default_image"
                                                        value="{{ $selectionValue }}"
                                                        class="sr-only"
                                                        @checked($isSelected)
                                                    >

                                                    <img
                                                        src="{{ $image->url }}"
                                                        alt="{{ $image->alt_text ?: $product->name }}"
                                                        class="aspect-square w-full object-cover"
                                                    >

                                                    <span class="block truncate px-3 py-2 text-xs font-medium text-zinc-700">
                                                        {{ $image->title ?: $image->alt_text ?: 'Product image #' . $image->id }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @if ($product->attributeValueImages->isNotEmpty())
                                    <div>
                                        <h3 class="text-sm font-semibold text-zinc-900">Variant images</h3>

                                        <div class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                            @foreach ($product->attributeValueImages as $image)
                                                @php
                                                    $selectionValue = \App\Models\Product::DEFAULT_IMAGE_TYPE_ATTRIBUTE_VALUE_IMAGE . ':' . $image->id;
                                                    $isSelected = $currentDefaultImageSelection === $selectionValue;
                                                    $attributeValue = $image->attributeValue;
                                                    $variantImageLabel = $attributeValue?->attribute?->name && $attributeValue?->value
                                                        ? $attributeValue->attribute->name . ': ' . $attributeValue->value
                                                        : 'Variant image #' . $image->id;
                                                @endphp

                                                <label
                                                    for="default-attribute-value-image-{{ $image->id }}"
                                                    class="block cursor-pointer overflow-hidden rounded-xl border bg-white transition hover:border-zinc-400 {{ $isSelected ? 'border-zinc-900 ring-2 ring-zinc-900/10' : 'border-zinc-200' }}"
                                                >
                                                    <input
                                                        id="default-attribute-value-image-{{ $image->id }}"
                                                        type="radio"
                                                        name="default_image"
                                                        value="{{ $selectionValue }}"
                                                        class="sr-only"
                                                        @checked($isSelected)
                                                    >

                                                    <img
                                                        src="{{ $image->url }}"
                                                        alt="{{ $image->alt_text ?: $variantImageLabel }}"
                                                        class="aspect-square w-full object-cover"
                                                    >

                                                    <span class="block truncate px-3 py-2 text-xs font-medium text-zinc-700">
                                                        {{ $variantImageLabel }}
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                @error('default_image')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                                @enderror

                                <div class="flex justify-end border-t border-zinc-200 pt-5">
                                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                        Save default picture
                                    </button>
                                </div>
                            @else
                                <div class="rounded-xl border border-dashed border-zinc-300 bg-zinc-50 p-6 text-sm text-zinc-500">
                                    This product does not have any product or variant images yet.
                                </div>
                            @endif
                        </form>
                    </div>
                </div>
            </div>


            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-3">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Apply price to all variants
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Use this when all variants of this product should use the same customer-facing gross price, VAT rate, and currency.
                    The net price is calculated and stored automatically.
                </p>

                <form
                    method="POST"
                    action="{{ route('admin.products.prices.update', $product) }}"
                    class="mt-5 space-y-5"
                >
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <div>
                            <label for="gross_price" class="mb-2 block text-sm font-medium text-zinc-700">
                                Gross price
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="gross_price"
                                    type="number"
                                    name="gross_price"
                                    min="0.01"
                                    max="999999.99"
                                    step="0.01"
                                    inputmode="decimal"
                                    value="{{ old('gross_price') }}"
                                    required
                                    class="@error('gross_price') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    gross
                                </span>
                            </div>

                            @error('gross_price')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="vat_rate" class="mb-2 block text-sm font-medium text-zinc-700">
                                VAT rate
                            </label>

                            <select
                                id="vat_rate"
                                name="vat_rate"
                                required
                                class="@error('vat_rate') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                            >
                                @foreach (\App\Enums\VatRate::cases() as $vatRate)
                                    <option
                                        value="{{ $vatRate->value }}"
                                        @selected((int) old('vat_rate', \App\Enums\VatRate::VAT_23->value) === $vatRate->value)
                                    >
                                        {{ $vatRate->label() }}
                                    </option>
                                @endforeach
                            </select>

                            @error('vat_rate')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="currency" class="mb-2 block text-sm font-medium text-zinc-700">
                                Currency
                            </label>

                            <select
                                id="currency"
                                name="currency"
                                required
                                class="@error('currency') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                            >
                                @foreach (\App\Enums\Currency::cases() as $currency)
                                    <option
                                        value="{{ $currency->value }}"
                                        @selected(old('currency', \App\Enums\Currency::default()->value) === $currency->value)
                                    >
                                        {{ $currency->label() }}
                                    </option>
                                @endforeach
                            </select>

                            @error('currency')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Apply price to all variants
                        </button>
                    </div>
                </form>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-3">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Apply package data to all variants
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Use this when all variants of this product have the same shipping package.
                </p>

                <form
                    method="POST"
                    action="{{ route('admin.products.package-dimensions.update', $product) }}"
                    class="mt-5 space-y-5"
                >
                    @csrf
                    @method('PATCH')

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-4">
                        <div>
                            <label for="package_weight_grams" class="mb-2 block text-sm font-medium text-zinc-700">
                                Weight
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_weight_grams"
                                    type="number"
                                    name="package_weight_grams"
                                    min="1"
                                    max="100000"
                                    class="@error('package_weight_grams') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    g
                                </span>
                            </div>

                            @error('package_weight_grams')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="package_length_mm" class="mb-2 block text-sm font-medium text-zinc-700">
                                Length
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_length_mm"
                                    type="number"
                                    name="package_length_mm"
                                    min="1"
                                    max="5000"
                                    class="@error('package_length_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    mm
                                </span>
                            </div>

                            @error('package_length_mm')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="package_width_mm" class="mb-2 block text-sm font-medium text-zinc-700">
                                Width
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_width_mm"
                                    type="number"
                                    name="package_width_mm"
                                    min="1"
                                    max="5000"
                                    class="@error('package_width_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    mm
                                </span>
                            </div>

                            @error('package_width_mm')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="package_height_mm" class="mb-2 block text-sm font-medium text-zinc-700">
                                Height
                            </label>

                            <div class="flex rounded-xl shadow-sm">
                                <input
                                    id="package_height_mm"
                                    type="number"
                                    name="package_height_mm"
                                    min="1"
                                    max="5000"
                                    class="@error('package_height_mm') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-l-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                >

                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-zinc-300 bg-zinc-50 px-3 text-sm text-zinc-500">
                                    mm
                                </span>
                            </div>

                            @error('package_height_mm')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                            Apply to all variants
                        </button>
                    </div>
                </form>
            </div>
        </div>


        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Variant price data
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Save individual gross prices when variants have different prices. Net prices are calculated from the selected VAT rate.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('admin.products.variants.prices.update', $product) }}"
            >
                @csrf
                @method('PATCH')

                <div class="overflow-hidden rounded-xl border border-zinc-200">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Variant</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Current net</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Gross price</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">VAT rate</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Currency</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 bg-white">
                        @forelse ($product->variants as $variant)
                            @php
                                $variantName = $variant->attributeValues
                                    ->pluck('value')
                                    ->filter()
                                    ->implode(' / ');

                                $grossPriceAmount = $variant->grossPriceAmount();
                                $grossPriceValue = $grossPriceAmount === null
                                    ? ''
                                    : number_format($grossPriceAmount / 100, 2, '.', '');

                                $netPriceValue = $variant->price_net_amount === null
                                    ? '—'
                                    : number_format($variant->price_net_amount / 100, 2, ',', ' ') . ' ' . ($variant->currency?->value ?? '');
                            @endphp

                            <tr>
                                <td class="px-4 py-4 text-sm">
                                    <div class="font-medium text-zinc-900">
                                        {{ $variant->sku ?: 'Variant #' . $variant->id }}
                                    </div>

                                    @if ($variantName)
                                        <div class="mt-1 text-xs text-zinc-500">
                                            {{ $variantName }}
                                        </div>
                                    @endif

                                    <div class="mt-1 text-xs text-zinc-400">
                                        ID: {{ $variant->id }}
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700">
                                    {{ $variant->status->label() }}
                                </td>

                                <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                    {{ $netPriceValue }}
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="0.01"
                                        max="999999.99"
                                        step="0.01"
                                        inputmode="decimal"
                                        required
                                        name="variants[{{ $variant->id }}][gross_price]"
                                        value="{{ old("variants.{$variant->id}.gross_price", $grossPriceValue) }}"
                                        class="@error("variants.{$variant->id}.gross_price") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-32 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-sm">
                                    <select
                                        name="variants[{{ $variant->id }}][vat_rate]"
                                        required
                                        class="@error("variants.{$variant->id}.vat_rate") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-32 rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\VatRate::cases() as $vatRate)
                                            <option
                                                value="{{ $vatRate->value }}"
                                                @selected((int) old("variants.{$variant->id}.vat_rate", $variant->vat_rate?->value ?? \App\Enums\VatRate::VAT_23->value) === $vatRate->value)
                                            >
                                                {{ $vatRate->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>

                                <td class="px-4 py-4 text-sm">
                                    <select
                                        name="variants[{{ $variant->id }}][currency]"
                                        required
                                        class="@error("variants.{$variant->id}.currency") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                        @foreach (\App\Enums\Currency::cases() as $currency)
                                            <option
                                                value="{{ $currency->value }}"
                                                @selected(old("variants.{$variant->id}.currency", $variant->currency?->value ?? \App\Enums\Currency::default()->value) === $currency->value)
                                            >
                                                {{ $currency->label() }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    This product has no variants.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($errors->has('variants'))
                    <p class="mt-2 text-sm text-red-600">{{ $errors->first('variants') }}</p>
                @endif

                <div class="mt-5 flex justify-end">
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Save variant prices
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-4">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Variant package data
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    Save individual package data when variants differ in size or weight.
                </p>
            </div>

            <form
                method="POST"
                action="{{ route('admin.products.variants.package-dimensions.update', $product) }}"
            >
                @csrf
                @method('PATCH')

                <div class="overflow-hidden rounded-xl border border-zinc-200">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Variant</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Package status</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Weight g</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Length mm</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Width mm</th>
                            <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Height mm</th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 bg-white">
                        @forelse ($product->variants as $variant)
                            @php
                                $hasCompletePackage = $variant->package_weight_grams
                                    && $variant->package_length_mm
                                    && $variant->package_width_mm
                                    && $variant->package_height_mm;

                                $variantName = $variant->attributeValues
                                    ->pluck('value')
                                    ->filter()
                                    ->implode(' / ');
                            @endphp

                            <tr>
                                <td class="px-4 py-4 text-sm">
                                    <div class="font-medium text-zinc-900">
                                        {{ $variant->sku ?: 'Variant #' . $variant->id }}
                                    </div>

                                    @if ($variantName)
                                        <div class="mt-1 text-xs text-zinc-500">
                                            {{ $variantName }}
                                        </div>
                                    @endif

                                    <div class="mt-1 text-xs text-zinc-400">
                                        ID: {{ $variant->id }}
                                    </div>
                                </td>

                                <td class="px-4 py-4 text-sm text-zinc-700">
                                    {{ $variant->status->label() }}
                                </td>

                                <td class="px-4 py-4 text-sm">
                                    @if ($hasCompletePackage)
                                        <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                            Complete
                                        </span>
                                    @else
                                        <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                            Missing weight/dimensions
                                        </span>
                                    @endif
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="100000"
                                        name="variants[{{ $variant->id }}][package_weight_grams]"
                                        value="{{ old("variants.{$variant->id}.package_weight_grams", $variant->package_weight_grams) }}"
                                        class="@error("variants.{$variant->id}.package_weight_grams") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="5000"
                                        name="variants[{{ $variant->id }}][package_length_mm]"
                                        value="{{ old("variants.{$variant->id}.package_length_mm", $variant->package_length_mm) }}"
                                        class="@error("variants.{$variant->id}.package_length_mm") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="5000"
                                        name="variants[{{ $variant->id }}][package_width_mm]"
                                        value="{{ old("variants.{$variant->id}.package_width_mm", $variant->package_width_mm) }}"
                                        class="@error("variants.{$variant->id}.package_width_mm") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>

                                <td class="px-4 py-4 text-right text-sm">
                                    <input
                                        type="number"
                                        min="1"
                                        max="5000"
                                        name="variants[{{ $variant->id }}][package_height_mm]"
                                        value="{{ old("variants.{$variant->id}.package_height_mm", $variant->package_height_mm) }}"
                                        class="@error("variants.{$variant->id}.package_height_mm") border-red-300 ring-red-100 @else border-zinc-300 @enderror w-28 rounded-xl border bg-white px-3 py-2 text-right text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                                    >
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-500">
                                    This product has no variants.
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($errors->has('variants'))
                    <p class="mt-2 text-sm text-red-600">{{ $errors->first('variants') }}</p>
                @endif

                <div class="mt-5 flex justify-end">
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Save variant package data
                    </button>
                </div>
            </form>
        </div>
    </div>
@endsection
