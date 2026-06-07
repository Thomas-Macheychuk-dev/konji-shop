<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ShipmentTrackingAvailable;use App\Mail\ShipmentTrackingMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendShipmentTrackingEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(ShipmentTrackingAvailable $event): void    {
        $shipment = $event->shipment->loadMissing([
            'order.user',
            'order.shippingAddress',
            'order.billingAddress',
        ]);

        if ($shipment->tracking_email_sent_at !== null) {
            return;
        }

        if (blank($shipment->tracking_number) && blank($shipment->tracking_url)) {
            return;
        }

        $order = $shipment->order;
        $recipient = $order->user?->email ?: $order->guest_email ?: $order->shippingAddress?->email;

        if (! $recipient) {
            return;
        }

        Mail::to($recipient)->send(new ShipmentTrackingMail($shipment));

        $shipment->forceFill([
            'tracking_email_sent_at' => now(),
        ])->save();

        $order->events()->create([
            'type' => 'shipment_tracking_email_sent',
            'description' => 'E-mail ze śledzeniem przesyłki został wysłany do klienta.',
            'meta' => [
                'shipment_id' => $shipment->id,
                'recipient' => $recipient,
                'tracking_number' => $shipment->tracking_number,
                'tracking_url' => $shipment->tracking_url,
            ],
        ]);
    }
}
