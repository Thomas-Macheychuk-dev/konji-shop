@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Dane sprzedawcy</p>

            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">
                Kontakt
            </h1>

            <div class="prose prose-zinc mt-8 max-w-none">
                <h2>{{ config('legal.seller.shop_name') }}</h2>

                <p>
                    <strong>Firma:</strong> {{ config('legal.seller.company_name') }}<br>
                    <strong>Reprezentant:</strong> {{ config('legal.seller.representative') }}<br>
                    <strong>Adres:</strong>
                    {{ config('legal.seller.street') }},
                    {{ config('legal.seller.postcode') }} {{ config('legal.seller.city') }},
                    {{ config('legal.seller.country') }}
                </p>

                @if (filled(config('legal.seller.tax_id')))
                    <p>
                        <strong>NIP:</strong> {{ config('legal.seller.tax_id') }}
                    </p>
                @endif

                @if (filled(config('legal.seller.business_registry_number')))
                    <p>
                        <strong>Numer w rejestrze przedsiębiorców:</strong> {{ config('legal.seller.business_registry_number') }}
                    </p>
                @endif

                <p>
                    <strong>E-mail:</strong>
                    <a href="mailto:{{ config('legal.seller.email') }}">{{ config('legal.seller.email') }}</a><br>
                    <strong>Telefon:</strong> {{ config('legal.seller.phone') }}
                </p>
            </div>
        </div>
    </div>
@endsection
