<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'number' => 'ORD-' . strtoupper($this->faker->bothify('########')),
            'guest_email' => $this->faker->safeEmail(),
            'status' => OrderStatus::PENDING_PAYMENT,
            'currency' => 'PLN',
            'subtotal_amount' => 10000,
            'shipping_amount' => 0,
            'discount_amount' => 0,
            'total_amount' => 10000,
            'payment_status' => PaymentStatus::UNPAID,
            'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
            'notes' => null,
            'placed_at' => Carbon::now(),
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (): array => [
            'user_id' => $user->id,
            'guest_email' => null,
        ]);
    }

    public function guest(?string $email = null): static
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'guest_email' => $email ?? $this->faker->safeEmail(),
        ]);
    }

    public function unpaid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::PENDING_PAYMENT,
            'payment_status' => PaymentStatus::UNPAID,
        ]);
    }

    public function pendingPayment(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::PENDING_PAYMENT,
            'payment_status' => PaymentStatus::PENDING,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::PAID,
            'payment_status' => PaymentStatus::PAID,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::CANCELLED,
        ]);
    }
}
