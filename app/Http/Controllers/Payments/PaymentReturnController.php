<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentReturnController
{
    public function __invoke(Request $request): View
    {
        $paymentId = $request->query('paymentId');
        $statusFromPaynow = $request->query('status'); // PAID / ERROR / REJECTED itp.

        $payment = null;
        $order = null;
        $isSuccess = false;

        if ($paymentId) {
            $payment = Payment::where('provider_reference', $paymentId)
                ->with('order')
                ->first();
        }

        if (! $payment) {
            $lastOrderId = $request->session()->get('checkout.last_order_id');
            if ($lastOrderId) {
                $order = Order::with('payments')->find($lastOrderId);
                if ($order) {
                    $payment = $order->payments()->latest('id')->first();
                }
            }
        }

        if ($payment) {
            $order = $payment->order ?? $order;

            $isFailure = in_array($statusFromPaynow, ['ERROR', 'REJECTED', 'CANCELED'], true);

            $isSuccess = ! $isFailure;
        } else {
            $isSuccess = true;
        }

        $message = $isSuccess
            ? 'Dziękujemy za zakupy! Twoje zamówienie zostało przyjęte. Status płatności zostanie zaktualizowany po potwierdzeniu operatora płatności.'
            : 'Płatność nie została zakończona pomyślnie. Spróbuj ponownie lub skontaktuj się z nami.';

        return view('pages.checkout.return', [
            'isSuccess' => $isSuccess,
            'order' => $order,
            'payment' => $payment,
            'status' => $statusFromPaynow,
            'message' => $message,
        ]);
    }
}
