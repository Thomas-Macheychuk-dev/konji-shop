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

        /**
         * Paynow return URL uses `paymentStatus`, for example:
         * /checkout/success?paymentId=...&paymentStatus=CONFIRMED
         *
         * Keep `status` as a fallback in case another provider or older code uses it.
         */
        $statusFromPaynow = $request->query('paymentStatus', $request->query('status'));

        $payment = null;
        $order = null;
        $isSuccess = false;

        if ($paymentId) {
            $payment = Payment::query()
                ->where('provider_reference', $paymentId)
                ->with([
                    'order' => fn ($query) => $query->with($this->orderRelations()),
                ])
                ->first();

            $order = $payment?->order;
        }

        if (! $payment) {
            $lastOrderId = $request->session()->get('checkout.last_order_id');

            if ($lastOrderId) {
                $order = Order::query()
                    ->with($this->orderRelations())
                    ->find($lastOrderId);

                if ($order) {
                    $payment = $order->payments()
                        ->latest('id')
                        ->first();
                }
            }
        }

        if ($order) {
            $order->loadMissing($this->orderRelations());
        }

        if ($payment) {
            $isFailure = in_array($statusFromPaynow, ['ERROR', 'REJECTED', 'CANCELED'], true)
                || $payment->status->isFailed()
                || $payment->status->isUnpaid();

            $isSuccess = ! $isFailure;
        } else {
            $isSuccess = true;
        }

        if ($isSuccess) {
            $message = match (true) {
                $payment?->status->isPaid() => __('checkout.return.messages.payment_confirmed'),
                $payment?->status->isPending() => __('checkout.return.messages.payment_processing'),
                default => __('checkout.return.messages.order_received'),
            };
        } else {
            $message = $payment?->status->isUnpaid()
                ? __('checkout.return.messages.payment_not_started')
                : __('checkout.return.messages.payment_failed');
        }

        return view('pages.checkout.return', [
            'isSuccess' => $isSuccess,
            'order' => $order,
            'payment' => $payment,
            'message' => $message,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function orderRelations(): array
    {
        return [
            'items.product',
            'items.variant.attributeValues.attribute',
            'shippingAddress',
            'billingAddress',
            'payments',
            'shipments',
        ];
    }
}
