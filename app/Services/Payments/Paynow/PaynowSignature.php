<?php

declare(strict_types=1);

namespace App\Services\Payments\Paynow;

use InvalidArgumentException;
use JsonException;
use stdClass;

final class PaynowSignature
{
    /**
     * Generate the Paynow API v3 request signature.
     *
     * @param  array<string, scalar|array<int, scalar>>  $parameters
     *
     * @throws JsonException
     */
    public static function forRequest(
        string $apiKey,
        string $signatureKey,
        string $idempotencyKey,
        string $body = '',
        array $parameters = [],
    ): string {
        if ($apiKey === '') {
            throw new InvalidArgumentException('Paynow API key is required.');
        }

        if ($signatureKey === '') {
            throw new InvalidArgumentException('Paynow signature key is required.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Paynow idempotency key is required.');
        }

        $normalizedParameters = [];

        foreach ($parameters as $key => $value) {
            $normalizedParameters[$key] = is_array($value) ? array_values($value) : [$value];
        }

        ksort($normalizedParameters);

        $signaturePayload = json_encode([
            'headers' => [
                'Api-Key' => $apiKey,
                'Idempotency-Key' => $idempotencyKey,
            ],
            'parameters' => $normalizedParameters !== [] ? $normalizedParameters : new stdClass,
            'body' => $body,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return base64_encode(hash_hmac('sha256', $signaturePayload, $signatureKey, true));
    }
}
