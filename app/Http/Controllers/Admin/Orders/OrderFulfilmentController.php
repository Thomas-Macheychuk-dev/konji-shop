<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Contracts\Delivery\CreatesShipments;
use App\Enums\DeliveryProvider;
use App\Enums\FulfilmentStatus;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Delivery\Polkurier\PolkurierCarrierAvailabilityGuard;
use App\Services\Withdrawals\ProcessWithdrawalRefundService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use App\Models\Shipment;

final class OrderFulfilmentController extends Controller
{
    public function __construct(
        private readonly CreatesShipments $createShipmentService,
        private readonly PolkurierCarrierAvailabilityGuard $polkurierCarrierAvailabilityGuard,
        private readonly ProcessWithdrawalRefundService $processWithdrawalRefundService,
    ) {}

    public function __invoke(Request $request, Order $order, string $action): RedirectResponse
    {
        try {
            match ($action) {
                'processing' => $order->markFulfilmentAsProcessing(),

                'shipped' => $this->shipOrder($request, $order),

                'delivered' => $this->deliverOrder($order),

                'returned' => $this->returnOrderToSender($order),

                'completed' => $order->complete(),

                'refund' => $this->processWithdrawalRefundService->process($order),

                default => throw new DomainException(
                    'Unsupported fulfilment action.'
                ),
            };
        } catch (DomainException|RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            $action === 'refund'
                ? 'Withdrawal refund processed and customer notified.'
                : 'Order fulfilment status updated.'
        );
    }

    private function shipOrder(Request $request, Order $order): void
    {
        if ($order->delivery_service === 'local_pickup') {
            $order->markAsReadyForPickup();

            return;
        }

        $activeShipment = $this->activeShipment($order);

        if ($activeShipment) {
            $this->markExistingShipmentAsShipped($order, $activeShipment);

            return;
        }

        $pickup = $this->polkurierPickupData($request);
        $additionalFields = $this->polkurierAdditionalFields($request, $order);

        $this->createShipmentService->create(
            order: $order,
            provider: $order->delivery_provider->value,
            service: $order->delivery_service,
            lockerCode: $order->delivery_locker_code,
            pickup: $pickup,
            additionalFields: $additionalFields,
        );
    }

    private function activeShipment(Order $order): ?Shipment
    {
        return $order->shipments()
            ->whereNotIn('status', [
                ShipmentStatus::FAILED,
                ShipmentStatus::CANCELLED,
            ])
            ->latest('id')
            ->first();
    }

    private function markExistingShipmentAsShipped(Order $order, Shipment $shipment): void
    {
        if (! $order->fulfilment_status->isProcessing()) {
            throw new DomainException('Only orders in processing can be marked as shipped.');
        }

        if (in_array($shipment->status, [
            ShipmentStatus::PENDING,
            ShipmentStatus::CREATED,
        ], true)) {
            $shipment->markAsDispatched(
                $shipment->tracking_number,
                $shipment->tracking_url,
            );
        }

        $order->markAsShipped();
    }

    private function deliverOrder(Order $order): void
    {
        if (
            $order->delivery_service === 'local_pickup'
            && $order->fulfilment_status === FulfilmentStatus::READY_FOR_PICKUP
        ) {
            $order->markAsPickedUp();
            $order->complete();

            return;
        }

        $order->markAsDelivered();
    }

    private function returnOrderToSender(Order $order): void
    {
        if (! $order->fulfilment_status->isShipped()) {
            throw new DomainException('Only shipped orders can be marked as returned to sender.');
        }

        $shipment = $order->shipments()
            ->latest()
            ->first();

        if ($shipment === null) {
            throw new DomainException('Cannot return an order without a shipment.');
        }

        $shipment->markAsReturnedToSender();

        $order->markAsReturnedToSender();
    }

    /**
     * @return array<string, mixed>
     */
    private function polkurierPickupData(Request $request): array
    {
        $noCourierOrder = ! $request->has('polkurier_no_courier_order')
            || $request->boolean('polkurier_no_courier_order');

        if ($noCourierOrder) {
            return [
                'nocourierorder' => true,
            ];
        }

        $validated = $request->validate([
            'polkurier_pickup_date' => ['required', 'date', 'after_or_equal:today'],
            'polkurier_pickup_time_from' => ['nullable', 'date_format:H:i'],
            'polkurier_pickup_time_to' => ['nullable', 'required_with:polkurier_pickup_time_from', 'date_format:H:i', 'after:polkurier_pickup_time_from'],
        ]);

        $pickup = [
            'pickupdate' => $validated['polkurier_pickup_date'],
            'nocourierorder' => false,
        ];

        if (filled($validated['polkurier_pickup_time_from'] ?? null)) {
            $pickup['pickuptimefrom'] = $validated['polkurier_pickup_time_from'];
        }

        if (filled($validated['polkurier_pickup_time_to'] ?? null)) {
            $pickup['pickuptimeto'] = $validated['polkurier_pickup_time_to'];
        }

        return $pickup;
    }

    /**
     * @return array<string, string>
     */
    private function polkurierAdditionalFields(Request $request, Order $order): array
    {
        if ($order->delivery_provider !== DeliveryProvider::POLKURIER) {
            return [];
        }

        if ($order->delivery_service === 'local_pickup') {
            return [];
        }

        $fieldDefinitions = $this->polkurierCarrierAvailabilityGuard
            ->additionalFieldDefinitions($order);

        if ($fieldDefinitions === []) {
            return [];
        }

        $rules = [
            'polkurier_additional_fields' => ['nullable', 'array'],
        ];

        foreach ($fieldDefinitions as $fieldDefinition) {
            $fieldName = $fieldDefinition['name'] ?? null;

            if (! is_string($fieldName) || trim($fieldName) === '') {
                continue;
            }

            $fieldName = trim($fieldName);
            $fieldRules = [
                ($fieldDefinition['required'] ?? false) === true ? 'required' : 'nullable',
                'string',
                'max:255',
            ];

            $optionValues = $this->additionalFieldOptionValues($fieldDefinition);

            if ($optionValues !== []) {
                $fieldRules[] = Rule::in($optionValues);
            }

            $rules['polkurier_additional_fields.'.$fieldName] = $fieldRules;
        }

        $validated = $request->validate($rules);
        $submittedFields = $validated['polkurier_additional_fields'] ?? [];

        if (! is_array($submittedFields)) {
            return [];
        }

        $additionalFields = [];

        foreach ($fieldDefinitions as $fieldDefinition) {
            $fieldName = $fieldDefinition['name'] ?? null;

            if (! is_string($fieldName) || trim($fieldName) === '') {
                continue;
            }

            $fieldName = trim($fieldName);
            $value = $submittedFields[$fieldName] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $additionalFields[$fieldName] = trim((string) $value);
        }

        return $additionalFields;
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     * @return array<int, string>
     */
    private function additionalFieldOptionValues(array $fieldDefinition): array
    {
        $options = $fieldDefinition['options'] ?? null;

        if (! is_array($options)) {
            return [];
        }

        $values = [];

        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $value = $option['value'] ?? null;

            if (! is_scalar($value) || trim((string) $value) === '') {
                continue;
            }

            $values[] = trim((string) $value);
        }

        return array_values(array_unique($values));
    }
}
