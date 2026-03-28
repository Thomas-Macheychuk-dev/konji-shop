<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderCancelController extends Controller
{
    public function __invoke(Request $request, int $orderId): RedirectResponse
    {
        $order = $request->user()
            ->orders()
            ->whereNotNull('placed_at')
            ->findOrFail($orderId);

        if (! $order->canBeCancelledByCustomer()) {
            return redirect()
                ->route('account.orders.show', $order->id)
                ->with('error', 'This order can no longer be cancelled.');
        }

        DB::transaction(function () use ($order): void {
            $existingNotes = trim((string) $order->notes);

            $systemNote = 'Cancelled by customer on '.now()->format('Y-m-d H:i:s');

            $order->update([
                'status' => OrderStatus::CANCELLED,
                'notes' => $existingNotes !== ''
                    ? $existingNotes.PHP_EOL.$systemNote
                    : $systemNote,
            ]);
        });

        return redirect()
            ->route('account.orders.show', $order->id)
            ->with('success', 'Your order has been cancelled.');
    }
}
