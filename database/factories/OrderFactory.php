<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\VatRate;
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
        $itemsGross = 10000;
        $shippingGross = 0;
        $discount = 0;

        $items = $this->grossBreakdown($itemsGross);
        $shipping = $this->grossBreakdown($shippingGross);

        return [
            'user_id' => null,
            'number' => 'ORD-'.now()->format('YmdHis').'-'.random_int(1000, 9999),
            'guest_email' => $this->guestEmail(),
            'status' => OrderStatus::PENDING_PAYMENT,
            'currency' => 'PLN',

            'subtotal_amount' => $itemsGross,
            'items_net_amount' => $items['net'],
            'items_tax_amount' => $items['tax'],
            'items_gross_amount' => $itemsGross,

            'shipping_amount' => $shippingGross,
            'shipping_net_amount' => $shipping['net'],
            'shipping_tax_amount' => $shipping['tax'],
            'shipping_gross_amount' => $shippingGross,

            'discount_amount' => $discount,
            'tax_amount' => $items['tax'] + $shipping['tax'],
            'total_amount' => max(0, $itemsGross + $shippingGross - $discount),

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
     * @return array{net: int, tax: int}
     */
    private function grossBreakdown(int $grossAmount): array
    {
        if ($grossAmount <= 0) {
            return [
                'net' => 0,
                'tax' => 0,
            ];
        }

        $net = VatRate::VAT_23->netFromGross($grossAmount);

        return [
            'net' => $net,
            'tax' => max(0, $grossAmount - $net),
        ];
    }

    /**
     * @throws RandomException
     */
    private function guestEmail(): string
    {
        return 'guest+'.random_int(100000, 999999).'@example.test';
    }
}
