<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => null,
            'provider_reference' => null,
            'status' => PaymentStatus::UNPAID,
            'amount' => 10000,
            'currency' => 'PLN',
            'paid_at' => null,
            'payload' => null,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'order_id' => $order->id,
            'amount' => $order->total_amount,
            'currency' => $order->currency,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::PENDING,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::PAID,
            'paid_at' => now(),
        ]);
    }

    public function przelewy24(?string $reference = null): static
    {
        return $this->state(fn (): array => [
            'provider' => 'przelewy24',
            'provider_reference' => $reference ?? 'fake-p24-' . fake()->numerify('######'),
        ]);
    }
}
