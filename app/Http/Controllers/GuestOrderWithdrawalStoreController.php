<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\WithdrawalRequestSubmitted;
use App\Http\Requests\StoreContractWithdrawalRequest;
use App\Models\Order;
use App\Services\Withdrawals\CreateWithdrawalRequestService;
use DomainException;
use Illuminate\Http\RedirectResponse;

final class GuestOrderWithdrawalStoreController extends Controller
{
    public function __invoke(
        StoreContractWithdrawalRequest $request,
        Order $order,
        CreateWithdrawalRequestService $service,
    ): RedirectResponse {
        $guestAccess = $request->session()->get('guest_order_access');

        abort_unless(
            is_array($guestAccess)
            && ($guestAccess['order_id'] ?? null) === $order->id,
            403
        );

        abort_if($order->user_id !== null, 404);
        abort_if($order->placed_at === null, 404);

        $order->load(['items.withdrawalRequestItems.withdrawalRequest']);

        try {
            $withdrawalRequest = $service->create(
                $order,
                [
                    ...$request->validated(),
                    'source' => 'guest',
                ],
            );
        } catch (DomainException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        WithdrawalRequestSubmitted::dispatch($withdrawalRequest);

        return redirect()
            ->route('guest.orders.show', $order)
            ->with('success', 'Your contract withdrawal request has been submitted. Reference: '.$withdrawalRequest->number);
    }
}
