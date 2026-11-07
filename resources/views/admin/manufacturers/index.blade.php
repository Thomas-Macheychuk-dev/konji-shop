@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                Producenci (GPSR)
            </h1>

            <p class="mt-2 max-w-3xl text-sm text-zinc-600">
                Dane producenta, które rozporządzenie GPSR wymaga na stronie każdego produktu: nazwa, adres pocztowy i elektroniczny, a dla producentów spoza UE — osoba odpowiedzialna w UE.
                Dane wpisane dla marki pojawiają się na stronach wszystkich jej produktów.
            </p>
        </div>

        @php
            $missingCount = $coverage['active'] - $coverage['covered'];
        @endphp

        <div class="mb-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Aktywne produkty z danymi</p>
                <p class="mt-2 text-2xl font-bold text-zinc-900">{{ number_format($coverage['covered'], 0, ',', ' ') }} / {{ number_format($coverage['active'], 0, ',', ' ') }}</p>
            </div>

            <div class="rounded-2xl border p-5 shadow-sm {{ $missingCount > 0 ? 'border-amber-200 bg-amber-50' : 'border-zinc-200 bg-white' }}">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Aktywne produkty bez danych</p>
                <p class="mt-2 text-2xl font-bold text-zinc-900">{{ number_format($missingCount, 0, ',', ' ') }}</p>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">W tym bez przypisanej marki</p>
                <p class="mt-2 text-2xl font-bold text-zinc-900">{{ number_format($coverage['without_brand'], 0, ',', ' ') }}</p>
                <p class="mt-1 text-xs text-zinc-500">Tym produktom trzeba najpierw nadać atrybut „Producent” lub „Marka”.</p>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <form method="GET" action="{{ route('admin.manufacturers.index') }}" class="mb-6 flex flex-col gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:flex-row sm:items-center">
            <label for="search" class="sr-only">Szukaj marki</label>
            <input
                id="search"
                type="search"
                name="search"
                value="{{ $search }}"
                placeholder="Szukaj po marce lub nazwie producenta…"
                class="block w-full flex-1 rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm text-zinc-900 outline-none focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
            >

            <label class="flex shrink-0 items-center gap-2 text-sm font-medium text-zinc-700">
                <input type="checkbox" name="missing" value="1" @checked($onlyMissing) class="h-4 w-4 rounded border-zinc-300">
                Tylko bez danych
            </label>

            <button type="submit" class="shrink-0 rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-zinc-700">
                Filtruj
            </button>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-zinc-100 text-sm">
                <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                    <tr>
                        <th class="px-5 py-3">Marka</th>
                        <th class="px-5 py-3">Atrybut</th>
                        <th class="px-5 py-3 text-right">Aktywne produkty</th>
                        <th class="px-5 py-3">Dane producenta</th>
                        <th class="px-5 py-3 text-right">Akcja</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-zinc-100">
                    @forelse ($brands as $brand)
                        <tr>
                            <td class="px-5 py-3 font-semibold text-zinc-900">{{ $brand->value }}</td>
                            <td class="px-5 py-3 text-zinc-600">{{ $brand->attribute?->name }}</td>
                            <td class="px-5 py-3 text-right tabular-nums text-zinc-900">{{ number_format($brand->active_products_count, 0, ',', ' ') }}</td>
                            <td class="px-5 py-3">
                                @if ($brand->manufacturer)
                                    <span class="rounded-lg bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">Uzupełnione</span>
                                    <span class="ml-2 text-zinc-600">{{ $brand->manufacturer->name }}</span>
                                @else
                                    <span class="rounded-lg bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-800">Brak</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-right">
                                <a href="{{ route('admin.manufacturers.edit', $brand) }}" class="font-semibold text-zinc-700 hover:text-zinc-900">
                                    {{ $brand->manufacturer ? 'Edytuj' : 'Uzupełnij' }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-5 py-6 text-center text-zinc-500">Brak marek spełniających kryteria.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $brands->links() }}
        </div>
    </div>
@endsection
