@extends('layouts.storefront')

@section('content')
    <x-storefront.category-page-shell wide>
        <section>
            <div class="mx-auto max-w-[1480px] px-4 py-10 sm:px-6 lg:px-8 lg:py-14">
                <nav class="flex flex-wrap items-center gap-2 text-xs font-medium text-slate-500" aria-label="Okruszki">
                    <a href="{{ route('home') }}" class="transition hover:text-[#155fa8]">Strona główna</a>
                    <span aria-hidden="true">/</span>
                    <span class="text-slate-900" aria-current="page">Kategorie</span>
                </nav>

                <p class="mt-6 text-xs font-bold uppercase tracking-[0.18em] text-[#1674c4]">Kategorie</p>
                <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-950 sm:text-4xl">Wszystkie działy sklepu</h1>
                <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-600 sm:text-base">
                    Wybierz dział, aby zobaczyć jego podkategorie i produkty.
                </p>

                <x-storefront.category-grid :categories="$categories" />
            </div>
        </section>
    </x-storefront.category-page-shell>
@endsection
