<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weather update - Camp FreedivePH</title>
</head>
<body style="margin:0;padding:24px 12px;background:#F2F2F7;color:#1D1D1F;font-family:Arial,sans-serif;line-height:1.6;">
    <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #E5E5EA;">
        <div style="padding:18px 24px;background:#780000;color:#fff;font-weight:800;">Camp FreedivePH</div>
        <div style="padding:28px 24px;">
            @php
                $isCritical = $level === 'critical';
                $diveDate = $batch->start_date->format('l, F d, Y');
            @endphp

            <h1 style="margin:0 0 12px;font-size:22px;">
                {{ $isCritical ? 'Your dive may not be safe' : 'Rough sea conditions expected' }}
            </h1>

            <p>Hello {{ $booking->contact_name }},</p>

            @if($isCritical)
                <p>
                    Our latest weather and sea check rates your dive on <strong>{{ $diveDate }}</strong> as
                    <strong style="color:#B91C1C;">Critical Risk</strong>. This means wind, waves or currents may be too dangerous for freediving.
                    Your trip starts in about <strong>{{ $hoursUntilDive }} {{ $hoursUntilDive === 1 ? 'hour' : 'hours' }}</strong>.
                </p>

                <p style="margin:20px 0 8px;font-weight:700;">Because of this, you can choose to:</p>
                <ul style="margin:0 0 16px;padding-left:20px;">
                    <li><strong>Reschedule for free</strong> to another available date, or</li>
                    <li><strong>Cancel</strong> and get a <strong>full refund of your downpayment</strong> (&#8369;{{ number_format($booking->downpayment_amount, 2) }}).</li>
                </ul>

                <p style="margin:24px 0;">
                    <a href="{{ $manageUrl }}" style="display:inline-block;background:#780000;color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:10px;">Reschedule or cancel my booking</a>
                </p>

                <p>
                    If you would like to keep your booking, you don't need to do anything. Our safety team makes the final go / no-go decision
                    before departure. If we cancel the trip, we will email you and you will get a full refund.
                </p>
            @else
                <p>
                    Our latest weather and sea check rates your dive on <strong>{{ $diveDate }}</strong> as
                    <strong style="color:#B45309;">High Risk</strong>. This means the sea may be rough (stronger wind, waves or currents than usual).
                    Your trip starts in about <strong>{{ $hoursUntilDive }} {{ $hoursUntilDive === 1 ? 'hour' : 'hours' }}</strong>.
                </p>

                <p><strong>Your dive is still going ahead as planned.</strong> To keep everyone safe, our coaches may:</p>
                <ul style="margin:0 0 16px;padding-left:20px;">
                    <li>choose more sheltered dive spots,</li>
                    <li>add extra safety divers, and</li>
                    <li>shorten or adjust the dive sessions.</li>
                </ul>

                <p>
                    You don't need to do anything right now. Our safety team makes the final go / no-go decision before departure.
                    If conditions get worse, we will email you again with your options.
                </p>
            @endif

            <table style="width:100%;border-collapse:collapse;margin:20px 0;">
                <tr><td style="padding:8px 0;color:#6E6E73;">Booking number</td><td style="padding:8px 0;font-weight:700;">{{ $booking->booking_number }}</td></tr>
                <tr><td style="padding:8px 0;color:#6E6E73;">Dive dates</td><td style="padding:8px 0;font-weight:700;">{{ $batch->start_date->format('M d, Y') }} - {{ $batch->end_date?->format('M d, Y') }}</td></tr>
                <tr><td style="padding:8px 0;color:#6E6E73;">Safety rating</td><td style="padding:8px 0;font-weight:700;">{{ $isCritical ? 'Critical Risk' : 'High Risk' }}</td></tr>
            </table>

            <p style="color:#6E6E73;font-size:13px;">
                Weather forecasts can change. We check conditions every hour and will keep you updated.
            </p>
        </div>
    </div>
</body>
</html>
