@extends('layouts.storefront')

@section('content')
    @php($seller = config('legal.seller'))

    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm sm:p-8">
            <p class="text-sm font-medium text-zinc-500">Dane sprzedawcy</p>
            <h1 class="mt-2 text-3xl font-bold tracking-tight text-zinc-900">Kontakt</h1>

            <div class="prose prose-zinc mt-8 max-w-none">
                <p>
                    <strong>Sprzedawca:</strong> {{ $seller['company_name'] }}<br>
                    <strong>Adres:</strong> ul. {{ $seller['street'] }}, {{ $seller['postcode'] }} {{ $seller['city'] }}<br>
                    <strong>KRS:</strong> {{ $seller['business_registry_number'] }}<br>
                    <strong>NIP:</strong> {{ $seller['tax_id'] }}<br>
                    <strong>REGON:</strong> {{ $seller['regon'] }}<br>
                    <strong>Kapitał zakładowy:</strong> {{ $seller['share_capital'] }}
                </p>
                <p>
                    <strong>E-mail:</strong> <a href="mailto:{{ $seller['email'] }}">{{ $seller['email'] }}</a><br>
                    <strong>Telefon:</strong> {{ $seller['phone'] }}
                </p>
                <p><strong>Adres do zwrotów i reklamacji:</strong> {{ config('legal.returns.return_address') }}</p>
            </div>
        </div>
    </div>
@endsection
