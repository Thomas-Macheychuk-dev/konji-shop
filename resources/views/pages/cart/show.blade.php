@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8">
            <h1 class="text-3xl font-bold tracking-tight text-zinc-900">Shopping Cart</h1>
            <p class="mt-2 text-sm text-zinc-600">
                Review your selected products before checkout.
            </p>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-2xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (! $cart || $cart->items->isEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm">
                <p class="text-zinc-700">Your cart is empty.</p>

                <div class="mt-4">
                    <a
                        href="{{ route('home') }}"
                        class="inline-flex items-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
                    >
                        Continue shopping
                    </a>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-[1fr_380px]">
                <section class="space-y-4">
                    @foreach ($cart->items as $item)
                        @php
                            $product = $item->product;
                            $variant = $item->variant;
                            $imageUrl = $item->meta['image_url'] ?? $product?->mainImage?->url;
                            $lineTotal = $item->unit_price * $item->quantity;
                            $initialQuantity = min(max($item->quantity, 1), 50);
                        @endphp

                        <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-col gap-5 sm:flex-row">
                                <div class="w-full shrink-0 sm:w-28">
                                    @if ($imageUrl)
                                        <img
                                            src="{{ $imageUrl }}"
                                            alt="{{ $item->meta['product_name'] ?? $product?->name ?? 'Product image' }}"
                                            class="aspect-square w-full rounded-xl border border-zinc-200 object-cover"
                                        >
                                    @else
                                        <div class="flex aspect-square w-full items-center justify-center rounded-xl border border-zinc-200 bg-zinc-100 text-xs text-zinc-500">
                                            No image
                                        </div>
                                    @endif
                                </div>

                                <div class="flex-1">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h2 class="text-lg font-semibold text-zinc-900">
                                                {{ $item->meta['product_name'] ?? $product?->name ?? 'Product' }}
                                            </h2>

                                            @if (!empty($item->meta['variant_name']))
                                                <p class="mt-1 text-sm text-zinc-600">
                                                    Variant: {{ $item->meta['variant_name'] }}
                                                </p>
                                            @endif
                                        </div>

                                        <div class="text-right">
                                            <p class="text-sm text-zinc-500">
                                                {{ number_format($item->unit_price / 100, 2, ',', ' ') }} {{ $item->currency }}
                                                each
                                            </p>
                                            <p class="mt-1 text-lg font-semibold text-zinc-900">
                                                {{ number_format($lineTotal / 100, 2, ',', ' ') }} {{ $item->currency }}
                                            </p>
                                        </div>
                                    </div>

                                    <div class="mt-4 flex flex-wrap items-center gap-3">
                                        <form
                                            method="POST"
                                            action="{{ route('cart.items.update', $item) }}"
                                            class="flex flex-wrap items-center gap-3"
                                            x-data="{ quantity: {{ $initialQuantity }} }"
                                        >
                                            @csrf
                                            @method('PATCH')

                                            <label
                                                for="quantity-{{ $item->id }}"
                                                class="text-sm text-zinc-600"
                                            >
                                                Quantity
                                            </label>

                                            <div class="flex items-center rounded-xl border border-zinc-300 bg-white shadow-sm">
                                                <button
                                                    type="button"
                                                    class="inline-flex h-11 w-11 items-center justify-center text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
                                                    :disabled="quantity <= 1"
                                                    @click="quantity = Math.max(1, quantity - 1)"
                                                >
                                                    −
                                                </button>

                                                <input
                                                    id="quantity-{{ $item->id }}"
                                                    x-model.number="quantity"
                                                    @input="
                                                        quantity = Number(quantity);
                                                        if (!Number.isFinite(quantity) || quantity < 1) quantity = 1;
                                                        if (quantity > 50) quantity = 50;
                                                        quantity = Math.floor(quantity);
                                                    "
                                                    type="number"
                                                    name="quantity"
                                                    min="1"
                                                    max="50"
                                                    class="h-11 w-16 border-x border-zinc-300 bg-transparent text-center text-sm font-medium text-zinc-900 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                                >

                                                <button
                                                    type="button"
                                                    class="inline-flex h-11 w-11 items-center justify-center text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
                                                    :disabled="quantity >= 50"
                                                    @click="quantity = Math.min(50, quantity + 1)"
                                                >
                                                    +
                                                </button>
                                            </div>

                                            <button
                                                type="submit"
                                                class="inline-flex h-11 items-center justify-center rounded-xl border border-zinc-300 px-4 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                            >
                                                Update
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="{{ route('cart.items.destroy', $item) }}"
                                        >
                                            @csrf
                                            @method('DELETE')

                                            <button
                                                type="submit"
                                                class="inline-flex h-11 items-center justify-center rounded-xl border border-red-200 px-4 text-sm font-medium text-red-700 transition hover:bg-red-50"
                                            >
                                                Remove
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </section>

                <aside>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Order Summary</h2>

                        <dl class="mt-5 space-y-3 text-sm text-zinc-700">
                            <div class="flex items-center justify-between">
                                <dt>Items</dt>
                                <dd>{{ $cart->items->sum('quantity') }}</dd>
                            </div>

                            <div class="flex items-center justify-between">
                                <dt>Subtotal</dt>
                                <dd class="font-medium text-zinc-900">
                                    {{ number_format($subtotal / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-6 space-y-3">
                            <button
                                type="button"
                                disabled
                                class="inline-flex w-full cursor-not-allowed items-center justify-center rounded-xl bg-zinc-200 px-5 py-3 text-sm font-semibold text-zinc-500"
                            >
                                Checkout coming soon
                            </button>

                            <a
                                href="{{ route('home') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                            >
                                Continue shopping
                            </a>
                        </div>
                    </div>
                </aside>
            </div>
        @endif
    </div>
@endsection
