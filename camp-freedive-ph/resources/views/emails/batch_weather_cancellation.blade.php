<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your dive is cancelled - Camp FreedivePH</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1D1D1F; background-color: #F2F2F7; margin: 0; padding: 20px 10px; font-size: 14px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; overflow: hidden; border: 1px solid #E5E5EA; }
        .top-bar { width: 100%; border-collapse: collapse; background-color: #780000; color: #ffffff; }
        .content { padding: 28px 24px; }
        .reason-box { background: #FEF2F2; border-radius: 10px; padding: 14px 16px; color: #991B1B; margin: 18px 0; }
        .refund-box { background: #ECFDF5; border-radius: 10px; padding: 14px 16px; color: #065F46; margin: 18px 0; }
        .pin-badge { display: inline-block; background: #F2F2F7; border-radius: 6px; padding: 2px 8px; font-family: monospace; font-size: 14px; font-weight: 700; color: #1D1D1F; letter-spacing: 2px; }
        .btn { display: inline-block; background: #780000; color: #ffffff !important; text-decoration: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; font-size: 14px; }
        .footer-gradient { background: #380000; background: linear-gradient(180deg, #470000 0%, #220000 100%); padding: 28px 24px; text-align: center; color: #ffffff; }
        .footer-sub { background: #ffffff; padding: 16px 20px; text-align: center; font-size: 12px; color: #6E6E73; border-top: 1px solid #E5E5EA; }
    </style>
</head>
<body>
    @php
        $dates = ($booking->start_date?->format('l, F d, Y') ?? '')
            . ($booking->end_date && !$booking->end_date->isSameDay($booking->start_date) ? ' to ' . $booking->end_date->format('l, F d, Y') : '');
    @endphp
    <div class="container">
        <table class="top-bar">
            <tr>
                <td style="padding: 16px 24px; font-size: 16px; font-weight: 800; color: #ffffff;">Camp FreedivePH</td>
                <td style="padding: 16px 24px; text-align: right; font-size: 13px; color: rgba(255,255,255,0.9);">
                    {{ now()->setTimezone('Asia/Manila')->format('l, F jS, Y') }}
                </td>
            </tr>
        </table>

        <div class="content">
            <h1 style="margin: 0 0 12px; font-size: 22px; font-weight: 800;">Your dive has been cancelled for your safety</h1>

            <p>Hello {{ $booking->contact_name }},</p>

            <p>
                We're sorry, but we had to cancel your freediving trip on <strong>{{ $dates }}</strong>.
                Our safety team decided the sea will not be safe for diving on those dates.
            </p>

            <div class="reason-box">
                <strong style="display: block; margin-bottom: 2px;">Why we cancelled</strong>
                <span>{{ $cancellationReason }}</span>
            </div>

            <div class="refund-box">
                <strong style="display: block; margin-bottom: 2px;">You will not lose any money</strong>
                @if($refundAmount > 0)
                    We have already started a <strong>full refund of the &#8369;{{ number_format($refundAmount, 2) }}</strong> you paid.
                    It goes back to the payment method you used. You don't need to do anything.
                @else
                    We have not received any payment for this booking yet, so there is nothing to refund and nothing for you to pay.
                @endif
            </div>

            <p><strong>Would you rather dive on another date?</strong> Just reply to this email or message us, and we will move your booking to another available date for free instead of refunding you.</p>

            <p style="margin: 24px 0;">
                <a href="{{ route('manage.show', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin]) }}" class="btn">View my booking</a>
            </p>

            <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
                <tr><td style="padding: 8px 0; color: #6E6E73;">Booking number</td><td style="padding: 8px 0; font-weight: 700;">{{ $booking->booking_number }}</td></tr>
                <tr><td style="padding: 8px 0; color: #6E6E73;">PIN</td><td style="padding: 8px 0;"><span class="pin-badge">{{ $booking->pin }}</span></td></tr>
                <tr><td style="padding: 8px 0; color: #6E6E73;">Cancelled dates</td><td style="padding: 8px 0; font-weight: 700;">{{ $booking->start_date?->format('M d, Y') }}@if($booking->end_date) - {{ $booking->end_date->format('M d, Y') }}@endif</td></tr>
                <tr><td style="padding: 8px 0; color: #6E6E73;">Refund</td><td style="padding: 8px 0; font-weight: 700; color: #065F46;">&#8369;{{ number_format($refundAmount, 2) }} (100%)</td></tr>
            </table>

            <p style="color: #6E6E73; font-size: 13px;">Thank you for understanding. We hope to see you in the water soon.</p>
        </div>

        <div class="footer-gradient">
            <h3 style="margin: 0 0 12px; font-size: 18px; font-weight: 800; color: #ffffff;">Camp FreedivePH</h3>
            <p style="margin: 0 0 14px; font-size: 13px;">
                <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">Facebook</a>
                <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">Instagram</a>
                <a href="mailto:campfreediveph@gmail.com" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">campfreediveph@gmail.com</a>
                <a href="tel:+639278879894" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">+63 927 887 9894</a>
            </p>
            <p style="margin: 0 auto; font-size: 12px; color: rgba(255,255,255,0.75); max-width: 440px;">
                The Shack Hideaway by Mayumi Resorts, Sitio Bagalangit Road, Barangay Bagalangit, Anilao, Mabini, Batangas, Philippines
            </p>
        </div>
        <div class="footer-sub">You're receiving this email because you booked a trip with Camp FreedivePH.</div>
    </div>
</body>
</html>
