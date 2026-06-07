<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Śledzenie przesyłki jest gotowe</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #18181b;">
@php
    $order = $shipment->order;
    $carrierLabel = $shipment->carrier()?->label() ?? ucfirst((string) ($shipment->provider?->value ?? 'delivery'));
    $serviceLabel = \App\Enums\DeliveryService::tryFrom((string) $shipment->service)?->label() ?? ($shipment->service ?: 'Dostawa');
@endphp

<div style="width: 100%; background-color: #f4f4f5; padding: 32px 16px;">
    <div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; overflow: hidden;">
        <div style="padding: 32px; border-bottom: 1px solid #e4e4e7;">
            <h1 style="margin: 0 0 12px; font-size: 28px; line-height: 1.2;">
                Śledzenie przesyłki jest gotowe
            </h1>

            <p style="margin: 0 0 8px; font-size: 16px; line-height: 1.6;">
                Dobra wiadomość — Twoja przesyłka Konji Shop została przygotowana, a szczegóły śledzenia są już dostępne.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #52525b;">
                <strong>Numer zamówienia:</strong> {{ $order->number }}<br>
                <strong>Carrier:</strong> {{ $carrierLabel }}<br>
                <strong>Usługa:</strong> {{ $serviceLabel }}
            </p>
        </div>

        <div style="padding: 32px;">
            <h2 style="margin: 0 0 16px; font-size: 20px; line-height: 1.3;">
                Szczegóły śledzenia
            </h2>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%; margin-bottom: 24px;">
                <tbody>
                <tr>
                    <td style="padding: 8px 0; font-size: 14px; color: #52525b;">
                        Numer śledzenia
                    </td>
                    <td align="right" style="padding: 8px 0; font-size: 14px; font-weight: 700; color: #18181b;">
                        {{ $shipment->tracking_number ?: 'Oczekuje' }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 8px 0; font-size: 14px; color: #52525b;">
                        Numer referencyjny przesyłki
                    </td>
                    <td align="right" style="padding: 8px 0; font-size: 14px; font-weight: 700; color: #18181b;">
                        {{ $shipment->provider_reference ?: '—' }}
                    </td>
                </tr>
                @if ($shipment->locker_code)
                    <tr>
                        <td style="padding: 8px 0; font-size: 14px; color: #52525b;">
                            Paczkomat
                        </td>
                        <td align="right" style="padding: 8px 0; font-size: 14px; font-weight: 700; color: #18181b;">
                            {{ $shipment->locker_code }}
                        </td>
                    </tr>
                @endif
                </tbody>
            </table>

            @if ($shipment->tracking_url)
                <p style="margin: 0 0 24px;">
                    <a
                        href="{{ $shipment->tracking_url }}"
                        style="display: inline-block; border-radius: 12px; background-color: #18181b; padding: 12px 20px; color: #ffffff; font-size: 14px; font-weight: 700; text-decoration: none;"
                    >
                        Śledź przesyłkę
                    </a>
                </p>
            @endif

            <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #52525b;">
                Dane śledzenia mogą pojawić się na stronie przewoźnika z opóźnieniem. Przesyłka może pokazać ruch po odebraniu jej przez kuriera.
            </p>
        </div>
    </div>
</div>
</body>
</html>
