@extends('layouts.storefront')

@section('content')
    @php
        $seller = config('legal.seller');
        $phoneHref = 'tel:'.preg_replace('/[^0-9+]/', '', (string) $seller['phone']);
    @endphp

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Obsługa klienta</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Kontakt</h1>
            <p class="mt-3 max-w-2xl text-base leading-7 text-zinc-600">
                Masz pytanie o produkt, zamówienie albo dobór rozmiaru? Napisz lub zadzwoń.
            </p>

            <div class="mt-8 grid gap-4 sm:grid-cols-2">
                <div class="rounded-xl bg-zinc-50 p-5 ring-1 ring-zinc-200">
                    <h2 class="text-sm font-semibold text-zinc-900">Napisz lub zadzwoń</h2>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div>
                            <dt class="text-zinc-500">E-mail</dt>
                            <dd><a href="mailto:{{ $seller['email'] }}" class="font-medium text-zinc-900 underline underline-offset-4">{{ $seller['email'] }}</a></dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500">Telefon</dt>
                            <dd><a href="{{ $phoneHref }}" class="font-medium text-zinc-900 underline underline-offset-4">{{ $seller['phone'] }}</a></dd>
                        </div>
                    </dl>
                </div>

                <div class="rounded-xl bg-zinc-50 p-5 ring-1 ring-zinc-200">
                    <h2 class="text-sm font-semibold text-zinc-900">Zwroty i reklamacje</h2>
                    <p class="mt-3 text-sm text-zinc-500">Adres do odsyłania towaru</p>
                    <p class="text-sm font-medium text-zinc-900">{{ $seller['company_name'] }}<br>{{ config('legal.returns.return_address') }}</p>
                    <p class="mt-3 text-sm">
                        <a href="{{ route('legal.returns') }}" class="font-medium text-zinc-900 underline underline-offset-4">Zwroty</a>
                        <span class="text-zinc-400">·</span>
                        <a href="{{ route('legal.complaints') }}" class="font-medium text-zinc-900 underline underline-offset-4">Reklamacje</a>
                    </p>
                </div>
            </div>

            <div class="mt-4 rounded-xl p-5 ring-1 ring-zinc-200">
                <h2 class="text-sm font-semibold text-zinc-900">Dane sprzedawcy</h2>
                <dl class="mt-3 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-zinc-500">Sprzedawca</dt>
                        <dd class="font-medium text-zinc-900">{{ $seller['company_name'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Adres siedziby</dt>
                        <dd class="font-medium text-zinc-900">ul. {{ $seller['street'] }}, {{ $seller['postcode'] }} {{ $seller['city'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">KRS</dt>
                        <dd class="font-medium text-zinc-900">{{ $seller['business_registry_number'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">NIP</dt>
                        <dd class="font-medium text-zinc-900">{{ $seller['tax_id'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">REGON</dt>
                        <dd class="font-medium text-zinc-900">{{ $seller['regon'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Kapitał zakładowy</dt>
                        <dd class="font-medium text-zinc-900">{{ $seller['share_capital'] }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>
@endsection
