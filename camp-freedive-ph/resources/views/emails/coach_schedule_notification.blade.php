<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $eventTitle }} - Camp FreedivePH</title>
</head>
<body style="margin:0;padding:24px 12px;background:#F2F2F7;color:#1D1D1F;font-family:Arial,sans-serif;line-height:1.6;">
    <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #E5E5EA;">
        <div style="padding:18px 24px;background:#780000;color:#fff;font-weight:800;">Camp FreedivePH</div>
        <div style="padding:28px 24px;">
            <h1 style="margin:0 0 12px;font-size:22px;">{{ $eventTitle }}</h1>
            <p>Hello Coach,</p>
            <p>{{ $message }}</p>
            <table style="width:100%;border-collapse:collapse;margin:20px 0;">
                <tr><td style="padding:8px 0;color:#6E6E73;">Batch</td><td style="padding:8px 0;font-weight:700;">{{ $batch->batch_code }}</td></tr>
                <tr><td style="padding:8px 0;color:#6E6E73;">Schedule</td><td style="padding:8px 0;font-weight:700;">{{ $batch->start_date?->format('M d, Y') }} - {{ $batch->end_date?->format('M d, Y') }}</td></tr>
                @if($bookingNumber)
                    <tr><td style="padding:8px 0;color:#6E6E73;">Booking</td><td style="padding:8px 0;font-weight:700;">{{ $bookingNumber }}</td></tr>
                @endif
            </table>
            <p style="color:#6E6E73;font-size:13px;">Please open the Coach Portal for the latest roster, schedule, and action required.</p>
        </div>
    </div>
</body>
</html>
