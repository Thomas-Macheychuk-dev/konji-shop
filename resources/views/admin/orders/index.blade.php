@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-zinc-900">Zamówienia</h1>
            <p class="mt-2 text-sm text-zinc-600">Manage customer orders and fulfilment.</p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
            <form method="GET" action="{{ route('admin.orders.index') }}" class="mb-6 grid gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:grid-cols-5">
                <div>
                    <label for="search" class="block text-sm font-medium text-zinc-700">Szukaj</label>
                    <input
                        id="search"
                        name="search"
                        value="{{ request('search') }}"
                        placeholder="Numer zamówienia lub e-mail"
                        class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm"
                    >
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-zinc-700">Status</label>
                    <select id="status" name="status" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                        <option value="">Wszystkie statusy</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status->value }}" @selected(request('status') === $status->value)>
                                {{ $status->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="payment_status" class="block text-sm font-medium text-zinc-700">Płatność</label>
                    <select id="payment_status" name="payment_status" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                        <option value="">Wszystkie statusy płatności</option>
                        @foreach ($paymentStatuses as $paymentStatus)
                            <option value="{{ $paymentStatus->value }}" @selected(request('payment_status') === $paymentStatus->value)>
                                {{ $paymentStatus->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="fulfilment_status" class="block text-sm font-medium text-zinc-700">Realizacja</label>
                    <select id="fulfilment_status" name="fulfilment_status" class="mt-1 w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm">
                        <option value="">Wszystkie statusy realizacji</option>
                        @foreach ($fulfilmentStatuses as $fulfilmentStatus)
                            <option value="{{ $fulfilmentStatus->value }}" @selected(request('fulfilment_status') === $fulfilmentStatus->value)>
                                {{ $fulfilmentStatus->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="flex items-end gap-2">
                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Filtruj
                    </button>

                    <a href="{{ route('admin.orders.index') }}" class="rounded-xl border border-zinc-300 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50">
                        Resetuj
                    </a>
                </div>
            </form>
            <table class="min-w-full divide-y divide-zinc-200">
                <thead class="bg-zinc-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Zamówienie</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Klient</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Płatność</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Realizacja</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Razem</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white">
                @forelse ($orders as $order)
                    <tr>
                        <td class="px-4 py-4 text-sm">
                            <a href="{{ route('admin.orders.show', $order) }}" class="font-semibold text-zinc-900 hover:text-zinc-700">
                                {{ $order->number }}
                            </a>
                            <p class="mt-1 text-xs text-zinc-500">
                                {{ $order->placed_at?->format('Y-m-d H:i') ?? 'Not placed' }}
                            </p>
                        </td>
                        <td class="px-4 py-4 text-sm text-zinc-700">
                            {{ $order->user?->email ?? $order->guest_email ?? 'Unknown' }}
                        </td>
                        <td class="px-4 py-4 text-sm">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $order->status->badgeColorClasses() }}">
                                {{ $order->status->label() }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-sm">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $order->payment_status->badgeColorClasses() }}">
                                {{ $order->payment_status->label() }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-sm">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $order->fulfilment_status->badgeColorClasses() }}">
                                {{ $order->fulfilment_status->label() }}
                            </span>
                        </td>

                        <td class="px-4 py-4 text-right text-sm font-semibold text-zinc-900">
                            {{ number_format($order->total_amount / 100, 2) }} {{ $order->currency }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-500">
                            Nie znaleziono zamówień.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-6">
            {{ $orders->links() }}
        </div>
    </div>
@endsection
