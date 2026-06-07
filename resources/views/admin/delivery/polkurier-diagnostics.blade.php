@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                    Diagnostyka Polkurier
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Sprawdź konfigurację Polkurier i uruchom bezpieczną testową wycenę bez tworzenia przesyłki.
                </p>
            </div>

            <a
                href="{{ route('admin.orders.index') }}"
                class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
            >
                Zamówienia
            </a>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="mb-6 rounded-2xl border {{ $polkurierReady ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }} p-6 shadow-sm">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold {{ $polkurierReady ? 'text-green-900' : 'text-red-900' }}">
                        {{ $polkurierReady ? 'Polkurier jest gotowy do produkcji.' : 'Polkurier NIE jest gotowy do produkcji.' }}
                    </h2>

                    <p class="mt-2 text-sm {{ $polkurierReady ? 'text-green-800' : 'text-red-800' }}">
                        To podsumowanie jest też dostępne z terminala przez
                        <code class="rounded bg-white/70 px-1 py-0.5">php artisan polkurier:check</code>.
                    </p>
                </div>
            </div>

            <div class="mt-5 overflow-hidden rounded-xl border border-white/70 bg-white">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Kategoria</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Sprawdzenie</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Komunikat</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @foreach ($readinessItems as $item)
                        <tr>
                            <td class="px-4 py-3 text-sm text-zinc-700">
                                {{ $item['category'] }}
                            </td>

                            <td class="px-4 py-3 text-sm font-medium text-zinc-900">
                                {{ $item['name'] }}
                            </td>

                            <td class="px-4 py-3 text-sm">
                                @if ($item['status'] === 'OK')
                                    <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                OK
                            </span>
                                @elseif ($item['status'] === 'WARNING')
                                    <span class="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                Ostrzeżenie
                            </span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                Brakujące
                            </span>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-sm text-zinc-600">
                                {{ $item['message'] }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mb-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Dostępni przewoźnicy
                    </h2>

                    <p class="mt-2 text-sm text-zinc-500">
                        Dane przewoźników Polkurier w cache: {{ $availableCarriersCount }} {{ $availableCarriersCount === 1 ? 'przewoźnik' : 'przewoźników' }}.
                        Odświeżenie wywołuje Polkurier <code>available_carriers</code> z <code>additional_data=true</code>.
                    </p>
                </div>

                <form method="POST" action="{{ route('admin.polkurier.available-carriers.refresh') }}">
                    @csrf

                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Odśwież przewoźników z Polkurier
                    </button>
                </form>
            </div>

            @if ($availableCarriersCount === 0)
                <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    Brak danych przewoźników Polkurier w cache.
                </div>
            @endif

            <div class="mt-5 overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Skonfigurowana opcja</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Kod Polkurier</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status API</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Typy przesyłek</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Usługi</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Wymagane pola</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($availableCarrierSummaries as $carrier)
                        <tr>
                            <td class="px-4 py-3 text-sm">
                                <p class="font-medium text-zinc-900">
                                    {{ $carrier['label'] }}
                                </p>

                                @if ($carrier['name'])
                                    <p class="mt-1 text-xs text-zinc-500">
                                        {{ $carrier['name'] }}
                                    </p>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-sm font-mono text-zinc-700">
                                {{ $carrier['code'] ?: '—' }}
                            </td>

                            <td class="px-4 py-3 text-sm">
                                @if ($carrier['available'])
                                    <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                Dostępny
                            </span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                Nie zwrócono
                            </span>
                                @endif

                                @if ($carrier['foreign_shipments'] !== null)
                                    <p class="mt-1 text-xs text-zinc-500">
                                        Przesyłki zagraniczne:
                                        {{ $carrier['foreign_shipments'] ? 'tak' : 'nie' }}
                                    </p>
                                @endif
                            </td>

                            <td class="px-4 py-3 text-sm text-zinc-700">
                                {{ $carrier['shipment_types'] ? implode(', ', $carrier['shipment_types']) : '—' }}
                            </td>

                            <td class="px-4 py-3 text-sm text-zinc-700">
                                {{ $carrier['courier_services'] ? implode(', ', $carrier['courier_services']) : '—' }}
                            </td>

                            <td class="px-4 py-3 text-sm text-zinc-700">
                                {{ $carrier['required_additional_fields'] ? implode(', ', $carrier['required_additional_fields']) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500">
                                Brak danych przewoźników Polkurier w cache.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Konfiguracja API
                </h2>

                <dl class="mt-4 divide-y divide-zinc-100 text-sm">
                    @foreach ($configuration as $label => $configured)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <dt class="text-zinc-600">{{ $label }}</dt>
                            <dd>
                                @if ($configured)
                                    <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        OK
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                        Brakujące
                                    </span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                <p class="mt-4 text-xs text-zinc-500">
                    Wartość tokenu jest celowo ukryta.
                </p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Testowa wycena
                </h2>

                <p class="mt-2 text-sm text-zinc-500">
                    To wywołuje Polkurier <code>order_valuation_v2</code>. Nie tworzy zamówienia ani przesyłki.
                </p>

                <form
                    method="POST"
                    action="{{ route('admin.polkurier.valuation-test') }}"
                    class="mt-5 space-y-5"
                >
                    @csrf

                    <div>
                        <label for="courier_code" class="mb-2 block text-sm font-medium text-zinc-700">
                            Kurier
                        </label>

                        <select
                            id="courier_code"
                            name="courier_code"
                            class="@error('courier_code') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                            @foreach (['UPS', 'DPD', 'INPOST', 'INPOST_PACZKOMAT'] as $courierCode)
                                <option
                                    value="{{ $courierCode }}"
                                    @selected(old('courier_code', 'UPS') === $courierCode)
                                >
                                    {{ $courierCode }}
                                </option>
                            @endforeach
                        </select>

                        @error('courier_code')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="recipient_postcode" class="mb-2 block text-sm font-medium text-zinc-700">
                                Kod pocztowy odbiorcy
                            </label>

                            <input
                                id="recipient_postcode"
                                type="text"
                                name="recipient_postcode"
                                value="{{ old('recipient_postcode', '87-100') }}"
                                class="@error('recipient_postcode') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                            >

                            @error('recipient_postcode')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="recipient_country" class="mb-2 block text-sm font-medium text-zinc-700">
                                Recipient country
                            </label>

                            <input
                                id="recipient_country"
                                type="text"
                                name="recipient_country"
                                value="{{ old('recipient_country', 'PL') }}"
                                maxlength="2"
                                class="@error('recipient_country') border-red-300 ring-red-100 @else border-zinc-300 @enderror block w-full rounded-xl border bg-white px-4 py-3 text-sm uppercase text-zinc-900 outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                            >

                            @error('recipient_country')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Run valuation test
                    </button>
                </form>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Sender fields
                </h2>

                <dl class="mt-4 divide-y divide-zinc-100 text-sm">
                    @foreach ($senderFields as $field => $configured)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <dt class="font-mono text-xs text-zinc-600">{{ $field }}</dt>
                            <dd>
                                @if ($configured)
                                    <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        OK
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                        Brakujące
                                    </span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Default pack fields
                </h2>

                <dl class="mt-4 divide-y divide-zinc-100 text-sm">
                    @foreach ($defaultPackFields as $field => $configured)
                        <div class="flex items-center justify-between gap-4 py-3">
                            <dt class="font-mono text-xs text-zinc-600">{{ $field }}</dt>
                            <dd>
                                @if ($configured)
                                    <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        OK
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                        Brakujące
                                    </span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </div>
        </div>

        @if (session('polkurier_valuation_request') || session('polkurier_valuation_response'))
            <div class="mt-6 grid gap-6 lg:grid-cols-2">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Last valuation request
                    </h2>

                    <pre class="mt-4 overflow-x-auto rounded-xl bg-zinc-950 p-4 text-xs text-zinc-100">{{ json_encode(session('polkurier_valuation_request'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Last valuation response
                    </h2>

                    <pre class="mt-4 overflow-x-auto rounded-xl bg-zinc-950 p-4 text-xs text-zinc-100">{{ json_encode(session('polkurier_valuation_response'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            </div>
        @endif
    </div>
@endsection
