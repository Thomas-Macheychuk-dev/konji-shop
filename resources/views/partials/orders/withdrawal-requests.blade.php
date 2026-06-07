@if ($order->withdrawalRequests->isNotEmpty())
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
        <div class="border-b border-zinc-200 px-6 py-4">
            <h2 class="text-lg font-semibold text-zinc-900">
                Zgłoszenia odstąpienia od umowy
            </h2>

            <p class="mt-1 text-sm text-zinc-600">
                Zgłoszenia odstąpienia przesłane dla tego zamówienia.
            </p>
        </div>

        <div class="divide-y divide-zinc-200">
            @foreach ($order->withdrawalRequests as $withdrawalRequest)
                <div class="px-6 py-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <p class="font-semibold text-zinc-900">
                                {{ $withdrawalRequest->number }}
                            </p>

                            <p class="mt-1 text-sm text-zinc-600">
                                Zgłoszono {{ $withdrawalRequest->submitted_at?->format('Y-m-d H:i') ?? '—' }}
                            </p>

                            @if ($withdrawalRequest->acknowledged_at)
                                <p class="mt-1 text-sm text-zinc-600">
                                    Potwierdzono {{ $withdrawalRequest->acknowledged_at->format('Y-m-d H:i') }}
                                </p>
                            @endif

                            @if ($withdrawalRequest->refunded_at)
                                <p class="mt-1 text-sm text-zinc-600">
                                    Zwrócono środki {{ $withdrawalRequest->refunded_at->format('Y-m-d H:i') }}
                                </p>
                            @endif
                        </div>

                        <div class="sm:text-right">
                            <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $withdrawalRequest->status->badgeColorClasses() }}">
                                {{ $withdrawalRequest->status->label() }}
                            </span>
                        </div>
                    </div>

                    <div class="mt-4 rounded-xl bg-zinc-50 p-4">
                        <p class="text-sm font-medium text-zinc-900">
                            Wybrane pozycje
                        </p>

                        <div class="mt-3 space-y-3">
                            @foreach ($withdrawalRequest->items as $item)
                                <div class="flex flex-col gap-1 text-sm sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="font-medium text-zinc-900">
                                            {{ $item->product_name_snapshot }}
                                        </p>

                                        @if ($item->variant_name_snapshot)
                                            <p class="text-zinc-600">
                                                {{ $item->variant_name_snapshot }}
                                            </p>
                                        @endif

                                        @if ($item->sku_snapshot)
                                            <p class="text-zinc-500">
                                                SKU: {{ $item->sku_snapshot }}
                                            </p>
                                        @endif
                                    </div>

                                    <div class="text-zinc-700 sm:text-right">
                                        Ilość {{ $item->quantity_requested }} / {{ $item->quantity_ordered }}

                                        @if ($loop->last)
                                            <div class="mt-2 font-medium text-zinc-900">
                                                Kwota zwrotu: {{ $withdrawalRequest->refundAmountDecimal() }} {{ $order->currency }}
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
