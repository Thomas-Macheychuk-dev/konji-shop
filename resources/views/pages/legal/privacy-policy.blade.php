@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Dane osobowe</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Polityka prywatności
            </h1>

            <p class="mt-2 text-sm text-zinc-500">
                Version: {{ config('legal.versions.privacy') }}
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

                <h2>Administrator danych</h2>
                @if ($identityAddressLines !== [])
                    <p>
                        Administratorem danych jest:<br>
                        @foreach ($identityAddressLines as $identityAddressLine)
                            {{ trim($identityAddressLine) }}@if (! $loop->last)<br>@endif
                        @endforeach
                    </p>
                @else
                    <p>
                        Administratorem danych jest {{ config('legal.seller.company_name') }},
                        {{ config('legal.seller.street') }},
                        {{ config('legal.seller.postcode') }} {{ config('legal.seller.city') }}.
                    </p>
                @endif

                <h2>Kontakt</h2>
                <p>
                    Pytania dotyczące prywatności można wysyłać na adres
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a>.
                </p>

                <h2>Przetwarzane dane</h2>
                <p>
                    Sklep może przetwarzać dane konta klienta, dane kontaktowe, adresy dostawy i rozliczeniowe, historię zamówień, informacje o płatnościach oraz dane techniczne niezbędne do działania strony internetowej.
                </p>

                <h2>Cel przetwarzania</h2>
                <p>
                    Dane są przetwarzane w celu obsługi zamówień, płatności, dostawy, komunikacji z klientem, reklamacji, zwrotów, obowiązków księgowych, bezpieczeństwa oraz działania strony internetowej.
                </p>

                <h2>Odbiorcy danych</h2>
                <p>
                    Dane mogą być udostępniane usługodawcom, takim jak operatorzy płatności, dostawcy usług dostawy, hostingodawcy, dostawcy poczty e-mail oraz doradcy księgowi lub prawni, gdy jest to niezbędne.
                </p>

                <h2>Prawa klienta</h2>
                <p>
                    Klienci mogą mieć prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania, wniesienia sprzeciwu oraz przenoszenia danych, zgodnie z obowiązującym prawem.
                </p>
            </div>
        </div>
    </div>
@endsection
