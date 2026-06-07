<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\OrderPlaced;
use App\Http\Requests\PlaceOrderRequest;
use App\Services\Cart\CartGuestTokenResolver;
use App\Services\Cart\CartService;
use App\Services\Checkout\CheckoutService;
use App\Services\Payments\StartPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class CheckoutPlaceOrderController extends Controller
{
    public function __invoke(
        PlaceOrderRequest $request,
        CartService $cartService,
        CartGuestTokenResolver $guestTokenResolver,
        CheckoutService $checkoutService,
        StartPaymentService $startPaymentService,
    ): RedirectResponse {
        $guestToken = $request->user() ? null : $guestTokenResolver->resolve($request);

        $cart = $cartService->findActiveCart($request->user(), $guestToken);

        if (! $cart || $cart->items()->count() === 0) {
            return redirect()->route('cart.show')->with('error', 'Twój koszyk jest pusty.');
        }

        try {
            $order = $checkoutService->placeOrder(
                $cart,
                $request->validated(),
                $request->user()
            );

            $payment = $order->payments()->oldest('id')->first();

            if (! $payment) {
                throw new RuntimeException('Order payment record was not created.');
            }

            $providerKey = config('payments.default');

            $paymentInitialization = $startPaymentService->start(
                $order,
                $payment,
                $providerKey
            );

            Log::info('Payment initialization result', [
                'provider' => $paymentInitialization->provider,
                'provider_reference' => $paymentInitialization->providerReference,
                'redirect_url' => $paymentInitialization->redirectUrl,
                'payload' => $paymentInitialization->payload,
            ]);

        } catch (RuntimeException $exception) {
            Log::error('Payment initialization failed', [
                'order_id' => $order->id ?? null,
                'provider' => $providerKey ?? null,
                'error' => $exception->getMessage(),
            ]);

            if (isset($order)) {
                $request->session()->put('checkout.last_order_id', $order->id);

                return redirect()
                    ->route('checkout.success')
                    ->with('error', 'Order was created, but payment could not be started: '.$exception->getMessage());
            }

            return redirect()
                ->route('checkout.show')
                ->withInput()
                ->with('error', 'BŁĄD PAYNOW: '.$exception->getMessage());
        }

        OrderPlaced::dispatch($order);
        $request->session()->put('checkout.last_order_id', $order->id);

        return redirect()->away($paymentInitialization->redirectUrl);
    }
}
