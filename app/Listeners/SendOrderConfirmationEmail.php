<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Mail\OrderConfirmationMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendOrderConfirmationEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(OrderPlaced $event): void
    {
        $order = $event->order->loadMissing([
            'user',
            'items',
            'shippingAddress',
            'billingAddress',
        ]);

        $recipient = $order->user?->email ?: $order->guest_email;

        if (! $recipient) {
            return;
        }

        Mail::to($recipient)->send(new OrderConfirmationMail($order));
    }
}
