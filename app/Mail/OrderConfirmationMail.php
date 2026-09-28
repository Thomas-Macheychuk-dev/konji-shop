<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderConfirmationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Potwierdzenie zamówienia - '.$this->order->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.orders.confirmation',
        );
    }

    public function attachments(): array
    {
        $version = trim((string) ($this->order->terms_version ?: config('legal.versions.terms')));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) !== 1) {
            return [];
        }

        $relativePaths = [
            "legal/{$version}/ORTEZKA_PL_Regulamin_sklepu_internetowego_{$version}.pdf",
            "legal/{$version}/ORTEZKA_PL_Formularz_odstapienia_DLA_KLIENTA_{$version}.pdf",
        ];

        return collect($relativePaths)
            ->map(fn (string $relativePath): string => public_path($relativePath))
            ->filter(fn (string $path): bool => is_file($path))
            ->map(fn (string $path): Attachment => Attachment::fromPath($path)
                ->as(basename($path))
                ->withMime('application/pdf'))
            ->values()
            ->all();
    }
}
