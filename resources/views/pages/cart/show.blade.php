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

        @if (session('error'))
            <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
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
                            $productUrl = $product?->slug ? route('products.show', $product->slug) : null;
                            $imageUrl = $variant?->main_image_url ?? ($item->meta['image_url'] ?? null);
                            $currentUnitPrice = $item->currentUnitPriceAmount();
                            $currentLineTotal = $item->currentLineTotalAmount();
                            $priceChanged = $currentUnitPrice !== null && (int) $item->unit_price !== $currentUnitPrice;
                            $variantName = $variant?->attributeValues
                                ?->map(function ($attributeValue) {
                                    $attributeName = $attributeValue->attribute?->name;
                                    $value = $attributeValue->value;

                                    if (! $attributeName || ! $value) {
                                        return null;
                                    }

                                    return "{$attributeName}: {$value}";
                                })
                                ->filter()
                                ->implode(', ');
                            $initialQuantity = min(
                                max($item->quantity, \App\Support\Cart\CartLimits::MIN_QUANTITY_PER_LINE),
                                \App\Support\Cart\CartLimits::MAX_QUANTITY_PER_LINE
                            );
                        @endphp

                        <article class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                            <div class="flex flex-col gap-5 sm:flex-row">
                                <div class="w-full shrink-0 sm:w-28">
                                    @if ($productUrl)
                                        <a
                                            href="{{ $productUrl }}"
                                            class="block focus:outline-none focus:ring-2 focus:ring-zinc-400 focus:ring-offset-2 rounded-xl"
                                        >
                                            @if ($imageUrl)
                                                <img
                                                    src="{{ $imageUrl }}"
                                                    alt="{{ $item->meta['product_name'] ?? $product?->name ?? 'Product image' }}"
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
                                                alt="{{ $item->meta['product_name'] ?? $product?->name ?? 'Product image' }}"
                                                class="aspect-square w-full rounded-xl border border-zinc-200 object-cover"
                                            >
                                        @else
                                            <div class="flex aspect-square w-full items-center justify-center rounded-xl border border-zinc-200 bg-zinc-100 text-xs text-zinc-500">
                                                No image
                                            </div>
                                        @endif
                                    @endif
                                </div>

                                <div class="flex-1">
                                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            @if ($productUrl)
                                                <h2 class="text-lg font-semibold text-zinc-900">
                                                    <a
                                                        href="{{ $productUrl }}"
                                                        class="transition hover:text-zinc-700 hover:underline"
                                                    >
                                                        {{ $item->meta['product_name'] ?? $product?->name ?? 'Product' }}
                                                    </a>
                                                </h2>
                                            @else
                                                <h2 class="text-lg font-semibold text-zinc-900">
                                                    {{ $item->meta['product_name'] ?? $product?->name ?? 'Product' }}
                                                </h2>
                                            @endif

                                            @if ($variantName)
                                                <p class="mt-1 text-sm text-zinc-600">
                                                    {{ $variantName }}
                                                </p>
                                            @endif

                                            @if (! $variant)
                                                <p class="mt-2 text-sm font-medium text-red-600">
                                                    This variant is no longer available.
                                                </p>
                                            @elseif ($currentUnitPrice === null)
                                                <p class="mt-2 text-sm font-medium text-red-600">
                                                    Current price is unavailable for this item.
                                                </p>
                                            @elseif ($priceChanged)
                                                <p class="mt-2 text-sm font-medium text-amber-600">
                                                    Price updated since this item was added to your cart.
                                                </p>
                                            @endif
                                        </div>

                                        <div class="text-right">
                                            @if ($currentUnitPrice !== null)
                                                <p class="text-sm text-zinc-500">
                                                    {{ number_format($currentUnitPrice / 100, 2, ',', ' ') }} {{ $variant?->currency?->value ?? $item->currency }}
                                                    each
                                                </p>
                                                <p class="mt-1 text-lg font-semibold text-zinc-900">
                                                    {{ number_format(($currentLineTotal ?? 0) / 100, 2, ',', ' ') }} {{ $variant?->currency?->value ?? $item->currency }}
                                                </p>
                                            @else
                                                <p class="text-sm font-medium text-red-600">
                                                    Price unavailable
                                                </p>
                                            @endif
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
                                                    :disabled="quantity <= {{ \App\Support\Cart\CartLimits::MIN_QUANTITY_PER_LINE }}"
                                                    @click="quantity = Math.max({{ \App\Support\Cart\CartLimits::MIN_QUANTITY_PER_LINE }}, quantity - 1)"
                                                >
                                                    −
                                                </button>

                                                <input
                                                    id="quantity-{{ $item->id }}"
                                                    x-model.number="quantity"
                                                    @input="
                                                        quantity = Number(quantity);
                                                        if (!Number.isFinite(quantity) || quantity < {{ \App\Support\Cart\CartLimits::MIN_QUANTITY_PER_LINE }}) {
                                                            quantity = {{ \App\Support\Cart\CartLimits::MIN_QUANTITY_PER_LINE }};
                                                        }
                                                        if (quantity > {{ \App\Support\Cart\CartLimits::MAX_QUANTITY_PER_LINE }}) {
                                                            quantity = {{ \App\Support\Cart\CartLimits::MAX_QUANTITY_PER_LINE }};
                                                        }
                                                        quantity = Math.floor(quantity);
                                                    "
                                                    type="number"
                                                    name="quantity"
                                                    min="{{ \App\Support\Cart\CartLimits::MIN_QUANTITY_PER_LINE }}"
                                                    max="{{ \App\Support\Cart\CartLimits::MAX_QUANTITY_PER_LINE }}"
                                                    class="h-11 w-16 border-x border-zinc-300 bg-transparent text-center text-sm font-medium text-zinc-900 focus:outline-none [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                                                >

                                                <button
                                                    type="button"
                                                    class="inline-flex h-11 w-11 items-center justify-center text-lg font-semibold text-zinc-700 transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40"
                                                    :disabled="quantity >= {{ \App\Support\Cart\CartLimits::MAX_QUANTITY_PER_LINE }}"
                                                    @click="quantity = Math.min({{ \App\Support\Cart\CartLimits::MAX_QUANTITY_PER_LINE }}, quantity + 1)"
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

                            <div class="flex items-center justify-between">
                                <dt>Shipping</dt>
                                <dd class="font-medium text-zinc-900">
                                    {{ number_format(($shipping ?? 0) / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                </dd>
                            </div>

                            @if (($discount ?? 0) > 0)
                                <div class="flex items-center justify-between">
                                    <dt>Discount</dt>
                                    <dd class="font-medium text-zinc-900">
                                        -{{ number_format($discount / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                    </dd>
                                </div>
                            @endif

                            <div class="border-t border-zinc-200 pt-3">
                                <div class="flex items-center justify-between">
                                    <dt class="text-base font-semibold text-zinc-900">Total</dt>
                                    <dd class="text-base font-semibold text-zinc-900">
                                        {{ number_format(($total ?? $subtotal) / 100, 2, ',', ' ') }} {{ $cart->currency }}
                                    </dd>
                                </div>
                            </div>
                        </dl>

                        <p class="mt-4 text-xs text-zinc-500">
                            Prices in cart reflect current product variant prices.
                        </p>

                        <div class="mt-6 space-y-3">
                            <a
                                href="{{ route('checkout.show') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
                            >
                                Proceed to checkout
                            </a>

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
