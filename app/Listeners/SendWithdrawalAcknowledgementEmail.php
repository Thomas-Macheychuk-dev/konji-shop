<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WithdrawalRequestSubmitted;
use App\Mail\WithdrawalAcknowledgementMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

final class SendWithdrawalAcknowledgementEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(WithdrawalRequestSubmitted $event): void
    {
        $withdrawalRequest = $event->withdrawalRequest->loadMissing([
            'order',
            'items',
        ]);

        Mail::to($withdrawalRequest->customer_email)
            ->send(new WithdrawalAcknowledgementMail($withdrawalRequest));

        if ($withdrawalRequest->acknowledged_at === null) {
            $withdrawalRequest->acknowledge();
        }
    }
}
