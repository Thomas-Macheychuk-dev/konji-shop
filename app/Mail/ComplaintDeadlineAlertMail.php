<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Complaint;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Internal reminder to the shop about complaints that reached the alert day or passed the response deadline.
 */
final class ComplaintDeadlineAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  Collection<int, Complaint>  $complaints
     */
    public function __construct(
        public readonly Collection $complaints,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reklamacje – zbliża się lub minął termin odpowiedzi ('.$this->complaints->count().')',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.complaints.deadline-alert',
        );
    }
}
