<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GuestOrderCancelController extends Controller
{
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        $guestAccess = $request->session()->get('guest_order_access');

        abort_unless(
            is_array($guestAccess)
            && ($guestAccess['order_id'] ?? null) === $order->id,
            403
        );

        abort_if(! $order->isGuestOrder(), 404);
        abort_if(! $order->isPlaced(), 404);

        if (! $order->canBeCancelled()) {
            return redirect()
                ->route('guest.orders.show', $order)
                ->with('error', 'This order can no longer be cancelled.');
        }

        DB::transaction(function () use ($order): void {
            $existingNotes = trim((string) $order->notes);
            $systemNote = 'Cancelled by guest customer on '.now()->format('Y-m-d H:i:s');

            $order->update([
                'status' => OrderStatus::CANCELLED,
                'notes' => $existingNotes !== ''
                    ? $existingNotes.PHP_EOL.$systemNote
                    : $systemNote,
            ]);
        });

        return redirect()
            ->route('guest.orders.show', $order)
            ->with('success', 'Your order has been cancelled.');
    }
}
