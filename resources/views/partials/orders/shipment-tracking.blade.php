@php
    $shipments = collect($order->shipments ?? [])
        ->sortByDesc('created_at')
        ->values();
@endphp

@if ($shipments->isNotEmpty())
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm">
        <div class="border-b border-zinc-200 px-6 py-4">
            <h2 class="text-lg font-semibold text-zinc-900">
                Śledzenie przesyłki
            </h2>

            <p class="mt-1 text-sm text-zinc-500">
                Śledź status dostawy i szczegóły przesyłki dla tego zamówienia.
            </p>
        </div>

        <div class="divide-y divide-zinc-200">
            @foreach ($shipments as $shipment)
                <div class="px-6 py-5">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-semibold text-zinc-900">
                                    {{ $shipment->carrier()?->label() ?? ucfirst((string) ($shipment->provider?->value ?? 'Dostawa')) }}
                                </p>

                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $shipment->status->badgeColorClasses() }}">
                                    {{ $shipment->status->label() }}
                                </span>
                            </div>

                            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-zinc-500">Usługa</dt>
                                    <dd class="mt-1 font-medium text-zinc-900">
                                        {{ \App\Enums\DeliveryService::tryFrom((string) $shipment->service)?->label() ?? ($shipment->service ?: '—') }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-zinc-500">Numer śledzenia</dt>
                                    <dd class="mt-1 break-all font-medium text-zinc-900">
                                        {{ $shipment->tracking_number ?: '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-zinc-500">Referencja</dt>
                                    <dd class="mt-1 break-all font-medium text-zinc-900">
                                        {{ $shipment->provider_reference ?: '—' }}
                                    </dd>
                                </div>

                                <div>
                                    <dt class="text-zinc-500">Kod paczkomatu</dt>
                                    <dd class="mt-1 font-medium text-zinc-900">
                                        {{ $shipment->locker_code ?: '—' }}
                                    </dd>
                                </div>

                                @if ($shipment->shipped_at)
                                    <div>
                                        <dt class="text-zinc-500">Wysłano</dt>
                                        <dd class="mt-1 font-medium text-zinc-900">
                                            {{ $shipment->shipped_at->format('Y-m-d H:i') }}
                                        </dd>
                                    </div>
                                @endif

                                @if ($shipment->delivered_at)
                                    <div>
                                        <dt class="text-zinc-500">Dostarczono</dt>
                                        <dd class="mt-1 font-medium text-zinc-900">
                                            {{ $shipment->delivered_at->format('Y-m-d H:i') }}
                                        </dd>
                                    </div>
                                @endif
                            </dl>
                        </div>

                        @if ($shipment->tracking_url)
                            <div class="shrink-0">
                                <a
                                    href="{{ $shipment->tracking_url }}"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center justify-center rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white transition hover:bg-zinc-700"
                                >
                                    Śledź przesyłkę
                                </a>
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
