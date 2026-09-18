<?php

it('keeps the Paynow notification configuration aligned with the routed endpoint', function (): void {
    expect(config('payments.providers.paynow.notification_path'))
        ->toBe('/payments/paynow/notifications')
        ->toBe(route('payments.paynow.notifications', [], false));
});
