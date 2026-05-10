@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <a href="{{ route('admin.orders.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                    ← Back to orders
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Order {{ $order->number }}
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Placed at {{ $order->placed_at?->format('Y-m-d H:i') ?? 'not placed' }}
                </p>
            </div>

            <div class="text-right text-sm">
                <p class="font-semibold text-zinc-900">
                    {{ number_format($order->total_amount / 100, 2) }} {{ $order->currency }}
                </p>
                <p class="mt-1 text-zinc-500">Total</p>
            </div>
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

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Status</h2>

                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Order</dt>
                        <dd class="font-medium text-zinc-900">{{ $order->status->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Payment</dt>
                        <dd class="font-medium text-zinc-900">{{ $order->payment_status->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Fulfilment</dt>
                        <dd class="font-medium text-zinc-900">{{ $order->fulfilment_status->label() }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-2">
                <h2 class="text-lg font-semibold text-zinc-900">Fulfilment actions</h2>

                <div class="mt-4 flex flex-wrap gap-3">
                    @if ($order->status->isConfirmed() && $order->fulfilment_status->isUnfulfilled())
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'processing']) }}">
                            @csrf
                            @method('PATCH')
                            <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                Start processing
                            </button>
                        </form>
                    @endif

                    @if ($order->status->isConfirmed() && $order->fulfilment_status->isProcessing())
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'shipped']) }}">
                            @csrf
                            @method('PATCH')
                            <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                Mark as shipped
                            </button>
                        </form>
                    @endif

                    @if ($order->status->isConfirmed() && $order->fulfilment_status->isShipped())
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'delivered']) }}">
                            @csrf
                            @method('PATCH')
                            <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                Mark as delivered
                            </button>
                        </form>
                    @endif

                    @if ($order->status->isConfirmed() && $order->fulfilment_status->isDelivered())
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'completed']) }}">
                            @csrf
                            @method('PATCH')
                            <button class="rounded-xl bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600">
                                Complete order
                            </button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Customer</h2>

                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Email</dt>
                        <dd class="font-medium text-zinc-900">
                            {{ $order->user?->email ?? $order->guest_email ?? 'Unknown' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-zinc-500">Type</dt>
                        <dd class="font-medium text-zinc-900">
                            {{ $order->isGuestOrder() ? 'Guest' : 'Registered user' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Internal notes</h2>

                <div class="mt-4 whitespace-pre-line rounded-xl bg-zinc-50 p-4 text-sm text-zinc-700">
                    {{ $order->notes ?: 'No notes.' }}
                </div>

                <form
                    method="POST"
                    action="{{ route('admin.orders.notes.update', $order) }}"
                    class="mt-4 space-y-3"
                >
                    @csrf
                    @method('PATCH')

                    <textarea
                        name="note"
                        rows="4"
                        class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm"
                        placeholder="Add an internal note..."
                    ></textarea>

                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Add note
                    </button>
                </form>
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">Order timeline</h2>

            <div class="mt-4 space-y-4">
                @forelse ($order->events as $event)
                    <div class="border-l-2 border-zinc-200 pl-4">
                        <p class="text-sm font-semibold text-zinc-900">
                            {{ $event->description }}
                        </p>

                        <p class="mt-1 text-xs text-zinc-500">
                            {{ $event->created_at->format('Y-m-d H:i') }}
                        </p>

                        @if ($event->meta)
                            <dl class="mt-2 rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600">
                                @foreach ($event->meta as $key => $value)
                                    <div class="flex justify-between gap-4">
                                        <dt class="font-medium">{{ str($key)->headline() }}</dt>
                                        <dd>{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No timeline events yet.</p>
                @endforelse
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">Items</h2>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">SKU</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Unit price</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Total</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($order->items as $item)
                        <tr>
                            <td class="px-4 py-4 text-sm">
                                <p class="font-medium text-zinc-900">{{ $item->product_name_snapshot }}</p>
                                @if ($item->variant_name_snapshot)
                                    <p class="mt-1 text-xs text-zinc-500">{{ $item->variant_name_snapshot }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $item->sku_snapshot ?: '—' }}
                            </td>
                            <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                {{ $item->unitPriceDecimal() }} {{ $order->currency }}
                            </td>
                            <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                {{ $item->quantity }}
                            </td>
                            <td class="px-4 py-4 text-right text-sm font-semibold text-zinc-900">
                                {{ $item->lineTotalDecimal() }} {{ $order->currency }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">
                                No items found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            @forelse ($order->addresses as $address)
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        {{ ucfirst($address->type) }} address
                    </h2>

                    <div class="mt-4 space-y-1 text-sm text-zinc-700">
                        @foreach ($address->formattedLines() as $line)
                            <p>{{ $line }}</p>
                        @endforeach

                        @if ($address->email)
                            <p class="pt-2">{{ $address->email }}</p>
                        @endif

                        @if ($address->phone)
                            <p>{{ $address->phone }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 text-sm text-zinc-500 shadow-sm lg:col-span-2">
                    No addresses found.
                </div>
            @endforelse
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">Payments</h2>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Provider</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Reference</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">External status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Amount</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($order->payments as $payment)
                        <tr>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->provider ?: '—' }}
                            </td>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->provider_reference ?: '—' }}
                            </td>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->status->label() }}
                            </td>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->external_status ?: '—' }}
                            </td>
                            <td class="px-4 py-4 text-right text-sm font-semibold text-zinc-900">
                                {{ $payment->amountDecimal() }} {{ $payment->currency }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">
                                No payments found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
