<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WithdrawalRequestRefunded;
use App\Mail\WithdrawalRefundedMail;
use Illuminate\Support\Facades\Mail;

final class SendWithdrawalRefundedEmail
{
    public function handle(WithdrawalRequestRefunded $event): void
    {
        $withdrawalRequest = $event->withdrawalRequest->loadMissing([
            'order',
            'items',
        ]);

        Mail::to($withdrawalRequest->customer_email)
            ->send(new WithdrawalRefundedMail($withdrawalRequest));

        $withdrawalRequest->order?->events()->create([
            'type' => 'withdrawal_refund_email_sent',
            'description' => 'E-mail o zwrocie środków został wysłany do klienta.',
            'meta' => [
                'withdrawal_request_id' => $withdrawalRequest->id,
                'withdrawal_request_number' => $withdrawalRequest->number,
                'recipient' => $withdrawalRequest->customer_email,
            ],
        ]);
    }
}
