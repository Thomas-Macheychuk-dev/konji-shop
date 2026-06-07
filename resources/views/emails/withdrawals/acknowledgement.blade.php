<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Otrzymaliśmy oświadczenie o odstąpieniu</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #18181b;">
<div style="width: 100%; background-color: #f4f4f5; padding: 32px 16px;">
    <div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; overflow: hidden;">
        <div style="padding: 32px; border-bottom: 1px solid #e4e4e7;">
            <h1 style="margin: 0 0 12px; font-size: 28px; line-height: 1.2;">
                Otrzymaliśmy oświadczenie o odstąpieniu
            </h1>

            <p style="margin: 0 0 8px; font-size: 16px; line-height: 1.6;">
                Otrzymaliśmy Twoje oświadczenie o odstąpieniu od umowy.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #52525b;">
                <strong>Numer odstąpienia:</strong> {{ $withdrawalRequest->number }}<br>
                <strong>Numer zamówienia:</strong> {{ $withdrawalRequest->order_number_snapshot }}<br>
                <strong>Zgłoszono:</strong> {{ optional($withdrawalRequest->submitted_at)?->format('Y-m-d H:i') ?? '—' }}
            </p>
        </div>

        <div style="padding: 32px;">
            <h2 style="margin: 0 0 16px; font-size: 20px; line-height: 1.3;">
                Dane klienta
            </h2>

            <p style="margin: 0 0 24px; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                <strong>Imię i nazwisko:</strong> {{ $withdrawalRequest->customer_name }}<br>
                <strong>E-mail:</strong> {{ $withdrawalRequest->customer_email }}
            </p>

            <h2 style="margin: 0 0 16px; font-size: 20px; line-height: 1.3;">
                Wybrane pozycje
            </h2>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%; margin-bottom: 24px;">
                <thead>
                <tr>
                    <th align="left" style="padding: 12px 8px; border-bottom: 1px solid #e4e4e7; font-size: 14px;">
                        Produkt
                    </th>
                    <th align="center" style="padding: 12px 8px; border-bottom: 1px solid #e4e4e7; font-size: 14px;">
                        Ilość
                    </th>
                    <th align="right" style="padding: 12px 8px; border-bottom: 1px solid #e4e4e7; font-size: 14px;">
                        Kwota brutto
                    </th>
                </tr>
                </thead>

                <tbody>
                @foreach ($withdrawalRequest->items as $item)
                    <tr>
                        <td style="padding: 12px 8px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: top;">
                            <div style="font-weight: 600;">
                                {{ $item->product_name_snapshot }}
                            </div>

                            @if ($item->variant_name_snapshot)
                                <div style="margin-top: 4px; color: #52525b;">
                                    {{ $item->variant_name_snapshot }}
                                </div>
                            @endif

                            @if ($item->sku_snapshot)
                                <div style="margin-top: 4px; color: #71717a; font-size: 12px;">
                                    SKU: {{ $item->sku_snapshot }}
                                </div>
                            @endif
                        </td>

                        <td align="center" style="padding: 12px 8px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: top;">
                            {{ $item->quantity_requested }}
                        </td>

                        <td align="right" style="padding: 12px 8px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: top;">
                            {{ $item->lineGrossDecimal() }} {{ $withdrawalRequest->order?->currency ?? 'PLN' }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            @if ($withdrawalRequest->reason)
                <div style="margin-bottom: 24px;">
                    <h2 style="margin: 0 0 12px; font-size: 20px; line-height: 1.3;">
                        Powód
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $withdrawalRequest->reason }}
                    </p>
                </div>
            @endif

            @if ($withdrawalRequest->customer_note)
                <div style="margin-bottom: 24px;">
                    <h2 style="margin: 0 0 12px; font-size: 20px; line-height: 1.3;">
                        Wiadomość
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $withdrawalRequest->customer_note }}
                    </p>
                </div>
            @endif

            @if ($withdrawalRequest->refund_note)
                <div style="margin-bottom: 24px;">
                    <h2 style="margin: 0 0 12px; font-size: 20px; line-height: 1.3;">
                        Notatka do zwrotu
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $withdrawalRequest->refund_note }}
                    </p>
                </div>
            @endif

            <p style="margin: 0 0 12px; font-size: 14px; line-height: 1.7; color: #52525b;">
                Ten e-mail potwierdza elektroniczne złożenie oświadczenia o odstąpieniu od umowy.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #52525b;">
                Przeanalizujemy zgłoszenie i skontaktujemy się z Tobą w sprawie kolejnych kroków.
            </p>
        </div>
    </div>
</div>
</body>
</html>
