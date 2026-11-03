@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                    Wypożyczalnia
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Sprzęt widoczny na stronie wypożyczalni: {{ $itemCount }} pozycji. Ukryty sprzęt nie pojawia się na stronie ani w formularzu zapytania.
                </p>
            </div>

            <div class="flex flex-wrap gap-3">
                <a
                    href="{{ route('admin.pages.edit', \App\Enums\EditablePage::RENTAL) }}"
                    class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                    Teksty strony
                </a>

                <a
                    href="{{ route('rental.index') }}"
                    target="_blank"
                    class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                >
                    Zobacz stronę
                </a>

                <a
                    href="{{ route('admin.rental.create') }}"
                    class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700"
                >
                    Dodaj sprzęt
                </a>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        <div class="space-y-6">
            @foreach ($groups as $key => $group)
                <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4">
                        <h2 class="text-lg font-semibold text-zinc-900">{{ $group['heading'] }}</h2>

                        <a href="{{ route('admin.rental.create', ['group' => $key]) }}" class="text-sm font-semibold text-zinc-600 hover:text-zinc-900">
                            + Dodaj
                        </a>
                    </div>

                    @if ($group['items']->isEmpty())
                        <p class="px-5 py-4 text-sm text-zinc-500">Brak sprzętu w tej grupie.</p>
                    @else
                        <ul class="divide-y divide-zinc-100">
                            @foreach ($group['items'] as $item)
                                <li class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center">
                                    <div class="flex h-16 w-20 shrink-0 items-center justify-center rounded-lg border border-zinc-100 bg-white p-1">
                                        @if ($item->imageUrl())
                                            <img src="{{ $item->imageUrl() }}" alt="" class="max-h-full max-w-full object-contain" loading="lazy">
                                        @else
                                            <span class="text-xs text-zinc-400">Brak zdjęcia</span>
                                        @endif
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="font-semibold text-zinc-900">
                                            {{ $item->name }}
                                            @unless ($item->is_active)
                                                <span class="ml-2 rounded-lg bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-600">Ukryty</span>
                                            @endunless
                                            @if ($item->nfz_purchase)
                                                <span class="ml-2 rounded-lg bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700">NFZ</span>
                                            @endif
                                        </p>
                                        <p class="mt-1 text-sm text-zinc-500">
                                            Kaucja: {{ $item->depositLabel() }} · Miesięcznie: {{ $item->monthlyLabel() }} · Kolejność: {{ $item->sort_order }}
                                        </p>
                                    </div>

                                    <a
                                        href="{{ route('admin.rental.edit', $item) }}"
                                        class="shrink-0 rounded-xl border border-zinc-300 px-4 py-2 text-center text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                                    >
                                        Edytuj
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endforeach
        </div>
    </div>
@endsection
