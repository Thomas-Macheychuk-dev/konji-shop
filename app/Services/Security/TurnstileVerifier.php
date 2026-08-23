<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TurnstileVerifier
{
    public function verify(string $token, string $ip): bool
    {
        $secret = trim((string) config('traffic_protection.turnstile.secret_key'));

        if ($secret === '' || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout(max(1, (int) config('traffic_protection.turnstile.timeout_seconds', 10)))
                ->retry(
                    max(1, (int) config('traffic_protection.turnstile.attempts', 2)),
                    250,
                    static fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                )
                ->post((string) config('traffic_protection.turnstile.siteverify_url'), [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Turnstile verification request failed.', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        if (! $response->successful()) {
            Log::warning('Turnstile verification returned a non-success HTTP status.', [
                'status' => $response->status(),
            ]);

            return false;
        }

        $result = $response->json();

        if (! is_array($result) || ($result['success'] ?? false) !== true) {
            return false;
        }

        $expectedAction = trim((string) config('traffic_protection.turnstile.action'));

        if ($expectedAction !== '' && (string) ($result['action'] ?? '') !== $expectedAction) {
            return false;
        }

        $allowedHostnames = config('traffic_protection.turnstile.allowed_hostnames', []);

        if (is_array($allowedHostnames) && $allowedHostnames !== []) {
            $hostname = mb_strtolower(trim((string) ($result['hostname'] ?? '')));
            $allowedHostnames = array_map(
                static fn (mixed $allowed): string => mb_strtolower(trim((string) $allowed)),
                $allowedHostnames,
            );

            if ($hostname === '' || ! in_array($hostname, $allowedHostnames, true)) {
                return false;
            }
        }

        return true;
    }
}
