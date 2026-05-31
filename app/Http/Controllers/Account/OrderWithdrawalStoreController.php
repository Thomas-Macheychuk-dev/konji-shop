<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Events\WithdrawalRequestSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContractWithdrawalRequest;
use App\Services\Withdrawals\CreateWithdrawalRequestService;
use DomainException;
use Illuminate\Http\RedirectResponse;

final class OrderWithdrawalStoreController extends Controller
{
    public function __invoke(
        StoreContractWithdrawalRequest $request,
        int $orderId,
        CreateWithdrawalRequestService $service,
    ): RedirectResponse {
        $order = $request->user()
            ->orders()
            ->whereNotNull('placed_at')
            ->with(['items.withdrawalRequestItems.withdrawalRequest'])
            ->findOrFail($orderId);

        try {
            $withdrawalRequest = $service->create(
                $order,
                [
                    ...$request->validated(),
                    'source' => 'account',
                ],
                $request->user(),
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        WithdrawalRequestSubmitted::dispatch($withdrawalRequest);

        return redirect()
            ->route('account.orders.show', $order->id)
            ->with('success', 'Your contract withdrawal request has been submitted. Reference: '.$withdrawalRequest->number);
    }
}
