<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reklamacje – termin odpowiedzi</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #18181b;">
<div style="width: 100%; background-color: #f4f4f5; padding: 32px 16px;">
    <div style="max-width: 640px; margin: 0 auto; background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 16px; overflow: hidden;">
        <div style="padding: 32px; border-bottom: 1px solid #e4e4e7;">
            <h1 style="margin: 0 0 12px; font-size: 24px; line-height: 1.3;">Reklamacje czekają na odpowiedź</h1>
            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #52525b;">
                Na reklamację konsumenta trzeba odpowiedzieć w ciągu 14 dni od jej otrzymania. Brak odpowiedzi w terminie oznacza uznanie reklamacji (§ 12 ust. 8 Regulaminu).
            </p>
        </div>

        <div style="padding: 32px;">
            <table role="presentation" style="width: 100%; border-collapse: collapse; font-size: 14px; line-height: 1.5;">
                <tr>
                    <th style="padding: 6px 12px 6px 0; text-align: left; color: #71717a; font-weight: normal;">Reklamacja</th>
                    <th style="padding: 6px 12px 6px 0; text-align: left; color: #71717a; font-weight: normal;">Klient</th>
                    <th style="padding: 6px 0; text-align: left; color: #71717a; font-weight: normal;">Termin</th>
                </tr>
                @foreach ($complaints as $complaint)
                    <tr>
                        <td style="padding: 8px 12px 8px 0; border-top: 1px solid #e4e4e7; vertical-align: top;">
                            <a href="{{ route('admin.complaints.show', $complaint) }}" style="color: #18181b; font-weight: bold;">{{ $complaint->number }}</a>
                            <div style="color: #52525b;">{{ \Illuminate\Support\Str::limit($complaint->product_description, 60) }}</div>
                        </td>
                        <td style="padding: 8px 12px 8px 0; border-top: 1px solid #e4e4e7; vertical-align: top;">{{ $complaint->customer_name }}</td>
                        <td style="padding: 8px 0; border-top: 1px solid #e4e4e7; vertical-align: top;">
                            {{ $complaint->response_due_on->format('Y-m-d') }}
                            <div style="color: {{ $complaint->deadlineState() === \App\Models\Complaint::DEADLINE_OVERDUE ? '#b91c1c' : '#b45309' }}; font-weight: bold;">{{ $complaint->deadlineLabel() }}</div>
                        </td>
                    </tr>
                @endforeach
            </table>

            <p style="margin: 24px 0 0; font-size: 14px;">
                <a href="{{ route('admin.complaints.index') }}" style="color: #18181b;">Otwórz rejestr reklamacji</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
