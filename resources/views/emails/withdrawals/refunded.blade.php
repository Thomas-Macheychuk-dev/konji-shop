<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zwrot środków przetworzony</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #18181b;">
@php
    $order = $withdrawalRequest->order;
    $currency = $order?->currency ?? 'PLN';
@endphp

<div style="width: 100%; background-color: #f4f4f5; padding: 32px 16px;">
    <div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; overflow: hidden;">
        <div style="padding: 32px; border-bottom: 1px solid #e4e4e7;">
            <h1 style="margin: 0 0 12px; font-size: 28px; line-height: 1.2;">
                Zwrot środków został przetworzony
            </h1>

            <p style="margin: 0 0 8px; font-size: 16px; line-height: 1.6;">
                Przetworzyliśmy zwrot środków związany z Twoim odstąpieniem od umowy.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #52525b;">
                <strong>Numer odstąpienia:</strong> {{ $withdrawalRequest->number }}<br>
                <strong>Numer zamówienia:</strong> {{ $withdrawalRequest->order_number_snapshot }}<br>
                <strong>Zwrócono środki:</strong> {{ optional($withdrawalRequest->refunded_at)?->format('Y-m-d H:i') ?? '—' }}<br>
                <strong>Kwota zwrotu:</strong> {{ $withdrawalRequest->refundAmountDecimal() }} {{ $currency }}
            </p>
        </div>

        <div style="padding: 32px;">
            <h2 style="margin: 0 0 16px; font-size: 20px; line-height: 1.3;">
                Zwrócone pozycje
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
                            {{ $item->lineGrossDecimal() }} {{ $currency }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <p style="margin: 0 0 12px; font-size: 14px; line-height: 1.7; color: #52525b;">
                Zwrot środków jest realizowany, o ile to możliwe, tą samą metodą płatności.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #52525b;">
                W zależności od operatora płatności i banku zaksięgowanie środków na koncie może potrwać pewien czas.
            </p>
        </div>
    </div>
</div>
</body>
</html>
