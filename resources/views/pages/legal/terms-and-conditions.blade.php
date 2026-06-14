@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Zasady sklepu</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Regulamin
            </h1>

            <p class="mt-2 text-sm text-zinc-500">
                Version: {{ config('legal.versions.terms') }}
            </p>

            <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                Tekst roboczy. Sprawdź przed użyciem produkcyjnym.
            </p>

            <div class="prose prose-zinc mt-8 max-w-none">
                @php
                    $identityAddress = trim((string) config('legal.seller.identity_address'));
                    $identityAddressLines = filled($identityAddress)
                        ? array_values(array_filter(preg_split('/\R+/', $identityAddress) ?: [], fn (string $line): bool => trim($line) !== ''))
                        : [];
                @endphp

                <h2>Sprzedawca</h2>
                @if ($identityAddressLines !== [])
                    <p>
                        Sklep internetowy jest prowadzony przez:<br>
                        @foreach ($identityAddressLines as $identityAddressLine)
                            {{ trim($identityAddressLine) }}@if (! $loop->last)<br>@endif
                        @endforeach
                    </p>
                @else
                    <p>
                        Sklep internetowy jest prowadzony przez {{ config('legal.seller.company_name') }},
                        {{ config('legal.seller.street') }},
                        {{ config('legal.seller.postcode') }} {{ config('legal.seller.city') }},
                        {{ config('legal.seller.country') }}.
                    </p>
                @endif

                <p>
                    Kontakt:
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>,
                    {{ config('legal.seller.phone') }}.
                </p>

                <h2>Zamówienia</h2>
                <p>
                    Zamówienia można składać przez formularz kasy. Przed złożeniem zamówienia klient może sprawdzić ceny produktów, VAT, koszty dostawy, metodę dostawy oraz łączną kwotę do zapłaty.
                </p>

                <h2>Ceny i płatności</h2>
                <p>
                    Ceny produktów są podawane jako ceny brutto w PLN, chyba że wskazano inaczej. Kasa pokazuje rozbicie VAT, koszt dostawy oraz łączną kwotę brutto przed złożeniem zamówienia.
                </p>

                <h2>Dostawa</h2>
                <p>
                    Dostępne metody i koszty dostawy są wyświetlane w kasie. Dostawa może być realizowana kurierem, do paczkomatu albo przez odbiór osobisty, jeśli jest dostępny.
                </p>

                <h2>Prawo odstąpienia od umowy</h2>
                <p>
                    Konsumenci mogą mieć prawo odstąpienia od umowy zawartej na odległość w terminie 14 dni, z uwzględnieniem ustawowych wyjątków. Szczegóły znajdują się na stronie Zwroty i odstąpienie od umowy.
                </p>

                <h2>Reklamacje</h2>
                <p>
                    Reklamacje można składać e-mailem na adres
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>.
                    Szczegóły znajdują się na stronie Reklamacje i gwarancja.
                </p>
            </div>
        </div>
    </div>
@endsection
