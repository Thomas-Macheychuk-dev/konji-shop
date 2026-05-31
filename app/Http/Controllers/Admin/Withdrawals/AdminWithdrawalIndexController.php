<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Withdrawals;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use Illuminate\Contracts\View\View;

final class AdminWithdrawalIndexController extends Controller
{
    public function __invoke(): View
    {
        $withdrawalRequests = WithdrawalRequest::query()
            ->with(['order', 'items'])
            ->latest('submitted_at')
            ->paginate(20);

        return view('admin.withdrawals.index', [
            'withdrawalRequests' => $withdrawalRequests,
        ]);
    }
}
