@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border border-green-200 bg-green-50 p-6 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-medium text-green-700">Order placed successfully</p>
                    <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-900">
                        Thank you for your order
                    </h1>
                    <p class="mt-2 text-sm text-zinc-700">
                        Your order has been received and is now awaiting the next step.
                    </p>
                </div>

                <div class="rounded-2xl border border-green-200 bg-white px-4 py-3 text-sm shadow-sm">
                    <p class="text-zinc-500">Order number</p>
                    <p class="mt-1 font-semibold text-zinc-900">{{ $order->number }}</p>
                </div>
            </div>
        </div>

        <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-[1fr_320px]">
            <section class="space-y-6">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">Order items</h2>

                    <div class="mt-5 space-y-4">
                        @foreach ($order->items as $item)
                            <article class="flex items-start justify-between gap-4 border-b border-zinc-100 pb-4 last:border-b-0 last:pb-0">
                                <div class="min-w-0 flex-1">
                                    <h3 class="text-sm font-medium text-zinc-900">
                                        {{ $item->product_name_snapshot }}
                                    </h3>

                                    @if ($item->variant_name_snapshot)
                                        <p class="mt-1 text-sm text-zinc-500">
                                            Variant: {{ $item->variant_name_snapshot }}
                                        </p>
                                    @endif

                                    @if ($item->sku_snapshot)
                                        <p class="mt-1 text-xs text-zinc-400">
                                            SKU: {{ $item->sku_snapshot }}
                                        </p>
                                    @endif

                                    <p class="mt-2 text-xs text-zinc-500">
                                        Quantity: {{ $item->quantity }}
                                    </p>
                                </div>

                                <div class="text-right">
                                    <p class="text-sm text-zinc-500">
                                        {{ number_format($item->unit_price_amount / 100, 2, ',', ' ') }} {{ $order->currency }}
                                        each
                                    </p>
                                    <p class="mt-1 text-sm font-semibold text-zinc-900">
                                        {{ number_format($item->line_total_amount / 100, 2, ',', ' ') }} {{ $order->currency }}
                                    </p>
                                </div>
                            </article>
                        @endforeach
                    </div>
                </div>

                @if ($order->notes)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Order notes</h2>
                        <p class="mt-4 whitespace-pre-line text-sm text-zinc-700">{{ $order->notes }}</p>
                    </div>
                @endif
            </section>

            <aside class="space-y-6">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">Order summary</h2>

                    <dl class="mt-5 space-y-3 text-sm text-zinc-700">
                        <div class="flex items-center justify-between">
                            <dt>Status</dt>
                            <dd class="font-medium text-zinc-900">{{ $order->status->label() }}</dd>
                        </div>

                        <div class="flex items-center justify-between">
                            <dt>Payment</dt>
                            <dd class="font-medium text-zinc-900">{{ $order->payment_status->label() }}</dd>
                        </div>

                        <div class="flex items-center justify-between">
                            <dt>Fulfilment</dt>
                            <dd class="font-medium text-zinc-900">{{ $order->fulfilment_status->label() }}</dd>
                        </div>

                        @if ($order->placed_at)
                            <div class="flex items-center justify-between">
                                <dt>Placed at</dt>
                                <dd class="font-medium text-zinc-900">{{ $order->placed_at->format('Y-m-d H:i') }}</dd>
                            </div>
                        @endif

                        <div class="flex items-center justify-between">
                            <dt>Subtotal</dt>
                            <dd class="font-medium text-zinc-900">
                                {{ number_format($order->subtotal_amount / 100, 2, ',', ' ') }} {{ $order->currency }}
                            </dd>
                        </div>

                        <div class="flex items-center justify-between">
                            <dt>Shipping</dt>
                            <dd class="font-medium text-zinc-900">
                                {{ number_format($order->shipping_amount / 100, 2, ',', ' ') }} {{ $order->currency }}
                            </dd>
                        </div>

                        @if ($order->discount_amount > 0)
                            <div class="flex items-center justify-between">
                                <dt>Discount</dt>
                                <dd class="font-medium text-zinc-900">
                                    -{{ number_format($order->discount_amount / 100, 2, ',', ' ') }} {{ $order->currency }}
                                </dd>
                            </div>
                        @endif

                        <div class="border-t border-zinc-200 pt-3">
                            <div class="flex items-center justify-between">
                                <dt class="text-base font-semibold text-zinc-900">Total</dt>
                                <dd class="text-base font-semibold text-zinc-900">
                                    {{ number_format($order->total_amount / 100, 2, ',', ' ') }} {{ $order->currency }}
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>

                @if ($order->shippingAddress)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Shipping address</h2>

                        <div class="mt-4 space-y-1 text-sm text-zinc-700">
                            <p class="font-medium text-zinc-900">{{ $order->shippingAddress->fullName() }}</p>

                            @if ($order->shippingAddress->company)
                                <p>{{ $order->shippingAddress->company }}</p>
                            @endif

                            <p>{{ $order->shippingAddress->address_line_1 }}</p>

                            @if ($order->shippingAddress->address_line_2)
                                <p>{{ $order->shippingAddress->address_line_2 }}</p>
                            @endif

                            <p>
                                {{ $order->shippingAddress->postcode }}
                                {{ $order->shippingAddress->city }}
                            </p>

                            <p>{{ $order->shippingAddress->country_code }}</p>

                            <div class="pt-2 text-zinc-500">
                                <p>{{ $order->shippingAddress->email }}</p>
                                <p>{{ $order->shippingAddress->phone }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                @if ($order->billingAddress)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Billing address</h2>

                        <div class="mt-4 space-y-1 text-sm text-zinc-700">
                            <p class="font-medium text-zinc-900">{{ $order->billingAddress->fullName() }}</p>

                            @if ($order->billingAddress->company)
                                <p>{{ $order->billingAddress->company }}</p>
                            @endif

                            <p>{{ $order->billingAddress->address_line_1 }}</p>

                            @if ($order->billingAddress->address_line_2)
                                <p>{{ $order->billingAddress->address_line_2 }}</p>
                            @endif

                            <p>
                                {{ $order->billingAddress->postcode }}
                                {{ $order->billingAddress->city }}
                            </p>

                            <p>{{ $order->billingAddress->country_code }}</p>

                            <div class="pt-2 text-zinc-500">
                                <p>{{ $order->billingAddress->email }}</p>
                                <p>{{ $order->billingAddress->phone }}</p>
                            </div>
                        </div>
                    </div>
                @endif

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <div class="space-y-3">
                        <a
                            href="{{ route('home') }}"
                            class="inline-flex w-full items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
                        >
                            Continue shopping
                        </a>

                        <a
                            href="{{ route('cart.show') }}"
                            class="inline-flex w-full items-center justify-center rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                        >
                            Back to cart
                        </a>
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
