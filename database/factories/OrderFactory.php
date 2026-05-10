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
use Random\RandomException;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @throws RandomException
     */
    public function definition(): array
    {
        return [
            'user_id' => null,
            'number' => 'ORD-'.now()->format('YmdHis').'-'.random_int(1000, 9999),
            'guest_email' => $this->guestEmail(),
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
            'guest_email' => $email ?? $this->guestEmail(),
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
            'status' => OrderStatus::CONFIRMED,
            'payment_status' => PaymentStatus::PAID,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::CANCELLED,
        ]);
    }

    /**
     * @throws RandomException
     */
    private function guestEmail(): string
    {
        return 'guest+'.random_int(100000, 999999).'@example.test';
    }
}
