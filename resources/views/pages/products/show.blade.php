@extends('layouts.storefront')

@section('content')
    @php
        $displayImage = $product->selectedDefaultImage();
        $displayImageUrl = $displayImage?->url;
        $displayImageAlt = $displayImage?->alt_text ?: $product->name;
        $grossPriceAmount = $defaultVariant?->grossPriceAmount();
        $currency = $defaultVariant?->currency?->value ?? 'PLN';
        $stockStatus = $defaultVariant?->stock_status?->value;
    @endphp

    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @if (session('success'))
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ $errors->first() }}
            </div>
        @endif

        <div
            id="product-configurator"
            data-product='@json($productPayload)'
        >
            <div class="grid grid-cols-1 gap-10 lg:grid-cols-2">
                <section>
                    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                        @if ($displayImageUrl)
                            <img
                                src="{{ $displayImageUrl }}"
                                alt="{{ $displayImageAlt }}"
                                class="h-auto w-full object-cover"
                                fetchpriority="high"
                            >
                        @else
                            <div class="flex aspect-[4/5] items-center justify-center bg-zinc-100 text-sm text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                No image available
                            </div>
                        @endif
                    </div>
                </section>

                <section>
                    <div class="space-y-6">
                        <div>
                            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">
                                {{ $product->name }}
                            </h1>

                            @if (filled($product->short_description))
                                <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
                                    {{ strip_tags($product->short_description) }}
                                </p>
                            @endif
                        </div>

                        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                            @if ($grossPriceAmount !== null)
                                <p class="text-3xl font-semibold text-zinc-900 dark:text-white">
                                    {{ number_format($grossPriceAmount / 100, 2, ',', ' ') }} {{ $currency }}
                                </p>
                            @else
                                <p class="text-base text-zinc-500 dark:text-zinc-400">
                                    Price unavailable
                                </p>
                            @endif

                            @if ($stockStatus)
                                <p class="mt-3 inline-flex rounded-full bg-zinc-100 px-3 py-1 text-sm font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ ucfirst(str_replace('_', ' ', $stockStatus)) }}
                                </p>
                            @endif

                            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                                Enable JavaScript to choose variants and add this product to your cart.
                            </p>
                        </div>

                        @if ($product->description)
                            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
                                <div class="product-description overflow-x-auto">
                                    {!! $product->description !!}
                                </div>
                            </div>
                        @endif
                    </div>
                </section>
            </div>
        </div>
    </div>
@endsection
