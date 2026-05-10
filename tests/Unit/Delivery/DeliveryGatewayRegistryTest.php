<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use App\Contracts\Delivery\DeliveryGateway;
use App\Data\Delivery\ShipmentCreationResult;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\Delivery\DeliveryGatewayRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DeliveryGatewayRegistryTest extends TestCase
{
    public function test_it_returns_the_registered_gateway_for_a_provider(): void
    {
        $gateway = new class implements DeliveryGateway {
            public function providerKey(): string
            {
                return 'inpost';
            }

            public function createShipment(Order $order, Shipment $shipment): ShipmentCreationResult
            {
                return new ShipmentCreationResult(
                    provider: 'inpost',
                    providerReference: 'shipment-123',
                );
            }
        };

        $registry = new DeliveryGatewayRegistry([
            $gateway,
        ]);

        $this->assertSame($gateway, $registry->for('inpost'));
    }

    public function test_it_throws_for_an_unsupported_provider(): void
    {
        $registry = new DeliveryGatewayRegistry([]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported delivery provider [unknown].');

        $registry->for('unknown');
    }
}
