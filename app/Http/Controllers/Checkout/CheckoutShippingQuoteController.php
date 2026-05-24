<?php

declare(strict_types=1);

namespace App\Http\Controllers\Checkout;

use App\Enums\DeliveryCarrier;
use App\Enums\DeliveryProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutShippingQuoteRequest;
use App\Models\Cart;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartService;
use App\Services\Delivery\Polkurier\PolkurierPackBuilder;
use App\Services\Delivery\Polkurier\PolkurierShippingQuoteService;
use Illuminate\Http\JsonResponse;

final class CheckoutShippingQuoteController extends Controller
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly PolkurierPackBuilder $packBuilder,
        private readonly PolkurierShippingQuoteService $shippingQuoteService,
    ) {}

    public function __invoke(CheckoutShippingQuoteRequest $request): JsonResponse
    {
        $cart = $this->activeCart($request);
        $packs = $cart === null ? [] : $this->packBuilder->fromCart($cart);

        $quote = $this->shippingQuoteService->quote(
            provider: DeliveryProvider::from((string) $request->input('delivery_provider')),
            carrier: DeliveryCarrier::from((string) $request->input('delivery_carrier')),
            service: (string) $request->input('delivery_service'),
            shippingAddress: $request->shippingAddressData(),
            currency: $cart?->currency ?? (string) $request->input('currency', 'PLN'),
            packs: $packs,
        );

        return response()->json([
            'amount' => $quote->amount,
            'formatted' => $quote->amount === 0
                ? __('Free')
                : number_format($quote->amount / 100, 2, ',', ' ') . ' ' . $quote->currency,
            'currency' => $quote->currency,
            'provider' => $quote->provider,
            'carrier' => $quote->carrier,
            'service' => $quote->service,
            'provider_service_code' => $quote->providerServiceCode,
            'provider_service_name' => $quote->providerServiceName,
            'source' => $quote->payload['source'] ?? null,
        ]);
    }

    private function activeCart(CheckoutShippingQuoteRequest $request): ?Cart
    {
        $guestToken = $request->user()
            ? null
            : $request->cookie(CartGuestTokenResolver::COOKIE_NAME);

        $cart = $this->cartService->findActiveCart(
            $request->user(),
            $guestToken,
        );

        if ($cart === null || $cart->items()->count() === 0) {
            return null;
        }

        $cart->load([
            'items.variant',
        ]);

        return $cart;
    }
}
