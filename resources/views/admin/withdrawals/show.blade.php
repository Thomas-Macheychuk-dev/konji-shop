@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <a
                    href="{{ route('admin.withdrawals.index') }}"
                    class="text-sm font-medium text-zinc-500 hover:text-zinc-700"
                >
                    ← Wróć do odstąpień
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Odstąpienie {{ $withdrawalRequest->number }}
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Zgłoszono {{ $withdrawalRequest->submitted_at?->format('Y-m-d H:i') ?? '—' }}
                </p>
            </div>

            <span class="inline-flex self-start rounded-full px-3 py-1 text-sm font-semibold {{ $withdrawalRequest->status->badgeColorClasses() }}">
                {{ $withdrawalRequest->status->label() }}
            </span>
        </div>

        <div class="grid gap-8 lg:grid-cols-3">
            <div class="space-y-8 lg:col-span-2">
                <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
                    <div class="border-b border-zinc-200 px-6 py-4">
                        <h2 class="text-lg font-semibold text-zinc-900">
                            Wybrane pozycje
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
                                        <p class="text-zinc-500">Żądana ilość</p>
                                        <p class="font-medium text-zinc-900">
                                            {{ $item->quantity_requested }} / {{ $item->quantity_ordered }}
                                        </p>

                                        <p class="mt-3 text-zinc-500">Kwota brutto</p>
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
                        Oświadczenie klienta
                    </h2>

                    <dl class="mt-5 space-y-4 text-sm">
                        <div>
                            <dt class="text-zinc-500">Powód</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->reason ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Wiadomość</dt>
                            <dd class="mt-1 whitespace-pre-line text-zinc-900">
                                {{ $withdrawalRequest->customer_note ?: '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Notatka do zwrotu</dt>
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
                        Szczegóły
                    </h2>

                    <dl class="mt-5 space-y-3 text-sm">
                        <div>
                            <dt class="text-zinc-500">Zamówienie</dt>
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
                            <dt class="text-zinc-500">Imię i nazwisko klienta</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->customer_name }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">E-mail klienta</dt>
                            <dd class="mt-1 break-all font-medium text-zinc-900">
                                {{ $withdrawalRequest->customer_email }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Potwierdzono</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->acknowledged_at?->format('Y-m-d H:i') ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Zwrócono środki</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->refunded_at?->format('Y-m-d H:i') ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">Kwota zwrotu</dt>
                            <dd class="mt-1 font-medium text-zinc-900">
                                {{ $withdrawalRequest->refundAmountDecimal() }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-zinc-500">IP zgłoszenia</dt>
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
