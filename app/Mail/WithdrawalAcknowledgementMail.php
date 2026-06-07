<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\WithdrawalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class WithdrawalAcknowledgementMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly WithdrawalRequest $withdrawalRequest,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Otrzymaliśmy oświadczenie o odstąpieniu - '.$this->withdrawalRequest->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.withdrawals.acknowledgement',
        );
    }
}
