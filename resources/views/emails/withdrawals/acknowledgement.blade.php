<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract withdrawal received</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #18181b;">
<div style="width: 100%; background-color: #f4f4f5; padding: 32px 16px;">
    <div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; overflow: hidden;">
        <div style="padding: 32px; border-bottom: 1px solid #e4e4e7;">
            <h1 style="margin: 0 0 12px; font-size: 28px; line-height: 1.2;">
                Contract withdrawal received
            </h1>

            <p style="margin: 0 0 8px; font-size: 16px; line-height: 1.6;">
                We have received your contract withdrawal statement.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #52525b;">
                <strong>Withdrawal reference:</strong> {{ $withdrawalRequest->number }}<br>
                <strong>Order number:</strong> {{ $withdrawalRequest->order_number_snapshot }}<br>
                <strong>Submitted at:</strong> {{ optional($withdrawalRequest->submitted_at)?->format('Y-m-d H:i') ?? '—' }}
            </p>
        </div>

        <div style="padding: 32px;">
            <h2 style="margin: 0 0 16px; font-size: 20px; line-height: 1.3;">
                Customer details
            </h2>

            <p style="margin: 0 0 24px; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                <strong>Name:</strong> {{ $withdrawalRequest->customer_name }}<br>
                <strong>Email:</strong> {{ $withdrawalRequest->customer_email }}
            </p>

            <h2 style="margin: 0 0 16px; font-size: 20px; line-height: 1.3;">
                Selected item(s)
            </h2>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%; margin-bottom: 24px;">
                <thead>
                <tr>
                    <th align="left" style="padding: 12px 8px; border-bottom: 1px solid #e4e4e7; font-size: 14px;">
                        Product
                    </th>
                    <th align="center" style="padding: 12px 8px; border-bottom: 1px solid #e4e4e7; font-size: 14px;">
                        Qty
                    </th>
                    <th align="right" style="padding: 12px 8px; border-bottom: 1px solid #e4e4e7; font-size: 14px;">
                        Amount gross
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
                        Reason
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $withdrawalRequest->reason }}
                    </p>
                </div>
            @endif

            @if ($withdrawalRequest->customer_note)
                <div style="margin-bottom: 24px;">
                    <h2 style="margin: 0 0 12px; font-size: 20px; line-height: 1.3;">
                        Message
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $withdrawalRequest->customer_note }}
                    </p>
                </div>
            @endif

            @if ($withdrawalRequest->refund_note)
                <div style="margin-bottom: 24px;">
                    <h2 style="margin: 0 0 12px; font-size: 20px; line-height: 1.3;">
                        Refund note
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $withdrawalRequest->refund_note }}
                    </p>
                </div>
            @endif

            <p style="margin: 0 0 12px; font-size: 14px; line-height: 1.7; color: #52525b;">
                This email confirms that your withdrawal statement has been submitted electronically.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #52525b;">
                We will review the request and contact you with the next steps.
            </p>
        </div>
    </div>
</div>
</body>
</html>
