<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class OrderIndexController extends Controller
{
    public function __invoke(Request $request): View
    {
        $orders = $request->user()
            ->orders()
            ->with(['items', 'payments'])
            ->whereNotNull('placed_at')
            ->paginate(10);

        return view('pages.account.orders.index', [
            'orders' => $orders,
        ]);
    }
}
