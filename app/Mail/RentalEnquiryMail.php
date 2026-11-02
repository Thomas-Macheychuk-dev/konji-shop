<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A visitor's rental availability question, sent to the shop. Reply-To is the visitor,
 * so the shop answers with a normal reply; nothing is sent to the visitor's address.
 */
final class RentalEnquiryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $items  Rental item names
     */
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $phone,
        public readonly array $items,
        public readonly ?string $period,
        public readonly ?string $city,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->email, $this->name)],
            subject: 'Wypożyczalnia – zapytanie od '.$this->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rental.enquiry',
        );
    }
}
