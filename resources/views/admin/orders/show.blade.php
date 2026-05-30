@extends('layouts.storefront')

@section('content')
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6 lg:px-8">
        <div class="mb-8 flex items-start justify-between gap-4">
            <div>
                <a href="{{ route('admin.orders.index') }}" class="text-sm font-medium text-zinc-500 hover:text-zinc-700">
                    ← Back to orders
                </a>

                <h1 class="mt-3 text-3xl font-bold tracking-tight text-zinc-900">
                    Order {{ $order->number }}
                </h1>

                <p class="mt-2 text-sm text-zinc-600">
                    Placed at {{ $order->placed_at?->format('Y-m-d H:i') ?? 'not placed' }}
                </p>
            </div>

            <div class="text-right text-sm">
                <p class="font-semibold text-zinc-900">
                    {{ number_format($order->total_amount / 100, 2) }} {{ $order->currency }}
                </p>
                <p class="mt-1 text-zinc-500">Total</p>
            </div>
        </div>

        @if (session('success'))
            <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                {{ session('success') }}
            </div>
        @endif

        @if (session('error'))
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        @php
            $latestFailedShipment = $order->shipments
                ->where('status', \App\Enums\ShipmentStatus::FAILED)
                ->sortByDesc('created_at')
                ->first();

            $latestFailedShipmentMessage = $latestFailedShipment
                ? data_get($latestFailedShipment->payload, 'error.message')
                : null;
        @endphp

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Status</h2>

                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Order</dt>
                        <dd class="font-medium text-zinc-900">{{ $order->status->label() }}</dd>
                    </div>

                    <div>
                        <dt class="text-zinc-500">Payment</dt>
                        <dd class="font-medium text-zinc-900">{{ $order->payment_status->label() }}</dd>
                    </div>

                    <div>
                        <dt class="text-zinc-500">Fulfilment</dt>
                        <dd class="font-medium text-zinc-900">{{ $order->fulfilment_status->label() }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm lg:col-span-2">
                <h2 class="text-lg font-semibold text-zinc-900">Fulfilment actions</h2>

                <div class="mt-4 flex flex-wrap gap-3">
                    @if (
                        $order->status === \App\Enums\OrderStatus::CONFIRMED
                        && $order->fulfilment_status === \App\Enums\FulfilmentStatus::UNFULFILLED
                    )
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'processing']) }}">
                            @csrf
                            @method('PATCH')

                            <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                Start processing
                            </button>
                        </form>
                    @endif

                    @if (
                        $order->status === \App\Enums\OrderStatus::CONFIRMED
                        && $order->fulfilment_status === \App\Enums\FulfilmentStatus::PROCESSING
                    )
                        @if ($latestFailedShipment)
                            <div class="mb-4 w-full rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                                <p class="font-semibold">
                                    Latest shipment creation failed.
                                </p>

                                @if ($latestFailedShipmentMessage)
                                    <p class="mt-1">
                                        {{ $latestFailedShipmentMessage }}
                                    </p>
                                @endif

                                <p class="mt-2 text-xs text-red-700">
                                    You can retry shipment creation after correcting the problem.
                                </p>
                            </div>
                        @endif

                        @if (
                            isset($polkurierCarrierAvailabilityCheck)
                            && $order->delivery_provider === \App\Enums\DeliveryProvider::POLKURIER
                            && $order->delivery_service !== 'local_pickup'
                            && $polkurierCarrierAvailabilityCheck['message']
                        )
                            @php
                                $carrierAvailabilityClasses = match ($polkurierCarrierAvailabilityCheck['severity']) {
                                    'success' => 'border-green-200 bg-green-50 text-green-800',
                                    'warning' => 'border-amber-200 bg-amber-50 text-amber-800',
                                    'error' => 'border-red-200 bg-red-50 text-red-800',
                                    default => 'border-zinc-200 bg-zinc-50 text-zinc-700',
                                };
                            @endphp

                            <div class="mb-4 w-full rounded-2xl border p-4 text-sm {{ $carrierAvailabilityClasses }}">
                                <p class="font-semibold">
                                    Polkurier carrier availability
                                </p>

                                <p class="mt-1">
                                    {{ $polkurierCarrierAvailabilityCheck['message'] }}
                                </p>

                                @if ($polkurierCarrierAvailabilityCheck['blocking'])
                                    <p class="mt-2 text-xs">
                                        Shipment creation is blocked until this is resolved.
                                    </p>
                                @endif
                            </div>
                        @endif

                        <form
                            method="POST"
                            action="{{ route('admin.orders.fulfilment.update', [$order, 'shipped']) }}"
                            class="w-full"
                        >
                            @csrf
                            @method('PATCH')

                            @if ($order->delivery_service !== 'local_pickup')
                                <div
                                    id="admin-polkurier-pickup-selector"
                                    data-pickup-times-url="{{ route('admin.orders.polkurier-pickup-times', $order) }}"
                                    data-initial-no-courier-order="1"
                                ></div>
                            @endif

                            @if (
                                $order->delivery_provider === \App\Enums\DeliveryProvider::POLKURIER
                                && $order->delivery_service !== 'local_pickup'
                                && ! empty($polkurierAdditionalFieldDefinitions ?? [])
                            )
                                <div class="mb-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                    <h3 class="text-sm font-semibold text-amber-900">
                                        Polkurier additional fields
                                    </h3>

                                    <p class="mt-1 text-xs text-amber-800">
                                        This carrier requires extra information before the shipment can be created.
                                    </p>

                                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                                        @foreach ($polkurierAdditionalFieldDefinitions as $field)
                                            @php
                                                $fieldName = (string) ($field['name'] ?? '');
                                                $fieldLabel = (string) ($field['label'] ?? $fieldName);
                                                $fieldDescription = (string) ($field['description'] ?? '');
                                                $fieldType = (string) ($field['type'] ?? 'TEXT');
                                                $fieldRequired = (bool) ($field['required'] ?? false);
                                                $fieldOptions = is_array($field['options'] ?? null) ? $field['options'] : [];
                                                $inputId = 'polkurier_additional_field_'.$fieldName;
                                                $inputName = 'polkurier_additional_fields['.$fieldName.']';
                                                $oldValue = old('polkurier_additional_fields.'.$fieldName);
                                            @endphp

                                            @continue($fieldName === '')

                                            <div>
                                                <label for="{{ $inputId }}" class="block text-xs font-medium text-amber-900">
                                                    {{ $fieldLabel }}

                                                    @if ($fieldRequired)
                                                        <span class="text-red-700">*</span>
                                                    @endif
                                                </label>

                                                @if ($fieldDescription !== '')
                                                    <p class="mt-1 text-xs text-amber-700">
                                                        {{ $fieldDescription }}
                                                    </p>
                                                @endif

                                                @if ($fieldType === 'SELECT' && $fieldOptions !== [])
                                                    <select
                                                        id="{{ $inputId }}"
                                                        name="{{ $inputName }}"
                                                        @required($fieldRequired)
                                                        class="mt-2 block w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm outline-none focus:border-amber-700 focus:ring-4 focus:ring-amber-100"
                                                    >
                                                        <option value="">
                                                            Select option
                                                        </option>

                                                        @foreach ($fieldOptions as $option)
                                                            @php
                                                                $optionValue = (string) ($option['value'] ?? '');
                                                                $optionLabel = (string) ($option['label'] ?? $optionValue);
                                                            @endphp

                                                            <option value="{{ $optionValue }}" @selected((string) $oldValue === $optionValue)>
                                                                {{ $optionLabel }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <input
                                                        id="{{ $inputId }}"
                                                        type="text"
                                                        name="{{ $inputName }}"
                                                        value="{{ $oldValue }}"
                                                        @required($fieldRequired)
                                                        class="mt-2 block w-full rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm text-zinc-900 shadow-sm outline-none focus:border-amber-700 focus:ring-4 focus:ring-amber-100"
                                                    >
                                                @endif

                                                @error('polkurier_additional_fields.'.$fieldName)
                                                <p class="mt-1 text-xs text-red-700">
                                                    {{ $message }}
                                                </p>
                                                @enderror
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            <button
                                class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700 disabled:cursor-not-allowed disabled:opacity-60"
                                @disabled(($polkurierCarrierAvailabilityCheck['blocking'] ?? false) === true)
                            >
                                @if ($order->delivery_service === 'local_pickup')
                                    Mark as ready for pickup
                                @elseif ($latestFailedShipment)
                                    Retry create shipment
                                @else
                                    Create shipment
                                @endif
                            </button>
                        </form>
                    @endif

                    @if (
                        $order->status === \App\Enums\OrderStatus::CONFIRMED
                        && $order->delivery_service === 'local_pickup'
                        && $order->fulfilment_status === \App\Enums\FulfilmentStatus::READY_FOR_PICKUP
                    )
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'delivered']) }}">
                            @csrf
                            @method('PATCH')

                            <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                Mark as picked up & complete
                            </button>
                        </form>
                    @endif

                    @if (
                        $order->status === \App\Enums\OrderStatus::CONFIRMED
                        && $order->delivery_service !== 'local_pickup'
                        && $order->fulfilment_status === \App\Enums\FulfilmentStatus::SHIPPED
                    )
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'delivered']) }}">
                            @csrf
                            @method('PATCH')

                            <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                                Mark as delivered
                            </button>
                        </form>

                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'returned']) }}">
                            @csrf
                            @method('PATCH')

                            <button class="rounded-xl bg-orange-700 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600">
                                Mark as returned to sender
                            </button>
                        </form>
                    @endif

                    @if (
                        $order->status === \App\Enums\OrderStatus::CONFIRMED
                        && $order->fulfilment_status === \App\Enums\FulfilmentStatus::DELIVERED
                    )
                        <form method="POST" action="{{ route('admin.orders.fulfilment.update', [$order, 'completed']) }}">
                            @csrf
                            @method('PATCH')

                            <button class="rounded-xl bg-green-700 px-4 py-2 text-sm font-semibold text-white hover:bg-green-600">
                                Complete order
                            </button>
                        </form>
                    @endif
                </div>

                @if (! $order->status->isCancelled())
                    @if ($order->canBeCancelledByAdmin())
                        <form
                            method="POST"
                            action="{{ route('admin.orders.cancel', $order) }}"
                            class="mt-6 space-y-3 border-t border-zinc-200 pt-6"
                        >
                            @csrf
                            @method('PATCH')

                            <label for="cancel_note" class="block text-sm font-medium text-zinc-700">
                                Cancel order
                            </label>

                            <textarea
                                id="cancel_note"
                                name="note"
                                rows="3"
                                required
                                class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm"
                                placeholder="Reason for cancellation..."
                            ></textarea>

                            <button class="rounded-xl bg-red-700 px-4 py-2 text-sm font-semibold text-white hover:bg-red-600">
                                Cancel order
                            </button>
                        </form>
                    @else
                        <p class="mt-6 border-t border-zinc-200 pt-6 text-sm text-zinc-500">
                            This order can no longer be cancelled.
                        </p>
                    @endif
                @endif
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Customer</h2>

                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Email</dt>
                        <dd class="font-medium text-zinc-900">
                            {{ $order->user?->email ?? $order->guest_email ?? 'Unknown' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-zinc-500">Type</dt>
                        <dd class="font-medium text-zinc-900">
                            {{ $order->isGuestOrder() ? 'Guest' : 'Registered user' }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Delivery choice</h2>

                <dl class="mt-4 space-y-3 text-sm">
                    <div>
                        <dt class="text-zinc-500">Carrier</dt>
                        <dd class="font-medium text-zinc-900">
                            {{ $order->delivery_carrier?->label() ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-zinc-500">Service</dt>
                        <dd class="font-medium text-zinc-900">
                            {{ \App\Enums\DeliveryService::tryFrom((string) $order->delivery_service)?->label() ?? '—' }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-zinc-500">Locker code</dt>
                        <dd class="font-medium text-zinc-900">
                            {{ $order->delivery_locker_code ?: '—' }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">Shipments</h2>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Carrier</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Reference</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Service</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Tracking</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Locker</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Shipped</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Documents</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Actions</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($order->shipments as $shipment)
                        <tr>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $shipment->carrier()?->label() ?? '—' }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $shipment->provider_reference ?: '—' }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ \App\Enums\DeliveryService::tryFrom((string) $shipment->service)?->label() ?? ($shipment->service ?: '—') }}
                            </td>

                            <td class="px-4 py-4 text-sm">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold {{ $shipment->status->badgeColorClasses() }}">
                                    {{ $shipment->status->label() }}
                                </span>

                                @if ($shipment->provider_status_code || $shipment->provider_status_label)
                                    <p class="mt-2 text-xs text-zinc-500">
                                        Polkurier:
                                        <span class="font-medium text-zinc-700">
                                            {{ $shipment->provider_status_label ?: $shipment->provider_status_code }}
                                        </span>

                                        @if ($shipment->provider_status_code && $shipment->provider_status_label)
                                            <span class="text-zinc-400">
                                                ({{ $shipment->provider_status_code }})
                                            </span>
                                        @endif
                                    </p>
                                @endif

                                @if ($shipment->provider_status_updated_at)
                                    <p class="mt-1 text-xs text-zinc-400">
                                        Updated {{ $shipment->provider_status_updated_at->format('Y-m-d H:i') }}
                                    </p>
                                @endif

                                @if ($shipment->status === \App\Enums\ShipmentStatus::FAILED)
                                    @php
                                        $failureMessage = data_get($shipment->payload, 'error.message');
                                    @endphp

                                    @if ($failureMessage)
                                        <p class="mt-2 max-w-xs text-xs text-red-700">
                                            {{ $failureMessage }}
                                        </p>
                                    @endif
                                @endif
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                @if ($shipment->tracking_url)
                                    <a
                                        href="{{ $shipment->tracking_url }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="font-medium text-zinc-900 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700"
                                    >
                                        {{ $shipment->tracking_number ?: 'Track shipment' }}
                                    </a>
                                @else
                                    {{ $shipment->tracking_number ?: '—' }}
                                @endif
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $shipment->locker_code ?: '—' }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $shipment->shipped_at?->format('Y-m-d H:i') ?? '—' }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                @if (
                                    $shipment->provider === \App\Enums\DeliveryProvider::POLKURIER
                                    && $shipment->provider_reference
                                )
                                    <div class="flex flex-col items-start gap-2">
                                        <a
                                            href="{{ route('admin.shipments.label', $shipment) }}"
                                            class="font-medium text-zinc-900 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700"
                                        >
                                            Download label
                                        </a>

                                        <a
                                            href="{{ route('admin.shipments.protocol', $shipment) }}"
                                            class="font-medium text-zinc-900 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700"
                                        >
                                            Download protocol
                                        </a>
                                    </div>
                                @else
                                    —
                                @endif
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                @php
                                    $isPolkurierShipment = $shipment->provider === \App\Enums\DeliveryProvider::POLKURIER
                                        && filled($shipment->provider_reference);

                                    $canCancelShipment = $isPolkurierShipment
                                        && in_array($shipment->status, [
                                            \App\Enums\ShipmentStatus::PENDING,
                                            \App\Enums\ShipmentStatus::CREATED,
                                            \App\Enums\ShipmentStatus::DISPATCHED,
                                        ], true);
                                @endphp

                                @if ($isPolkurierShipment)
                                    <div class="flex flex-col items-start gap-2">
                                        <form method="POST" action="{{ route('admin.shipments.status.refresh', $shipment) }}">
                                            @csrf
                                            @method('PATCH')

                                            <button class="font-medium text-zinc-900 underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700">
                                                Refresh status
                                            </button>
                                        </form>

                                        @if ($canCancelShipment)
                                            <form
                                                method="POST"
                                                action="{{ route('admin.shipments.cancel', $shipment) }}"
                                                onsubmit="return confirm('Cancel this Polkurier shipment?')"
                                            >
                                                @csrf
                                                @method('PATCH')

                                                <button class="font-medium text-red-700 underline decoration-red-300 underline-offset-4 hover:text-red-600">
                                                    Cancel shipment
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-8 text-center text-sm text-zinc-500">
                                No shipments found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-semibold text-zinc-900">Internal notes</h2>

                <div class="mt-4 whitespace-pre-line rounded-xl bg-zinc-50 p-4 text-sm text-zinc-700">
                    {{ $order->notes ?: 'No notes.' }}
                </div>

                <form
                    method="POST"
                    action="{{ route('admin.orders.notes.update', $order) }}"
                    class="mt-4 space-y-3"
                >
                    @csrf
                    @method('PATCH')

                    <textarea
                        name="note"
                        rows="4"
                        class="w-full rounded-xl border border-zinc-300 px-3 py-2 text-sm"
                        placeholder="Add an internal note..."
                    ></textarea>

                    <button class="rounded-xl bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-700">
                        Add note
                    </button>
                </form>
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">Order timeline</h2>

            <div class="mt-4 space-y-4">
                @forelse ($order->events as $event)
                    <div class="border-l-2 border-zinc-200 pl-4">
                        <p class="text-sm font-semibold text-zinc-900">
                            {{ $event->description }}
                        </p>

                        <p class="mt-1 text-xs text-zinc-500">
                            {{ $event->created_at->format('Y-m-d H:i') }}
                        </p>

                        @if ($event->meta)
                            <dl class="mt-2 rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600">
                                @foreach ($event->meta as $key => $value)
                                    <div class="flex justify-between gap-4">
                                        <dt class="font-medium">{{ str($key)->headline() }}</dt>
                                        <dd>{{ is_scalar($value) ? $value : json_encode($value) }}</dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-zinc-500">No timeline events yet.</p>
                @endforelse
            </div>
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">Items</h2>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Product</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">SKU</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Unit price</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Qty</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Total</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($order->items as $item)
                        @php
                            $product = $item->product;
                            $variant = $item->variant;
                            $productUrl = $product ? route('products.show', $product) : null;
                            $thumbnailUrl = $variant?->thumbnail_url
                                ?? $product?->thumbnail_url
                                ?? $variant?->image_url
                                ?? $product?->image_url
                                ?? $variant?->main_image_url
                                ?? $product?->main_image_url
                                ?? data_get($item->meta, 'image_url');
                        @endphp

                        <tr>
                            <td class="px-4 py-4 text-sm">
                                <div class="flex items-center gap-4">
                                    @if ($productUrl)
                                        <a
                                            href="{{ $productUrl }}"
                                            class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50"
                                        >
                                            @if ($thumbnailUrl)
                                                <img
                                                    src="{{ $thumbnailUrl }}"
                                                    alt="{{ $item->product_name_snapshot }}"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                >
                                            @else
                                                <span class="px-2 text-center text-xs text-zinc-400">
                                                    No image
                                                </span>
                                            @endif
                                        </a>
                                    @else
                                        <div class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-zinc-200 bg-zinc-50">
                                            @if ($thumbnailUrl)
                                                <img
                                                    src="{{ $thumbnailUrl }}"
                                                    alt="{{ $item->product_name_snapshot }}"
                                                    class="h-full w-full object-cover"
                                                    loading="lazy"
                                                >
                                            @else
                                                <span class="px-2 text-center text-xs text-zinc-400">
                                                    No image
                                                </span>
                                            @endif
                                        </div>
                                    @endif

                                    <div class="min-w-0">
                                        <p class="font-medium text-zinc-900">
                                            @if ($productUrl)
                                                <a
                                                    href="{{ $productUrl }}"
                                                    class="underline decoration-zinc-300 underline-offset-4 hover:text-zinc-700"
                                                >
                                                    {{ $item->product_name_snapshot }}
                                                </a>
                                            @else
                                                {{ $item->product_name_snapshot }}
                                            @endif
                                        </p>

                                        @if ($item->variant_name_snapshot)
                                            <p class="mt-1 text-xs text-zinc-500">
                                                {{ $item->variant_name_snapshot }}
                                            </p>
                                        @endif
                                    </div>
                                </div>
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $item->sku_snapshot ?: '—' }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                {{ $item->unitPriceDecimal() }} {{ $order->currency }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm text-zinc-700">
                                {{ $item->quantity }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm font-semibold text-zinc-900">
                                {{ $item->lineTotalDecimal() }} {{ $order->currency }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">
                                No items found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            @forelse ($order->addresses as $address)
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
                    <h2 class="text-lg font-semibold text-zinc-900">
                        {{ ucfirst($address->type) }} address
                    </h2>

                    <div class="mt-4 space-y-1 text-sm text-zinc-700">
                        @foreach ($address->formattedLines() as $line)
                            <p>{{ $line }}</p>
                        @endforeach

                        @if ($address->email)
                            <p class="pt-2">{{ $address->email }}</p>
                        @endif

                        @if ($address->phone)
                            <p>{{ $address->phone }}</p>
                        @endif
                    </div>
                </div>
            @empty
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 text-sm text-zinc-500 shadow-sm lg:col-span-2">
                    No addresses found.
                </div>
            @endforelse
        </div>

        <div class="mt-6 rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-zinc-900">Payments</h2>

            <div class="mt-4 overflow-hidden rounded-xl border border-zinc-200">
                <table class="min-w-full divide-y divide-zinc-200">
                    <thead class="bg-zinc-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Provider</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Reference</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500">External status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wide text-zinc-500">Amount</th>
                    </tr>
                    </thead>

                    <tbody class="divide-y divide-zinc-200 bg-white">
                    @forelse ($order->payments as $payment)
                        <tr>
                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->provider ?: '—' }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->provider_reference ?: '—' }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->status->label() }}
                            </td>

                            <td class="px-4 py-4 text-sm text-zinc-700">
                                {{ $payment->external_status ?: '—' }}
                            </td>

                            <td class="px-4 py-4 text-right text-sm font-semibold text-zinc-900">
                                {{ $payment->amountDecimal() }} {{ $payment->currency }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-8 text-center text-sm text-zinc-500">
                                No payments found.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
