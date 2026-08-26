<?php

use App\Services\Payments\Paynow\PaynowSignature;

it('matches the official Paynow v3 signature example', function (): void {
    $signature = PaynowSignature::forRequest(
        apiKey: '97a55694-5478-43b5-b406-fb49ebfdd2b5',
        signatureKey: 'b305b996-bca5-4404-a0b7-2ccea3d2b64b',
        idempotencyKey: 'd243fdb3-c287-484a-bb9c-58536f2794c1',
        body: '',
    );

    expect($signature)->toBe('fXwLZRwo0WiGll90PPl5oULX9VKA0gpFA/3+E+NRp5E=');
});

it('sorts and normalizes query parameters for v3 signing', function (): void {
    $signatureA = PaynowSignature::forRequest(
        apiKey: 'api-key',
        signatureKey: 'signature-key',
        idempotencyKey: 'idem-1',
        body: '{}',
        parameters: ['z' => 'last', 'a' => 'first'],
    );

    $signatureB = PaynowSignature::forRequest(
        apiKey: 'api-key',
        signatureKey: 'signature-key',
        idempotencyKey: 'idem-1',
        body: '{}',
        parameters: ['a' => ['first'], 'z' => ['last']],
    );

    expect($signatureA)->toBe($signatureB);
});
