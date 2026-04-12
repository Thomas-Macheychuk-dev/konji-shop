@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
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
                            @php
                                $product = $item->product;
                                $variant = $item->productVariant ?? $item->variant ?? null;
                                $productUrl = $product?->slug ? route('products.show', $product->slug) : null;

                                $imageUrl = $variant?->main_image_url
                                    ?? $product?->main_image_url
                                    ?? null;
                            @endphp

                            <article class="border-b border-zinc-100 pb-4 last:border-b-0 last:pb-0">
                                <div class="flex gap-4">
                                    <div class="w-20 shrink-0">
                                        @if ($productUrl)
                                            <a
                                                href="{{ $productUrl }}"
                                                class="block rounded-xl focus:outline-none focus:ring-2 focus:ring-zinc-400 focus:ring-offset-2"
                                            >
                                                @if ($imageUrl)
                                                    <img
                                                        src="{{ $imageUrl }}"
                                                        alt="{{ $item->product_name_snapshot }}"
                                                        class="aspect-square w-full rounded-xl border border-zinc-200 object-cover transition hover:opacity-90"
                                                    >
                                                @else
                                                    <div class="flex aspect-square w-full items-center justify-center rounded-xl border border-zinc-200 bg-zinc-100 text-xs text-zinc-500 transition hover:bg-zinc-200">
                                                        No image
                                                    </div>
                                                @endif
                                            </a>
                                        @else
                                            @if ($imageUrl)
                                                <img
                                                    src="{{ $imageUrl }}"
                                                    alt="{{ $item->product_name_snapshot }}"
                                                    class="aspect-square w-full rounded-xl border border-zinc-200 object-cover"
                                                >
                                            @else
                                                <div class="flex aspect-square w-full items-center justify-center rounded-xl border border-zinc-200 bg-zinc-100 text-xs text-zinc-500">
                                                    No image
                                                </div>
                                            @endif
                                        @endif
                                    </div>

                                    <div class="flex min-w-0 flex-1 items-start justify-between gap-4">
                                        <div class="min-w-0 flex-1">
                                            @if ($productUrl)
                                                <h3 class="text-sm font-medium text-zinc-900">
                                                    <a
                                                        href="{{ $productUrl }}"
                                                        class="transition hover:text-zinc-700 hover:underline"
                                                    >
                                                        {{ $item->product_name_snapshot }}
                                                    </a>
                                                </h3>
                                            @else
                                                <h3 class="text-sm font-medium text-zinc-900">
                                                    {{ $item->product_name_snapshot }}
                                                </h3>
                                            @endif

                                            @if ($item->variant_name_snapshot)
                                                <p class="mt-1 text-sm text-zinc-500">
                                                    {{ $item->variant_name_snapshot }}
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
                                    </div>
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

                            <p>{{ $order->shippingAddress->address_line_1 }}

                            @if ($order->shippingAddress->address_line_2)
                                / {{ $order->shippingAddress->address_line_2 }}
                            @endif</p>

                            <p>
                                {{ $order->shippingAddress->postcode }}
                                {{ $order->shippingAddress->city }}
                            </p>

                            <p>{{ $order->shippingAddress->countryName() }}</p>

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

                            <p>{{ $order->billingAddress->address_line_1 }}

                            @if ($order->billingAddress->address_line_2)
                                / {{ $order->billingAddress->address_line_2 }}
                            @endif</p>

                            <p>
                                {{ $order->billingAddress->postcode }}
                                {{ $order->billingAddress->city }}
                            </p>

                            <p>{{ $order->billingAddress->countryName() }}</p>

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
