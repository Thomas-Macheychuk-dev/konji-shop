@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                    Kategorie
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Zarządzaj kategoriami produktów widocznymi w sklepie oraz w menu kategorii.
                </p>
            </div>

            <a
                href="{{ route('admin.categories.create') }}"
                class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
            >
                Stwórz kategorię
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

        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <div class="mb-5">
                <h2 class="text-lg font-semibold text-zinc-900">
                    Katalog kategorii
                </h2>

                <p class="mt-1 text-sm text-zinc-500">
                    {{ $categories->total() }} kategorii
                </p>
            </div>

            <form method="GET" action="{{ route('admin.categories.index') }}" class="mb-6 rounded-2xl border border-zinc-200 bg-zinc-50 p-4">
                <div class="grid gap-4 lg:grid-cols-[1fr_240px_auto] lg:items-end">
                    <div>
                        <label for="search" class="mb-2 block text-sm font-medium text-zinc-700">
                            Szukaj kategorii
                        </label>

                        <input
                            id="search"
                            type="search"
                            name="search"
                            value="{{ $search }}"
                            placeholder="Szukaj po nazwie, slugu albo SEO..."
                            class="block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                    </div>

                    <div>
                        <label for="status" class="mb-2 block text-sm font-medium text-zinc-700">
                            Status
                        </label>

                        <select
                            id="status"
                            name="status"
                            class="block w-full rounded-xl border border-zinc-300 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-zinc-900 focus:ring-4 focus:ring-zinc-100"
                        >
                            <option value="">Wszystkie statusy</option>
                            @foreach (\App\Enums\CategoryStatus::cases() as $status)
                                <option value="{{ $status->value }}" @selected($selectedStatus === $status->value)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button class="rounded-xl bg-zinc-900 px-4 py-3 text-sm font-semibold text-white hover:bg-zinc-700">
                            Filtruj
                        </button>

                        @if ($search !== '' || $selectedStatus !== '')
                            <a
                                href="{{ route('admin.categories.index') }}"
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
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Kategoria</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Nadrzędna</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Produkty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Podkategorie</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Akcje</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($categories as $category)
                        <tr>
                            <td class="px-4 py-4 text-sm">
                                <div class="font-medium text-zinc-900">
                                    {{ $category->name }}
                                </div>

                                <div class="mt-1 text-xs text-zinc-500">
                                    {{ $category->slug }}
                                </div>
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $category->status->label() }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $category->parent?->name ?? '—' }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                {{ $category->products_count }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                {{ $category->children_count }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm">
                                <div class="flex flex-wrap justify-end gap-3">
                                    <a
                                        href="{{ route('admin.categories.edit', $category) }}"
                                        class="font-medium text-zinc-900 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700"
                                    >
                                        Edytuj
                                    </a>

                                    @if (! $category->status->isArchived())
                                        <form method="POST" action="{{ route('admin.categories.archive', $category) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="font-medium text-amber-700 underline decoration-amber-200 underline-offset-4 hover:text-amber-900">
                                                Archiwizuj
                                            </button>
                                        </form>
                                    @endif

                                    <form
                                        method="POST"
                                        action="{{ route('admin.categories.destroy', $category) }}"
                                        onsubmit="return confirm('Usunąć tę kategorię? Usunięcie jest możliwe tylko dla kategorii bez produktów i podkategorii.')"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button class="font-medium text-red-700 underline decoration-red-200 underline-offset-4 hover:text-red-900">
                                            Usuń
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-zinc-500">
                                Nie znaleziono kategorii.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>

            <div class="mt-6">
                {{ $categories->links() }}
            </div>
        </div>
    </div>
@endsection
