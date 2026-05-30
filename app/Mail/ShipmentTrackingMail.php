<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Shipment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ShipmentTrackingMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Shipment $shipment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your shipment tracking is ready - '.$this->shipment->order->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.shipments.tracking',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
