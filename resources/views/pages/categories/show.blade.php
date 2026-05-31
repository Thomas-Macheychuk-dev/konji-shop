@extends('layouts.storefront')

@section('content')
    <div class="space-y-8">
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-8">
            <p class="text-sm font-medium uppercase tracking-wide text-zinc-500">
                Category
            </p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">
                {{ $category->name }}
            </h1>

            @if (filled($category->description))
                <p class="mt-4 max-w-3xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                    {{ $category->description }}
                </p>
            @endif

            <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }} found.
            </p>
        </section>

        @if ($products->count() > 0)
            <section>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($products as $product)
                        @php
                            $displayVariant = $product->variants->firstWhere('is_default', true)
                                ?? $product->variants->first();

                            $grossPriceAmount = $displayVariant?->grossPriceAmount();
                            $currency = $displayVariant?->currency?->value ?? 'PLN';
                            $imageUrl = $product->default_image_url;
                        @endphp

                        <a
                            href="{{ route('products.show', $product->slug) }}"
                            class="group flex h-full flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900"
                        >
                            <div class="aspect-square bg-zinc-100 dark:bg-zinc-800">
                                @if ($imageUrl)
                                    <img
                                        src="{{ $imageUrl }}"
                                        alt="{{ $product->name }}"
                                        class="h-full w-full object-cover transition duration-300 group-hover:scale-105"
                                        loading="lazy"
                                    >
                                @else
                                    <div class="flex h-full items-center justify-center px-6 text-center text-sm text-zinc-400">
                                        No image available
                                    </div>
                                @endif
                            </div>

                            <div class="flex flex-1 flex-col p-4">
                                <h2 class="text-base font-semibold text-zinc-900 transition group-hover:text-zinc-700 dark:text-white dark:group-hover:text-zinc-200">
                                    {{ $product->name }}
                                </h2>

                                @if (filled($product->short_description))
                                    <p class="mt-2 line-clamp-2 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ strip_tags($product->short_description) }}
                                    </p>
                                @endif

                                <div class="mt-auto pt-4">
                                    @if ($grossPriceAmount !== null)
                                        <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                                            {{ number_format($grossPriceAmount / 100, 2, ',', ' ') }} {{ $currency }}
                                        </p>
                                    @else
                                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                            Price unavailable
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>

                <div class="mt-8">
                    {{ $products->links() }}
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50 p-8 text-center text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                No active products are available in this category yet.
            </section>
        @endif
    </div>
@endsection
