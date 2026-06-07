@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="w-full">
                <a
                    href="{{ route('account.orders.index') }}"
                    class="inline-flex items-center text-sm font-medium text-zinc-600 transition hover:text-zinc-900"
                >
                    ← Wróć do zamówień
                </a>

                <div class="mt-3 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <h1 class="text-3xl font-bold tracking-tight text-zinc-900">
                        Zamówienie {{ $order->number }}
                    </h1>

                    <div class="flex flex-wrap items-center gap-3">
                        <a
                            href="{{ route('account.orders.withdrawals.create', $order->id) }}"
                            class="inline-flex items-center rounded-xl border border-zinc-300 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                        >
                            Odstąp od umowy
                        </a>

                        @if ($order->canBeCancelledByCustomer())
                            <form
                                method="POST"
                                action="{{ route('account.orders.cancel', $order->id) }}"
                                data-order-cancel-form
                            >
                                @csrf

                                <button
                                    type="submit"
                                    class="inline-flex items-center rounded-xl border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 transition hover:bg-red-100"
                                >
                                    Anuluj zamówienie
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                <p class="mt-2 text-sm text-zinc-600">
                    Złożono {{ $order->placed_at?->format('Y-m-d H:i') }}
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
                <p class="text-sm text-zinc-500">Status zamówienia</p>
                <div class="mt-3">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->status->badgeColorClasses() }}">
                        {{ $order->status->label() }}
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Status płatności</p>
                <div class="mt-3">
                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->payment_status->badgeColorClasses() }}">
                        {{ $order->payment_status->label() }}
                    </span>
                </div>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-zinc-500">Status realizacji</p>
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
                        <h2 class="text-lg font-semibold text-zinc-900">Pozycje zamówienia</h2>
                    </div>

                    <div class="divide-y divide-zinc-200">
                        @foreach ($order->items as $item)
                            @php
                                $product = $item->product;
                                $variant = $item->variant;

                                $productUrl = $product
                                    ? route('products.show', $product)
                                    : null;

                                $thumbnailUrl = $variant?->main_image_url
                                    ?? $product?->main_image_url
                                    ?? data_get($item->meta, 'image_url');
                            @endphp

                            <div class="px-6 py-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="flex min-w-0 gap-4">
                                        <div class="shrink-0">
                                            @if ($productUrl)
                                                <a href="{{ $productUrl }}" class="block">
                                                    @if ($thumbnailUrl)
                                                        <img
                                                            src="{{ $thumbnailUrl }}"
                                                            alt="{{ $item->product_name_snapshot }}"
                                                            class="h-20 w-20 rounded-xl border border-zinc-200 object-cover transition hover:opacity-80"
                                                        >
                                                    @else
                                                        <div class="flex h-20 w-20 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-100 text-xs text-zinc-500 transition hover:bg-zinc-200">
                                                            Brak zdjęcia
                                                        </div>
                                                    @endif
                                                </a>
                                            @else
                                                @if ($thumbnailUrl)
                                                    <img
                                                        src="{{ $thumbnailUrl }}"
                                                        alt="{{ $item->product_name_snapshot }}"
                                                        class="h-20 w-20 rounded-xl border border-zinc-200 object-cover"
                                                    >
                                                @else
                                                    <div class="flex h-20 w-20 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-100 text-xs text-zinc-500">
                                                        Brak zdjęcia
                                                    </div>
                                                @endif
                                            @endif
                                        </div>

                                        <div class="min-w-0">
                                            <h3 class="text-base font-semibold text-zinc-900">
                                                @if ($productUrl)
                                                    <a
                                                        href="{{ $productUrl }}"
                                                        class="transition hover:text-zinc-600 hover:underline"
                                                    >
                                                        {{ $item->product_name_snapshot }}
                                                    </a>
                                                @else
                                                    {{ $item->product_name_snapshot }}
                                                @endif
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

                                            @if ($item->variant?->attributeValues?->isNotEmpty())
                                                <dl class="mt-3 space-y-1 text-sm text-zinc-600">
                                                    @foreach ($item->variant->attributeValues as $attributeValue)
                                                        <div class="flex gap-2">
                                                            <dt class="font-medium text-zinc-700">
                                                                {{ $attributeValue->attribute?->name ?? 'Option' }}:
                                                            </dt>
                                                            <dd>{{ $attributeValue->value }}</dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="shrink-0 text-sm sm:text-right">
                                        <p class="text-zinc-500">Ilość</p>
                                        <p class="font-medium text-zinc-900">{{ $item->quantity }}</p>
                                    </div>
                                </div>

                                <div class="mt-4 grid gap-4 rounded-xl bg-zinc-50 p-4 text-sm sm:grid-cols-2">
                                    <div>
                                        <p class="text-zinc-500">Cena jednostkowa brutto</p>
                                        <p class="mt-1 font-medium text-zinc-900">
                                            {{ $item->unitGrossDecimal() }} {{ $order->currency }}
                                        </p>

                                        @if ($item->hasTaxBreakdown())
                                            <dl class="mt-2 space-y-1 text-xs text-zinc-600">
                                                <div class="flex justify-between gap-3">
                                                    <dt>Netto</dt>
                                                    <dd class="font-medium text-zinc-800">
                                                        {{ $item->unitNetDecimal() }} {{ $order->currency }}
                                                    </dd>
                                                </div>

                                                <div class="flex justify-between gap-3">
                                                    <dt>VAT {{ $item->vatRateLabel() }}</dt>
                                                    <dd class="font-medium text-zinc-800">
                                                        {{ $item->unitTaxDecimal() }} {{ $order->currency }}
                                                    </dd>
                                                </div>
                                            </dl>
                                        @endif
                                    </div>

                                    <div>
                                        <p class="text-zinc-500">Wartość pozycji brutto</p>
                                        <p class="mt-1 font-semibold text-zinc-900">
                                            {{ $item->lineGrossDecimal() }} {{ $order->currency }}
                                        </p>

                                        @if ($item->hasTaxBreakdown())
                                            <dl class="mt-2 space-y-1 text-xs text-zinc-600">
                                                <div class="flex justify-between gap-3">
                                                    <dt>Netto</dt>
                                                    <dd class="font-medium text-zinc-800">
                                                        {{ $item->lineNetDecimal() }} {{ $order->currency }}
                                                    </dd>
                                                </div>

                                                <div class="flex justify-between gap-3">
                                                    <dt>VAT {{ $item->vatRateLabel() }}</dt>
                                                    <dd class="font-medium text-zinc-800">
                                                        {{ $item->lineTaxDecimal() }} {{ $order->currency }}
                                                    </dd>
                                                </div>
                                            </dl>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                @include('partials.orders.shipment-tracking', ['order' => $order])
                @include('partials.orders.withdrawal-requests', ['order' => $order])

                @if ($order->payments->isNotEmpty())
                    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                        <div class="border-b border-zinc-200 px-6 py-4">
                            <h2 class="text-lg font-semibold text-zinc-900">Płatności</h2>
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
                                                <dt class="text-zinc-500">Opłacono</dt>
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
                            <dt class="text-zinc-600">Numer zamówienia</dt>
                            <dd class="font-medium text-zinc-900">{{ $order->number }}</dd>
                        </div>

                        <div class="flex items-center justify-between gap-4">
                            <dt class="text-zinc-600">Złożono</dt>
                            <dd class="text-right font-medium text-zinc-900">
                                {{ $order->placed_at?->format('Y-m-d H:i') }}
                            </dd>
                        </div>

                        <div class="border-t border-zinc-100 pt-3">
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-600">Produkty brutto</dt>
                                <dd class="font-medium text-zinc-900">
                                    {{ $order->itemsGrossDecimal() }} {{ $order->currency }}
                                </dd>
                            </div>

                            @if ($order->hasTaxBreakdown())
                                <div class="mt-2 space-y-2 rounded-xl bg-zinc-50 p-3 text-xs">
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-zinc-500">Produkty netto</dt>
                                        <dd class="font-medium text-zinc-800">
                                            {{ $order->itemsNetDecimal() }} {{ $order->currency }}
                                        </dd>
                                    </div>

                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-zinc-500">VAT od produktów</dt>
                                        <dd class="font-medium text-zinc-800">
                                            {{ $order->itemsTaxDecimal() }} {{ $order->currency }}
                                        </dd>
                                    </div>
                                </div>
                            @endif
                        </div>

                        <div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-600">Dostawa brutto</dt>
                                <dd class="font-medium text-zinc-900">
                                    {{ $order->shippingGrossDecimal() }} {{ $order->currency }}
                                </dd>
                            </div>

                            @if ($order->hasTaxBreakdown() && ($order->shipping_gross_amount > 0 || $order->shipping_amount > 0))
                                <div class="mt-2 space-y-2 rounded-xl bg-zinc-50 p-3 text-xs">
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-zinc-500">Dostawa netto</dt>
                                        <dd class="font-medium text-zinc-800">
                                            {{ $order->shippingNetDecimal() }} {{ $order->currency }}
                                        </dd>
                                    </div>

                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-zinc-500">VAT od dostawy</dt>
                                        <dd class="font-medium text-zinc-800">
                                            {{ $order->shippingTaxDecimal() }} {{ $order->currency }}
                                        </dd>
                                    </div>
                                </div>
                            @endif
                        </div>

                        @if ($discount = $order->discount_amount)
                            @if ($discount > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-zinc-600">Discount</dt>
                                    <dd class="font-medium text-zinc-900">
                                        -{{ $order->discountDecimal() }} {{ $order->currency }}
                                    </dd>
                                </div>
                            @endif
                        @endif

                        @if ($order->hasTaxBreakdown())
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-zinc-600">VAT razem</dt>
                                <dd class="font-medium text-zinc-900">
                                    {{ $order->taxDecimal() }} {{ $order->currency }}
                                </dd>
                            </div>
                        @endif

                        <div class="border-t border-zinc-200 pt-3">
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-base font-semibold text-zinc-900">Razem brutto</dt>
                                <dd class="text-base font-bold text-zinc-900">
                                    {{ $order->totalDecimal() }} {{ $order->currency }}
                                </dd>
                            </div>
                        </div>
                    </dl>
                </div>

                @if ($order->shippingAddress)
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">Adres dostawy</h2>

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
                        <h2 class="text-lg font-semibold text-zinc-900">Adres rozliczeniowy</h2>

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
