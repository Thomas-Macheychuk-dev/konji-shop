<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Orders;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AdminOrderNoteController extends Controller
{
    public function __invoke(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'max:5000'],
        ]);

        $order->appendNote(
            $request->user()->email.': '.$validated['note']
        );

        return back()->with('success', 'Internal note added.');
    }
}
