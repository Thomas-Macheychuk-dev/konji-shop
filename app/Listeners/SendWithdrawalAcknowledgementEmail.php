<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WithdrawalRequestSubmitted;
use App\Mail\WithdrawalAcknowledgementMail;
use Illuminate\Support\Facades\Mail;

final class SendWithdrawalAcknowledgementEmail
{
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
