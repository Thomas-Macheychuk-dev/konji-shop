<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order confirmation</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #18181b;">
<div style="width: 100%; background-color: #f4f4f5; padding: 32px 16px;">
    <div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; overflow: hidden;">
        <div style="padding: 32px; border-bottom: 1px solid #e4e4e7;">
            <h1 style="margin: 0 0 12px; font-size: 28px; line-height: 1.2;">
                Thank you for your order
            </h1>

            <p style="margin: 0 0 8px; font-size: 16px; line-height: 1.6;">
                We have received your order and it is now being processed.
            </p>

            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #52525b;">
                <strong>Order number:</strong> {{ $order->number }}<br>
                <strong>Placed at:</strong> {{ optional($order->placed_at)?->format('Y-m-d H:i') ?? '—' }}
            </p>
        </div>

        <div style="padding: 32px;">
            <h2 style="margin: 0 0 16px; font-size: 20px; line-height: 1.3;">
                Order summary
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
                        Total
                    </th>
                </tr>
                </thead>
                <tbody>
                @foreach ($order->items as $item)
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
                        </td>
                        <td align="center" style="padding: 12px 8px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: top;">
                            {{ $item->quantity }}
                        </td>
                        <td align="right" style="padding: 12px 8px; border-bottom: 1px solid #f0f0f0; font-size: 14px; vertical-align: top;">
                            {{ number_format(($item->line_total_amount ?? 0) / 100, 2, '.', ' ') }} {{ $order->currency }}
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; width: 100%; margin-bottom: 24px;">
                <tbody>
                <tr>
                    <td style="padding: 6px 0; font-size: 14px; color: #52525b;">
                        Subtotal
                    </td>
                    <td align="right" style="padding: 6px 0; font-size: 14px; color: #52525b;">
                        {{ number_format(($order->subtotal_amount ?? 0) / 100, 2, '.', ' ') }} {{ $order->currency }}
                    </td>
                </tr>
                <tr>
                    <td style="padding: 6px 0; font-size: 14px; color: #52525b;">
                        Shipping
                    </td>
                    <td align="right" style="padding: 6px 0; font-size: 14px; color: #52525b;">
                        {{ number_format(($order->shipping_amount ?? 0) / 100, 2, '.', ' ') }} {{ $order->currency }}
                    </td>
                </tr>
                @if (($order->discount_amount ?? 0) > 0)
                    <tr>
                        <td style="padding: 6px 0; font-size: 14px; color: #52525b;">
                            Discount
                        </td>
                        <td align="right" style="padding: 6px 0; font-size: 14px; color: #52525b;">
                            -{{ number_format(($order->discount_amount ?? 0) / 100, 2, '.', ' ') }} {{ $order->currency }}
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding: 12px 0 0; border-top: 1px solid #e4e4e7; font-size: 16px; font-weight: 700;">
                        Total
                    </td>
                    <td align="right" style="padding: 12px 0 0; border-top: 1px solid #e4e4e7; font-size: 16px; font-weight: 700;">
                        {{ number_format(($order->total_amount ?? 0) / 100, 2, '.', ' ') }} {{ $order->currency }}
                    </td>
                </tr>
                </tbody>
            </table>

            @if ($order->shippingAddress)
                <div style="margin-bottom: 24px;">
                    <h2 style="margin: 0 0 12px; font-size: 20px; line-height: 1.3;">
                        Shipping address
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $order->shippingAddress->first_name }} {{ $order->shippingAddress->last_name }}<br>

                        @if ($order->shippingAddress->company)
                            {{ $order->shippingAddress->company }}<br>
                        @endif

                        {{ $order->shippingAddress->address_line_1 }}

                        @if ($order->shippingAddress->address_line_2)
                            / {{ $order->shippingAddress->address_line_2 }}
                        @endif<br>



                        {{ $order->shippingAddress->postcode }} {{ $order->shippingAddress->city }}<br>

                        @if ($order->shippingAddress->country_code)
                            {{ $order->shippingAddress->countryName() }}<br>
                        @endif

                        @if ($order->shippingAddress->phone)
                            {{ $order->shippingAddress->phone }}
                        @endif
                    </p>
                </div>
            @endif

            @if ($order->billingAddress)
                <div style="margin-bottom: 24px;">
                    <h2 style="margin: 0 0 12px; font-size: 20px; line-height: 1.3;">
                        Billing address
                    </h2>

                    <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #3f3f46;">
                        {{ $order->billingAddress->first_name }} {{ $order->billingAddress->last_name }}<br>

                        @if ($order->billingAddress->company)
                            {{ $order->billingAddress->company }}<br>
                        @endif

                        {{ $order->billingAddress->address_line_1 }}

                        @if ($order->billingAddress->address_line_2)
                            / {{ $order->billingAddress->address_line_2 }}<br>
                        @endif

                        {{ $order->billingAddress->postcode }} {{ $order->billingAddress->city }}<br>

                        @if ($order->billingAddress->country_code)
                            {{ $order->billingAddress->countryName() }}<br>
                        @endif

                        @if ($order->billingAddress->phone)
                            {{ $order->billingAddress->phone }}<br>
                        @endif

                        @if ($order->billingAddress->email)
                            {{ $order->billingAddress->email }}
                        @endif
                    </p>
                </div>
            @endif

            <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #52525b;">
                If you have any questions about your order, please contact our support team.
            </p>
        </div>
    </div>
</div>
</body>
</html>
