<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zapytanie o wypożyczenie</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #18181b;">
<div style="width: 100%; background-color: #f4f4f5; padding: 32px 16px;">
    <div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; overflow: hidden;">
        <div style="padding: 32px; border-bottom: 1px solid #e4e4e7;">
            <h1 style="margin: 0 0 12px; font-size: 24px; line-height: 1.3;">Zapytanie o wypożyczenie sprzętu</h1>
            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #52525b;">
                Wysłane z formularza na stronie wypożyczalni. Odpowiedz na tę wiadomość, aby napisać bezpośrednio do klienta.
            </p>
        </div>

        <div style="padding: 32px;">
            <table role="presentation" style="width: 100%; border-collapse: collapse; font-size: 14px; line-height: 1.6;">
                @foreach ([
                    'Imię i nazwisko' => $name,
                    'E-mail' => $email,
                    'Telefon' => $phone,
                    'Sprzęt' => $items === [] ? null : implode(', ', $items),
                    'Okres wynajmu' => $period,
                    'Miejscowość' => $city,
                ] as $label => $value)
                    <tr>
                        <td style="padding: 6px 16px 6px 0; width: 150px; color: #71717a; vertical-align: top;">{{ $label }}</td>
                        <td style="padding: 6px 0; vertical-align: top;">{{ filled($value) ? $value : '—' }}</td>
                    </tr>
                @endforeach
            </table>

            <h2 style="margin: 24px 0 8px; font-size: 16px;">Wiadomość</h2>
            <p style="margin: 0; font-size: 14px; line-height: 1.6; white-space: pre-line;">{{ $body }}</p>
        </div>
    </div>
</div>
</body>
</html>
