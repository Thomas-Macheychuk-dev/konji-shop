<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\RetryPaymentInitializationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class PaymentRetryController extends Controller
{
    public function __invoke(
        Request $request,
        Order $order,
        RetryPaymentInitializationService $retryPaymentInitializationService,
    ): RedirectResponse {
        abort_unless($this->canAccessOrder($request, $order), 404);

        $provider = (string) config('payments.default');

        try {
            $paymentInitialization = $retryPaymentInitializationService->retry($order, $provider);
        } catch (RuntimeException $exception) {
            Log::warning('Payment initialization retry failed', [
                'order_id' => $order->id,
                'provider' => $provider,
                'error' => $exception->getMessage(),
            ]);

            return back()->with(
                'error',
                'Nie udało się ponownie rozpocząć płatności. Spróbuj ponownie za chwilę.'
            );
        }

        return redirect()->away($paymentInitialization->redirectUrl);
    }

    private function canAccessOrder(Request $request, Order $order): bool
    {
        if ($request->user()) {
            return $order->user_id === $request->user()->id;
        }

        if ($order->user_id !== null) {
            return false;
        }

        if ((int) $request->session()->get('checkout.last_order_id') === $order->id) {
            return true;
        }

        $guestAccess = $request->session()->get('guest_order_access');

        return is_array($guestAccess)
            && (int) ($guestAccess['order_id'] ?? 0) === $order->id;
    }
}
