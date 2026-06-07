@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Obsługa klienta</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Reklamacje i gwarancja
            </h1>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Tekst roboczy. Sprawdź przed użyciem produkcyjnym.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Submitting a complaint</h2>
                <p>
                    Reklamacje można składać e-mailem na adres
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>.
                </p>

                <h2>Informacje, które należy podać</h2>
                <p>
                    Podaj numer zamówienia, nazwę produktu, opis problemu, zdjęcia, jeśli są istotne, oraz preferowany sposób rozwiązania sprawy.
                </p>

                <h2>Obsługa reklamacji</h2>
                <p>
                    Rozpatrzymy reklamację i skontaktujemy się z Tobą na adres e-mail przypisany do zamówienia.
                </p>
            </div>
        </div>
    </div>
@endsection
