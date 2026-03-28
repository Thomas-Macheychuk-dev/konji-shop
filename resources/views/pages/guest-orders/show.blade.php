@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="w-full">
                <a
                    href="{{ route('guest.orders.track.show') }}"
                    class="inline-flex items-center text-sm font-medium text-zinc-600 transition hover:text-zinc-900"
                >
                    ← Back to order lookup
                </a>

                <div class="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                        Order {{ $order->number }}
                    </h1>

                    @if ($order->canBeCancelled())
                        <form
                            method="POST"
                            action="{{ route('guest.orders.cancel', $order) }}"
                            data-order-cancel-form
                        >
                            @csrf

                            <button
                                type="submit"
                                class="inline-flex items-center rounded-xl border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-100"
                            >
                                Cancel order
                            </button>
                        </form>
                    @endif
                </div>

                <p class="mt-2 text-sm text-zinc-600">
                    Placed on {{ $order->placed_at?->format('Y-m-d H:i') }}
                </p>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <div class="mb-8 grid gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Order status</p>
                <div class="mt-3">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->status->badgeColorClasses() }}">
                        {{ $order->status->label() }}
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Payment status</p>
                <div class="mt-3">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->payment_status->badgeColorClasses() }}">
                        {{ $order->payment_status->label() }}
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Fulfilment status</p>
                <div class="mt-3">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->fulfilment_status->badgeColorClasses() }}">
                        {{ $order->fulfilment_status->label() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="grid gap-8 lg:grid-cols-3">
            <div class="space-y-8 lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="border-b border-zinc-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-zinc-900">Order items</h2>
                    </div>

                    <div class="divide-y divide-zinc-200">
                        @foreach ($order->items as $item)
                            <div class="px-6 py-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <h3 class="text-base font-semibold text-zinc-900">
                                            {{ $item->product_name_snapshot }}
                                        </h3>

                                        @if ($item->variant_name_snapshot)
                                            <p class="mt-1 text-sm text-zinc-600">
                                                {{ $item->variant_name_snapshot }}
                                            </p>
                                        @endif

                                        @if ($item->sku_snapshot)
                                            <p class="mt-2 text-sm text-zinc-500">
                                                SKU: {{ $item->sku_snapshot }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="shrink-0 text-sm sm:text-right">
                                        <p class="text-zinc-500">Quantity</p>
                                        <p class="font-medium text-zinc-900">{{ $item->quantity }}</p>
                                    </div>
                                </div>

                                <div class="mt-4 flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:justify-between">
                                    <div class="text-zinc-600">
                                        Unit price:
                                        <span class="font-medium text-zinc-900">
                                            {{ $item->unitPriceDecimal() }} {{ $order->currency }}
                                        </span>
                                    </div>

                                    <div class="text-zinc-600">
                                        Line total:
                                        <span class="font-semibold text-zinc-900">
                                            {{ $item->lineTotalDecimal() }} {{ $order->currency }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @if ($order->payments->isNotEmpty())
                    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                        <div class="border-b border-zinc-200 px-6 py-4">
                            <h2 class="text-lg font-semibold text-zinc-900">Payments</h2>
                        </div>

                        <div class="divide-y divide-zinc-200">
                            @foreach ($order->payments as $payment)
                                <div class="px-6 py-4">
                                    <dl class="grid gap-4 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt class="text-zinc-500">Status</dt>
                                            <dd class="mt-1">
                                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $payment->status->badgeColorClasses() }}">
                                                    {{ $payment->status->label() }}
                                                </span>
                                            </dd>
                                        </div>

                                        <div>
                                            <dt class="text-zinc-500">Amount</dt>
                                            <dd class="mt-1 text-zinc-900">
                                                {{ $payment->amountDecimal() }} {{ $payment->currency }}
                                            </dd>
                                        </div>

                                        @if ($payment->provider)
                                            <div>
                                                <dt class="text-zinc-500">Provider</dt>
                                                <dd class="mt-1 text-zinc-900">{{ $payment->provider }}</dd>
                                            </div>
                                        @endif

                                        @if ($payment->provider_reference)
                                            <div>
                                                <dt class="text-zinc-500">Reference</dt>
                                                <dd class="mt-1 break-all text-zinc-900">{{ $payment->provider_reference }}</dd>
                                            </div>
                                        @endif

                                        @if ($payment->paid_at)
                                            <div>
                                                <dt class="text-zinc-500">Paid at</dt>
                                                <dd class="mt-1 text-zinc-900">{{ $payment->paid_at->format('Y-m-d H:i') }}</dd>
                                            </div>
                                        @endif
                                    </dl>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <div class="space-y-8">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">Summary</h2>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-zinc-600">Order number</dt>
                            <dd class="font-medium text-zinc-900">{{ $order->number }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-zinc-600">Placed at</dt>
                            <dd class="text-right font-medium text-zinc-900">
                                {{ $order->placed_at?->format('Y-m-d H:i') }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-zinc-600">Subtotal</dt>
                            <dd class="font-medium text-zinc-900">
                                {{ number_format($order->subtotal_amount / 100, 2, '.', ' ') }} {{ $order->currency }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-zinc-600">Shipping</dt>
                            <dd class="font-medium text-zinc-900">
                                {{ number_format($order->shipping_amount / 100, 2, '.', ' ') }} {{ $order->currency }}
                            </dd>
                        </div>

                        @if ($order->discount_amount > 0)
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-600">Discount</dt>
                                <dd class="font-medium text-zinc-900">
                                    -{{ number_format($order->discount_amount / 100, 2, '.', ' ') }} {{ $order->currency }}
                                </dd>
                            </div>
                        @endif

                        <div class="border-t border-zinc-200 pt-3">
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-base font-semibold text-zinc-900">Total</dt>
                                <dd class="text-base font-bold text-zinc-900">
                                    {{ number_format($order->total_amount / 100, 2, '.', ' ') }} {{ $order->currency }}
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>

                @if ($order->shippingAddress)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Shipping address</h2>

                        <div class="mt-4 space-y-1 text-sm text-zinc-700">
                            @foreach ($order->shippingAddress->formattedLines() as $line)
                                <p>{{ $line }}</p>
                            @endforeach

                            @if ($order->shippingAddress->email)
                                <p class="pt-2 text-zinc-600">{{ $order->shippingAddress->email }}</p>
                            @endif

                            @if ($order->shippingAddress->phone)
                                <p class="text-zinc-600">{{ $order->shippingAddress->phone }}</p>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($order->billingAddress)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Billing address</h2>

                        <div class="mt-4 space-y-1 text-sm text-zinc-700">
                            @foreach ($order->billingAddress->formattedLines() as $line)
                                <p>{{ $line }}</p>
                            @endforeach

                            @if ($order->billingAddress->email)
                                <p class="pt-2 text-zinc-600">{{ $order->billingAddress->email }}</p>
                            @endif

                            @if ($order->billingAddress->phone)
                                <p class="text-zinc-600">{{ $order->billingAddress->phone }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
