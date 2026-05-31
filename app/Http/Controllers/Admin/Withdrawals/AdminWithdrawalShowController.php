<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Withdrawals;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use Illuminate\Contracts\View\View;

final class AdminWithdrawalShowController extends Controller
{
    public function __invoke(WithdrawalRequest $withdrawalRequest): View
    {
        $withdrawalRequest->load([
            'order',
            'user',
            'items.orderItem',
        ]);

        return view('admin.withdrawals.show', [
            'withdrawalRequest' => $withdrawalRequest,
        ]);
    }
}
