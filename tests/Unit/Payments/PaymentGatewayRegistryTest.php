<?php

declare(strict_types=1);

use App\Services\Payments\PaymentGatewayRegistry;
use App\Services\Payments\Przelewy24\Przelewy24Gateway;

it('returns the registered gateway for a provider', function (): void {
    $registry = new PaymentGatewayRegistry([
        app(Przelewy24Gateway::class),
    ]);

    $gateway = $registry->for('przelewy24');

    expect($gateway)->toBeInstanceOf(Przelewy24Gateway::class);
});

it('throws for an unsupported provider', function (): void {
    $registry = new PaymentGatewayRegistry([
        app(Przelewy24Gateway::class),
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unsupported payment provider [stripe].');

    $registry->for('stripe');
});
