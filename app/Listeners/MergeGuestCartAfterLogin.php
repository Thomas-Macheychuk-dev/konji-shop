<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartService;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

class MergeGuestCartAfterLogin
{
    public function __construct(
        private readonly Request $request,
        private readonly CartService $cartService,
    ) {
    }

    public function handle(Login $event): void
    {
        $guestToken = $this->request->cookie(CartGuestTokenResolver::COOKIE_NAME);

        if (! $guestToken) {
            return;
        }

        $this->cartService->mergeGuestCartIntoUserCart(
            $guestToken,
            $event->user
        );
    }
}
