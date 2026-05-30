<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentReturnController
{
    public function __invoke(Request $request): View
    {
        $paymentId = $request->query('paymentId');
        $statusFromPaynow = $request->query('status'); // PAID / ERROR / REJECTED / CANCELED etc.

        $payment = null;
        $order = null;
        $isSuccess = false;

        if ($paymentId) {
            $payment = Payment::query()
                ->where('provider_reference', $paymentId)
                ->with([
                    'order' => fn (Builder $query): Builder => $query->with($this->orderRelations()),
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
            $isFailure = in_array($statusFromPaynow, ['ERROR', 'REJECTED', 'CANCELED'], true);

            $isSuccess = ! $isFailure;
        } else {
            $isSuccess = true;
        }

        $message = $isSuccess
            ? 'Dziękujemy za zakupy! Status płatności zostanie zaktualizowany po potwierdzeniu operatora płatności.'
            : 'Płatność nie została zakończona pomyślnie. Spróbuj ponownie lub skontaktuj się z nami.';

        return view('pages.checkout.return', [
            'isSuccess' => $isSuccess,
            'order' => $order,
            'payment' => $payment,
            'status' => $statusFromPaynow,
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
