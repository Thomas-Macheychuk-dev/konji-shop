@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                    Produkty
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Zarządzaj produktami oraz danymi paczek używanymi do wyceny dostawy Polkurier.
                </p>
            </div>

            <a
                href="{{ route('admin.products.create') }}"
                class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
            >
                Stwórz produkt
            </a>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-5">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Katalog produktów
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    {{ $products->total() }} produktów
                </p>
            </div>

            <form method="GET" action="{{ route('admin.products.index') }}" class="mb-6 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <div class="grid gap-4 md:grid-cols-[1fr_auto] md:items-end">
                    <div>
                        <label for="search" class="mb-2 block text-sm font-medium text-zinc-700">
                            Szukaj produktów
                        </label>

                        <input
                            id="search"
                            type="search"
                            name="search"
                            value="{{ $search }}"
                            placeholder="Szukaj po nazwie produktu, slugu, ID zewnętrznym, SKU zewnętrznym lub SKU wariantu..."
                            class="block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <label class="flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-700">
                            <input
                                type="checkbox"
                                name="missing_package_data"
                                value="1"
                                @checked($missingPackageData)
                                class="rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900"
                            >

                            Tylko brakujące wagi/wymiary
                        </label>

                        <button class="rounded-xl bg-zinc-900 px-4 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
                            Filtruj
                        </button>

                        @if ($search !== '' || $missingPackageData)
                            <a
                                href="{{ route('admin.products.index') }}"
                                class="rounded-xl border border-zinc-300 px-4 py-3 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                            >
                                Wyczyść
                            </a>
                        @endif
                    </div>
                </div>
            </form>

            <div class="overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Produkt</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Warianty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Dane paczek</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Źródło zewnętrzne</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Aktualizacja</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Akcja</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($products as $product)
                        <tr>
                            <td class="px-4 py-4 text-sm">
                                <div class="font-medium text-zinc-900">
                                    {{ $product->name }}
                                </div>

                                <div class="mt-1 text-xs text-zinc-500">
                                    {{ $product->slug }}
                                </div>

                                @if ($product->external_parent_sku)
                                    <div class="mt-1 text-xs text-zinc-400">
                                        SKU nadrzędny: {{ $product->external_parent_sku }}
                                    </div>
                                @endif
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $product->status->label() }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                {{ $product->variants_count }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm">
                                @if ((int) $product->variants_missing_package_data_count > 0)
                                    <span class="inline-flex rounded-full bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">
                                        {{ $product->variants_missing_package_data_count }} z brakującą wagą/wymiarami
                                    </span>
                                @else
                                    <span class="inline-flex rounded-full bg-green-100 px-2.5 py-1 text-xs font-semibold text-green-700">
                                        Kompletne
                                    </span>
                                @endif
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                <div>
                                    {{ $product->external_source ?: '—' }}
                                </div>

                                @if ($product->external_id)
                                    <div class="mt-1 text-xs text-zinc-400">
                                        {{ $product->external_id }}
                                    </div>
                                @endif
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                <div>
                                    {{ $product->updated_at ?: $product->created_at }}
                                </div>
                            </td>

                            <td class="px-4 py-4 text-right text-sm">
                                <a
                                    href="{{ route('admin.products.edit', $product) }}"
                                    class="font-medium text-zinc-900 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700"
                                >
                                    Edytuj produkt
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-500">
                                Nie znaleziono produktów.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-6">
                {{ $products->links() }}
            </div>
        </div>
    </div>
@endsection
