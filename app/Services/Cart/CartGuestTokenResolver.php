<?php

declare(strict_types=1);

namespace App\Services\Cart;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class CartGuestTokenResolver
{
    public const COOKIE_NAME = 'konji_cart';

    public function resolve(Request $request): string
    {
        return $request->cookie(self::COOKIE_NAME) ?: (string) Str::uuid();
    }

    public function makeCookie(string $token): Cookie
    {
        return cookie(
            self::COOKIE_NAME,
            $token,
            60 * 24 * 30,
            '/',
            null,
            app()->environment('production'),
            true,
            false,
            'lax'
        );
    }
}
