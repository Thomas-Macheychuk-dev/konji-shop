@extends('layouts.storefront', ['seoTitle' => 'Nie znaleziono strony', 'robots' => 'noindex, follow'])

@section('content')
    <section class="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6 lg:py-24">
        <p class="text-xs font-bold uppercase tracking-[0.18em] text-[#1674c4]">Błąd 404</p>
        <h1 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Nie znaleźliśmy tej strony</h1>
        <p class="mx-auto mt-4 max-w-xl text-sm leading-6 text-slate-600 sm:text-base">
            Produkt mógł zostać wycofany albo adres zawiera literówkę. Wyszukaj produkt lub przejdź do kategorii.
        </p>

        <form method="GET" action="{{ route('home') }}" role="search" class="mx-auto mt-8 flex max-w-xl gap-2">
            <label for="not-found-search" class="sr-only">Szukaj produktów</label>
            <input id="not-found-search" type="search" name="q" placeholder="Np. orteza kolana"
                class="min-w-0 flex-1 rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-900 shadow-sm outline-none focus:border-[#155fa8] focus:ring-4 focus:ring-blue-100">
            <button type="submit" class="shrink-0 rounded-xl bg-[#155fa8] px-5 py-3 text-sm font-bold text-white transition hover:bg-[#0b3b70]">Szukaj</button>
        </form>

        <div class="mt-6 flex flex-wrap justify-center gap-3">
            <a href="{{ route('categories.index') }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-[#155fa8]">Wszystkie kategorie</a>
            <a href="{{ route('home') }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-[#155fa8]">Strona główna</a>
            <a href="{{ route('legal.contact') }}" class="rounded-xl border border-slate-200 bg-white px-5 py-3 text-sm font-semibold text-slate-700 transition hover:border-blue-200 hover:text-[#155fa8]">Kontakt</a>
        </div>
    </section>
@endsection
