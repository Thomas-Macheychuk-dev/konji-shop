@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                Edytor stron
            </h1>

            <p class="mt-2 text-sm text-zinc-600">
                Edytuj tytuły, wstępy i treść stron informacyjnych sklepu.
            </p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <ul class="divide-y divide-zinc-100">
                @foreach ($pages as $row)
                    <li class="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <p class="font-semibold text-zinc-900">{{ $row['title'] }}</p>
                            <p class="mt-1 text-sm text-zinc-500">/{{ $row['page']->value }}</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            @if ($row['customised'])
                                <span class="rounded-lg bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">
                                    Zmieniona {{ $row['updatedAt']?->format('d.m.Y H:i') }}
                                </span>
                            @else
                                <span class="rounded-lg bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600">
                                    Treść domyślna
                                </span>
                            @endif

                            <a
                                href="{{ route($row['page']->routeName()) }}"
                                class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50"
                            >
                                Zobacz
                            </a>

                            <a
                                href="{{ route('admin.pages.edit', $row['page']) }}"
                                class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700"
                            >
                                Edytuj
                            </a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endsection
