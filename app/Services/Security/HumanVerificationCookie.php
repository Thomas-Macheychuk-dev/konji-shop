<?php

declare(strict_types=1);

namespace App\Services\Security;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

final class HumanVerificationCookie
{
    public function isValid(Request $request): bool
    {
        $value = (string) $request->cookie($this->name(), '');

        if ($value === '') {
            return false;
        }

        $parts = explode('.', $value, 3);

        if (count($parts) !== 3 || $parts[0] !== 'v1' || ! ctype_digit($parts[1])) {
            return false;
        }

        $expiresAt = (int) $parts[1];

        if ($expiresAt <= time()) {
            return false;
        }

        return hash_equals($this->signature($expiresAt), $parts[2]);
    }

    public function make(Request $request): Cookie
    {
        $lifetimeMinutes = max(
            1,
            (int) config('traffic_protection.human_cookie.lifetime_minutes', 1440),
        );
        $expiresAt = time() + ($lifetimeMinutes * 60);
        $value = 'v1.'.$expiresAt.'.'.$this->signature($expiresAt);

        return cookie(
            name: $this->name(),
            value: $value,
            minutes: $lifetimeMinutes,
            path: '/',
            domain: config('session.domain'),
            secure: (bool) config('session.secure') || $request->isSecure(),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        );
    }

    private function name(): string
    {
        return (string) config(
            'traffic_protection.human_cookie.name',
            'konji_human_verified',
        );
    }

    private function signature(int $expiresAt): string
    {
        return hash_hmac('sha256', 'human-verified|'.$expiresAt, $this->signingKey());
    }

    private function signingKey(): string
    {
        $key = (string) config('app.key', '');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        return $key;
    }
}
