@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a
                    href="{{ route('admin.withdrawals.index') }}"
                    class="text-sm font-medium text-zinc-500 hover:text-zinc-700"
                >
                    ← Back to withdrawals
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Withdrawal {{ $withdrawalRequest->number }}
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Submitted at {{ $withdrawalRequest->submitted_at?->format('Y-m-d H:i') ?? '—' }}
                </p>
            </div>

            <span class="inline-flex self-start rounded-full px-3 py-1 text-sm font-semibold {{ $withdrawalRequest->status->badgeColorClasses() }}">
                {{ \Illuminate\Support\Str::headline($withdrawalRequest->status->value) }}
            </span>
        </div>

        <div class="grid gap-8 lg:grid-cols-3">
            <div class="space-y-8 lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="border-b border-zinc-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-zinc-900">
                            Selected items
                        </h2>
                    </div>

                    <div class="divide-y divide-zinc-200">
                        @foreach ($withdrawalRequest->items as $item)
                            <div class="px-6 py-5">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <h3 class="font-semibold text-zinc-900">
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

                                    <div class="text-sm sm:text-right">
                                        <p class="text-zinc-500">Quantity requested</p>
                                        <p class="font-medium text-zinc-900">
                                            {{ $item->quantity_requested }} / {{ $item->quantity_ordered }}
                                        </p>

                                        <p class="mt-3 text-zinc-500">Amount gross</p>
                                        <p class="font-semibold text-zinc-900">
                                            {{ $item->lineGrossDecimal() }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Customer statement
                    </h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="text-zinc-500">Reason</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->reason ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Message</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->customer_note ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Refund note</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->refund_note ?: '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="space-y-8">
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        Details
                    </h2>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Order</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                @if ($withdrawalRequest->order)
                                    <a
                                        href="{{ route('admin.orders.show', $withdrawalRequest->order) }}"
                                        class="hover:underline"
                                    >
                                        {{ $withdrawalRequest->order_number_snapshot }}
                                    </a>
                                @else
                                    {{ $withdrawalRequest->order_number_snapshot }}
                                @endif
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Customer name</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->customer_name }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Customer email</dt>
                            <dd class="mt-1 break-all font-medium text-zinc-900">
                                {{ $withdrawalRequest->customer_email }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Acknowledged at</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->acknowledged_at?->format('Y-m-d H:i') ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Refunded at</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->refunded_at?->format('Y-m-d H:i') ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Refund amount</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->refundAmountDecimal() }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Submission IP</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->submission_ip ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">User agent</dt>
                            <dd class="mt-1 break-words font-medium text-zinc-900">
                                {{ $withdrawalRequest->submission_user_agent ?: '—' }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>
    </div>
@endsection
