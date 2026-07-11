@extends('layouts.storefront')

@section('content')
    <x-storefront.category-page-shell>
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-[1480px] px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
            <nav class="flex flex-wrap items-center gap-2 text-xs font-medium text-slate-400" aria-label="Okruszki">
                <a href="{{ route('home') }}" class="transition hover:text-[#155fa8]">Strona główna</a>
                <span aria-hidden="true">/</span>
                <span class="text-slate-600">{{ $category->name }}</span>
            </nav>

            <div class="mt-6 grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#1674c4]">Kategoria produktów</p>
                    <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl lg:text-5xl">
                        {{ $category->name }}
                    </h1>

                    @if (filled($category->description))
                        <p class="mt-4 max-w-3xl text-sm leading-7 text-slate-600 sm:text-base">
                            {{ strip_tags($category->description) }}
                        </p>
                    @endif
                </div>

                <div class="inline-flex w-fit items-center gap-3 rounded-2xl border border-blue-100 bg-blue-50 px-4 py-3">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-white text-[#155fa8] shadow-sm">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h10" />
                        </svg>
                    </span>
                    <span>
                        <strong class="block text-sm font-bold text-slate-900">{{ $products->total() }} {{ $products->total() === 1 ? 'produkt' : 'produktów' }}</strong>
                        <span class="block text-xs text-slate-500">w tej kategorii</span>
                    </span>
                    <span class="sr-only">{{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }} found.</span>
                </div>
            </div>

            @if ($category->children->isNotEmpty())
                <div class="mt-8 flex flex-wrap gap-2.5">
                    @foreach ($category->children as $childCategory)
                        <a
                            href="{{ route('categories.show', $childCategory->slug) }}"
                            class="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:bg-blue-50 hover:text-[#155fa8]"
                        >
                            {{ $childCategory->name }}
                            <span class="text-slate-300" aria-hidden="true">→</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section>
        <div class="mx-auto max-w-[1480px] px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
            @if ($products->count() > 0)
                <div class="mb-7 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h2 class="text-2xl font-extrabold tracking-tight text-slate-950">Dostępne produkty</h2>
                        <p class="mt-1 text-sm text-slate-500">Wybierz produkt, aby sprawdzić warianty, rozmiary i dostępność.</p>
                    </div>

                    <a href="{{ route('legal.contact') }}" class="inline-flex w-fit items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-[#155fa8]">
                        Potrzebujesz pomocy?
                        <span aria-hidden="true">→</span>
                    </a>
                </div>

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($products as $product)
                        <x-storefront.product-card :product="$product" />
                    @endforeach
                </div>

                <div class="mt-10 rounded-2xl bg-white p-3 shadow-sm ring-1 ring-slate-200">
                    {{ $products->links() }}
                </div>
            @else
                <div class="rounded-[28px] border border-blue-100 bg-gradient-to-r from-blue-50 to-white px-6 py-12 text-center sm:px-10">
                    <span class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-white text-[#155fa8] shadow-sm ring-1 ring-blue-100">
                        <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h10" />
                        </svg>
                    </span>
                    <h2 class="mt-5 text-2xl font-bold text-slate-900">Produkty pojawią się wkrótce</h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-600">
                        W tej kategorii nie ma jeszcze aktywnych produktów. Skontaktuj się z nami, gdy szukasz konkretnego rozwiązania.
                    </p>
                    <a href="{{ route('legal.contact') }}" class="mt-6 inline-flex rounded-xl bg-[#155fa8] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#0b3b70]">
                        Skontaktuj się z obsługą
                    </a>
                </div>
            @endif
        </div>
    </section>
    </x-storefront.category-page-shell>
@endsection
