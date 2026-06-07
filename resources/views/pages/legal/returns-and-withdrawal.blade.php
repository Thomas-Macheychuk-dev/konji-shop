@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Prawa konsumenta</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Zwroty i odstąpienie od umowy
            </h1>

            <p class="mt-2 text-sm text-zinc-500">
                Version: {{ config('legal.versions.returns') }}
            </p>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Tekst roboczy. Sprawdź przed użyciem produkcyjnym.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>Termin odstąpienia</h2>
                <p>
                    Konsumenci mogą odstąpić od umowy zawartej na odległość w terminie {{ config('legal.returns.withdrawal_days') }} dni, z uwzględnieniem ustawowych wyjątków.
                </p>

                <h2>Jak odstąpić od umowy</h2>
                <p>
                    Aby odstąpić od umowy, skontaktuj się z nami e-mailem:
                    <a href="mailto:{{ config('legal.returns.contact_email') }}">{{ config('legal.returns.contact_email') }}</a>.
                    Podaj numer zamówienia oraz dane kontaktowe.
                </p>

                <h2>Adres zwrotu</h2>
                <p>
                    {{ config('legal.returns.return_address') }}
                </p>

                <h2>Zwracane towary</h2>
                <p>
                    Zwracane towary należy odesłać bez zbędnej zwłoki i zabezpieczyć przed uszkodzeniem w transporcie.
                </p>

                <h2>Zwroty środków</h2>
                <p>
                    Zwroty środków są realizowane tą samą metodą płatności, o ile jest to możliwe, chyba że uzgodniono inaczej.
                </p>
            </div>
        </div>
    </div>
@endsection
