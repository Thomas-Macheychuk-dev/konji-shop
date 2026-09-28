@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
        <nav class="mb-6 text-sm" aria-label="Breadcrumb">
            <ol class="flex flex-wrap items-center gap-2 text-zinc-500">
                <li>
                    <a href="{{ route('home') }}" class="font-medium transition hover:text-zinc-900">
                        Strona główna
                    </a>
                </li>
                <li aria-hidden="true" class="text-zinc-300">/</li>
                <li>
                    <a
                        href="{{ $canonicalUrl }}"
                        aria-current="page"
                        class="font-medium text-zinc-900"
                    >
                        {{ $placeholder['name'] }}
                    </a>
                </li>
            </ol>
        </nav>

        <article class="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-[0_12px_45px_rgba(15,23,42,0.05)]">
            <div class="grid gap-8 p-6 sm:p-8 lg:grid-cols-[minmax(0,1fr)_320px] lg:p-10">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#1674c4]">
                        Produkt
                    </p>

                    <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">
                        {{ $placeholder['name'] }}
                    </h1>

                    <div class="mt-7 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4">
                        <p class="font-bold text-amber-950">
                            Produkt obecnie niedostępny
                        </p>
                        <p class="mt-2 text-sm leading-6 text-amber-900">
                            Ten produkt nie jest obecnie dostępny do zakupu. Strona została zachowana,
                            ponieważ produkt był wcześniej oferowany i może ponownie pojawić się w ofercie.
                        </p>
                    </div>

                    <p class="mt-6 max-w-2xl text-sm leading-7 text-slate-600 sm:text-base">
                        Nie publikujemy nieaktualnej ceny ani nie umożliwiamy złożenia zamówienia,
                        dopóki produkt nie zostanie ponownie wprowadzony do sprzedaży.
                    </p>
                </div>

                <aside class="rounded-2xl border border-blue-100 bg-blue-50/70 p-6">
                    <p class="text-sm font-bold text-slate-900">
                        Szukasz tego modelu?
                    </p>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Skontaktuj się z nami. Możemy sprawdzić aktualną dostępność lub pomóc znaleźć odpowiedni produkt.
                    </p>

                    <a
                        href="{{ route('legal.contact') }}"
                        class="mt-5 inline-flex rounded-xl bg-[#155fa8] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#0b3b70]"
                    >
                        Skontaktuj się z obsługą
                    </a>
                </aside>
            </div>
        </article>
    </div>
@endsection
