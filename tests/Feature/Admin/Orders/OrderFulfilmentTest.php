<?php

use App\Enums\FulfilmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Models\User;

uses(RefreshDatabase::class);

it('moves a confirmed paid order into fulfilment processing', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $order->markFulfilmentAsProcessing();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::PROCESSING);
});

it('ships a confirmed paid order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
    ]);

    $order->markAsShipped();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::SHIPPED);
});

it('delivers a shipped order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $order->markAsDelivered();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::DELIVERED);
});

it('completes a delivered order', function (): void {
    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::DELIVERED,
    ]);

    $order->complete();

    expect($order->refresh()->status)->toBe(OrderStatus::COMPLETED);
});

it('allows an authenticated user to move an order into fulfilment processing through the admin route', function (): void {
    $user = User::factory()->create([
    'is_admin' => true,
]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'processing']))
        ->assertRedirect();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::PROCESSING);
});

it('redirects back with error for an invalid fulfilment action', function (): void {
    $user = User::factory()->create([
    'is_admin' => true,
]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::UNFULFILLED,
    ]);

    $this->actingAs($user)
        ->from('/admin/orders/'.$order->id)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'invalid-action']))
        ->assertRedirect('/admin/orders/'.$order->id)
        ->assertSessionHas('error');

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::UNFULFILLED);
});

it('allows an authenticated user to ship an order through the admin route', function (): void {
    $user = User::factory()->create([
    'is_admin' => true,
]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::PROCESSING,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'shipped']))
        ->assertRedirect();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::SHIPPED);
});

it('allows an authenticated user to deliver an order through the admin route', function (): void {
    $user = User::factory()->create([
    'is_admin' => true,
]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::SHIPPED,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'delivered']))
        ->assertRedirect();

    expect($order->refresh()->fulfilment_status)->toBe(FulfilmentStatus::DELIVERED);
});

it('allows an authenticated user to complete an order through the admin route', function (): void {
    $user = User::factory()->create([
    'is_admin' => true,
]);

    $order = Order::factory()->create([
        'status' => OrderStatus::CONFIRMED,
        'payment_status' => PaymentStatus::PAID,
        'fulfilment_status' => FulfilmentStatus::DELIVERED,
    ]);

    $this->actingAs($user)
        ->patch(route('admin.orders.fulfilment.update', [$order, 'completed']))
        ->assertRedirect();

    expect($order->refresh()->status)->toBe(OrderStatus::COMPLETED);
});
