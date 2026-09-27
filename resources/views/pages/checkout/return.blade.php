@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="rounded-2xl border {{ $isSuccess ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' }} p-6 shadow-sm">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    @if ($isSuccess)
                        <p class="text-sm font-medium text-green-700">
                            {{ __('checkout.return.success_kicker') }}
                        </p>

                        <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-900">
                            {{ __('checkout.return.success_title') }}
                        </h1>
                    @else
                        <p class="text-sm font-medium text-red-700">
                            {{ __('checkout.return.failure_kicker') }}
                        </p>

                        <h1 class="mt-1 text-3xl font-bold tracking-tight text-zinc-900">
                            {{ __('checkout.return.failure_title') }}
                        </h1>
                    @endif

                    <p class="mt-2 text-sm {{ $isSuccess ? 'text-green-800' : 'text-red-800' }}">
                        {{ $message }}
                    </p>
                </div>

                @if ($order)
                    <div class="rounded-2xl border {{ $isSuccess ? 'border-green-200' : 'border-red-200' }} bg-white px-4 py-3 text-sm shadow-sm">
                        <p class="text-zinc-500">{{ __('checkout.return.order_number') }}</p>
                        <p class="mt-1 font-semibold text-zinc-900">{{ $order->number }}</p>
                    </div>
                @endif
            </div>
        </div>

        @if ($order)
            <div class="mt-8 grid grid-cols-1 gap-8 lg:grid-cols-[1fr_320px]">
                <section class="space-y-6">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">
                            {{ __('checkout.return.status_heading') }}
                        </h2>

                        <dl class="mt-5 grid gap-4 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-zinc-500">{{ __('checkout.return.order') }}</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->status->badgeColorClasses() }}">
                                        {{ $order->status->label() }}
                                    </span>
                                </dd>
                            </div>

                            <div>
                                <dt class="text-zinc-500">{{ __('checkout.return.payment') }}</dt>
                                <dd class="mt-1">
                                    @if ($payment)
                                        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $payment->status->badgeColorClasses() }}">
                                            {{ $payment->status->label() }}
                                        </span>
                                    @else
                                        <span class="font-medium text-zinc-900">—</span>
                                    @endif
                                </dd>
                            </div>

                            <div>
                                <dt class="text-zinc-500">{{ __('checkout.return.fulfilment') }}</dt>
                                <dd class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium {{ $order->fulfilment_status->badgeColorClasses() }}">
                                        {{ $order->fulfilment_status->label() }}
                                    </span>
                                </dd>
                            </div>

                        </dl>
                    </div>

                    @include('partials.orders.shipment-tracking', ['order' => $order])
                </section>

                <aside class="space-y-6">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-semibold text-zinc-900">
                            {{ __('checkout.return.summary') }}
                        </h2>

                        <dl class="mt-5 space-y-3 text-sm text-zinc-700">
                            <div class="flex items-center justify-between gap-4">
                                <dt>{{ __('checkout.return.order_number') }}</dt>
                                <dd class="font-medium text-zinc-900">{{ $order->number }}</dd>
                            </div>

                            @if ($order->placed_at)
                                <div class="flex items-center justify-between gap-4">
                                    <dt>{{ __('checkout.return.order_date') }}</dt>
                                    <dd class="text-right font-medium text-zinc-900">
                                        {{ $order->placed_at->format('Y-m-d H:i') }}
                                    </dd>
                                </div>
                            @endif

                            <div class="border-t border-zinc-100 pt-3">
                                <div class="flex items-center justify-between gap-4">
                                    <dt>{{ __('checkout.return.items_gross') }}</dt>
                                    <dd class="font-medium text-zinc-900">
                                        {{ $order->itemsGrossDecimal() }} {{ $order->currency }}
                                    </dd>
                                </div>

                                @if ($order->hasTaxBreakdown())
                                    <div class="mt-2 space-y-2 rounded-xl bg-zinc-50 p-3 text-xs">
                                        <div class="flex items-center justify-between gap-4">
                                            <dt class="text-zinc-500">{{ __('checkout.return.items_net') }}</dt>
                                            <dd class="font-medium text-zinc-800">
                                                {{ $order->itemsNetDecimal() }} {{ $order->currency }}
                                            </dd>
                                        </div>

                                        <div class="flex items-center justify-between gap-4">
                                            <dt class="text-zinc-500">{{ __('checkout.return.items_vat') }}</dt>
                                            <dd class="font-medium text-zinc-800">
                                                {{ $order->itemsTaxDecimal() }} {{ $order->currency }}
                                            </dd>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            <div>
                                <div class="flex items-center justify-between gap-4">
                                    <dt>{{ __('checkout.return.shipping_gross') }}</dt>
                                    <dd class="font-medium text-zinc-900">
                                        {{ $order->shippingGrossDecimal() }} {{ $order->currency }}
                                    </dd>
                                </div>

                                @if ($order->hasTaxBreakdown() && ($order->shipping_gross_amount > 0 || $order->shipping_amount > 0))
                                    <div class="mt-2 space-y-2 rounded-xl bg-zinc-50 p-3 text-xs">
                                        <div class="flex items-center justify-between gap-4">
                                            <dt class="text-zinc-500">{{ __('checkout.return.shipping_net') }}</dt>
                                            <dd class="font-medium text-zinc-800">
                                                {{ $order->shippingNetDecimal() }} {{ $order->currency }}
                                            </dd>
                                        </div>

                                        <div class="flex items-center justify-between gap-4">
                                            <dt class="text-zinc-500">{{ __('checkout.return.shipping_vat') }}</dt>
                                            <dd class="font-medium text-zinc-800">
                                                {{ $order->shippingTaxDecimal() }} {{ $order->currency }}
                                            </dd>
                                        </div>
                                    </div>
                                @endif
                            </div>

                            @if ($order->discount_amount > 0)
                                <div class="flex items-center justify-between gap-4">
                                    <dt>{{ __('checkout.return.discount') }}</dt>
                                    <dd class="font-medium text-zinc-900">
                                        -{{ $order->discountDecimal() }} {{ $order->currency }}
                                    </dd>
                                </div>
                            @endif

                            @if ($order->hasTaxBreakdown())
                                <div class="flex items-center justify-between gap-4">
                                    <dt>{{ __('checkout.return.vat_total') }}</dt>
                                    <dd class="font-medium text-zinc-900">
                                        {{ $order->taxDecimal() }} {{ $order->currency }}
                                    </dd>
                                </div>
                            @endif

                            <div class="border-t border-zinc-200 pt-3">
                                <div class="flex items-center justify-between gap-4">
                                    <dt class="text-base font-semibold text-zinc-900">{{ __('checkout.return.total_gross') }}</dt>
                                    <dd class="text-base font-semibold text-zinc-900">
                                        {{ $order->totalDecimal() }} {{ $order->currency }}
                                    </dd>
                                </div>
                            </div>
                        </dl>
                    </div>

                    @include('partials.orders.payment-retry', ['order' => $order])

                    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                        <div class="space-y-3">
                            @auth
                                <a
                                    href="{{ route('account.orders.show', $order) }}"
                                    class="inline-flex w-full items-center justify-center rounded-xl bg-zinc-900 px-5 py-3 text-sm font-semibold text-white transition hover:bg-zinc-800"
                                >
                                    {{ __('checkout.return.view_order_details') }}
                                </a>
                            @endauth

                            <a
                                href="{{ route('home') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                            >
                                {{ __('checkout.return.back_to_shop') }}
                            </a>

                            <a
                                href="{{ route('guest.orders.track.show') }}"
                                class="inline-flex w-full items-center justify-center rounded-xl border border-zinc-300 px-5 py-3 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                            >
                                {{ __('checkout.return.track_order') }}
                            </a>
                        </div>
                    </div>
                </aside>
            </div>
        @else
            <div class="mt-12 flex flex-col justify-center gap-4 sm:flex-row">
                <a
                    href="{{ route('home') }}"
                    class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-8 py-4 text-sm font-semibold text-white transition hover:bg-zinc-800"
                >
                    {{ __('checkout.return.back_to_shop') }}
                </a>

                <a
                    href="{{ route('guest.orders.track.show') }}"
                    class="inline-flex items-center justify-center rounded-xl border border-zinc-300 px-8 py-4 text-sm font-semibold text-zinc-700 transition hover:bg-zinc-50"
                >
                    {{ __('checkout.return.track_order') }}
                </a>
            </div>
        @endif
    </div>
@endsection
