@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-zinc-900">Moje zamówienia</h1>
            <p class="mt-2 text-sm text-zinc-600">
                Przejrzyj swoje wcześniejsze zamówienia i sprawdź ich aktualne statusy.
            </p>
        </div>

        @if ($orders->isEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm">
                <p class="text-sm text-zinc-700">
                    Nie masz jeszcze żadnych zamówień.
                </p>

                <div class="mt-4">
                    <a
                        href="{{ route('home') }}"
                        class="inline-flex items-center rounded-xl bg-zinc-900 px-4 py-2 text-sm font-medium text-white transition hover:bg-zinc-800"
                    >
                        Kontynuuj zakupy
                    </a>
                </div>
            </div>
        @else
            <div class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm md:block">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200">
                        <thead class="bg-zinc-50">
                        <tr>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Zamówienie
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Data
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Razem
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Status zamówienia
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Status płatności
                            </th>
                            <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Status realizacji
                            </th>
                            <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">
                                Akcja
                            </th>
                        </tr>
                        </thead>

                        <tbody class="divide-y divide-zinc-200 bg-white">
                        @foreach ($orders as $order)
                            <tr class="align-top">
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-semibold text-zinc-900">
                                    {{ $order->number }}
                                </td>

                                <td class="whitespace-nowrap px-6 py-4 text-sm text-zinc-700">
                                    {{ $order->placed_at?->format('Y-m-d H:i') }}
                                </td>

                                <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-zinc-900">
                                    {{ number_format($order->total_amount / 100, 2, '.', ' ') }} {{ $order->currency }}
                                </td>

                                <td class="px-6 py-4 text-sm text-zinc-700">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->status->badgeColorClasses() }}">
                                            {{ $order->status->label() }}
                                        </span>
                                </td>

                                <td class="px-6 py-4 text-sm text-zinc-700">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->payment_status->badgeColorClasses() }}">
                                            {{ $order->payment_status->label() }}
                                        </span>
                                </td>

                                <td class="px-6 py-4 text-sm text-zinc-700">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->fulfilment_status->badgeColorClasses() }}">
                                            {{ $order->fulfilment_status->label() }}
                                        </span>
                                </td>

                                <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                                    <a
                                        href="{{ route('account.orders.show', $order->id) }}"
                                        class="inline-flex items-center rounded-xl border border-zinc-300 px-4 py-2 font-medium text-zinc-700 transition hover:bg-zinc-50"
                                    >
                                        Zobacz szczegóły
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="space-y-4 md:hidden">
                @foreach ($orders as $order)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-sm text-zinc-500">Zamówienie</p>
                                <p class="text-base font-semibold text-zinc-900">
                                    {{ $order->number }}
                                </p>
                            </div>

                            <a
                                href="{{ route('account.orders.show', $order->id) }}"
                                class="inline-flex items-center rounded-xl border border-zinc-300 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                            >
                                Szczegóły
                            </a>
                        </div>

                        <dl class="mt-4 space-y-3 text-sm">
                            <div>
                                <dt class="text-zinc-500">Złożono</dt>
                                <dd class="mt-1 text-zinc-900">{{ $order->placed_at?->format('Y-m-d H:i') }}</dd>
                            </div>

                            <div>
                                <dt class="text-zinc-500">Razem</dt>
                                <dd class="mt-1 font-medium text-zinc-900">
                                    {{ number_format($order->total_amount / 100, 2, '.', ' ') }} {{ $order->currency }}
                                </dd>
                            </div>

                            <div>
                                <dt class="text-zinc-500">Status zamówienia</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->status->badgeColorClasses() }}">
                                        {{ $order->status->label() }}
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-zinc-500">Status płatności</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->payment_status->badgeColorClasses() }}">
                                        {{ $order->payment_status->label() }}
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-zinc-500">Status realizacji</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->fulfilment_status->badgeColorClasses() }}">
                                        {{ $order->fulfilment_status->label() }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>
                @endforeach
            </div>

            <div class="mt-8">
                {{ $orders->links() }}
            </div>
        @endif
    </div>
@endsection
