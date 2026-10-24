<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Received - Camp FreedivePH</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1D1D1F; background-color: #F2F2F7; margin: 0; padding: 20px 10px; font-size: 14px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 0; overflow: hidden; border: 1px solid #E5E5EA; }
        .top-bar { width: 100%; border-collapse: collapse; background-color: #780000; color: #ffffff; }
        .hero-box { background: #FFFBEB; background: linear-gradient(to bottom, rgba(217, 119, 6, 0.12) 0%, rgba(217, 119, 6, 0) 100%); border: 1px solid #FDE68A; border-radius: 12px; padding: 28px 24px; text-align: center; margin-bottom: 24px; }
        .content { padding: 24px; }
        .status-box { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; padding: 16px; color: #92400E; margin: 20px 0; }
        .footer-gradient { background: #380000; background: linear-gradient(180deg, #470000 0%, #220000 100%); padding: 32px 24px 28px 24px; text-align: center; color: #ffffff; }
        .footer-sub { background: #ffffff; padding: 16px 20px; text-align: center; font-size: 12px; color: #6E6E73; border-top: 1px solid #E5E5EA; }
    </style>
</head>
<body>
    <div class="container">
        <table class="top-bar">
            <tr>
                <td style="padding: 16px 24px; text-align: left; vertical-align: middle;">
                    <span style="font-size: 16px; font-weight: 800; color: #ffffff; letter-spacing: -0.2px;">Camp FreedivePH</span>
                </td>
                <td style="padding: 16px 24px; text-align: right; vertical-align: middle; font-size: 13px; color: rgba(255, 255, 255, 0.9); font-weight: 500;">
                    {{ now()->setTimezone('Asia/Manila')->format('l, F jS, Y') }}
                </td>
            </tr>
        </table>

        <div class="content">
            <div class="hero-box">
                <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #1D1D1F; letter-spacing: -0.2px;">We Received Your Payment</h1>
                <p style="margin: 0 auto; font-size: 14px; color: #4A4A4F; line-height: 1.6; max-width: 480px;">
                    Hi <strong>{{ $contactName }}</strong>, thank you. Your downpayment for checkout <strong>{{ $intent->reference }}</strong> was received.
                </p>
            </div>

            <div class="status-box">
                <strong>Your booking is not confirmed yet</strong><br>
                <span style="font-size: 13px;">The dates you chose filled up before your booking could be completed, so no booking has been created. Our team has been notified and will contact you about the next steps. You do not need to pay again.</span>
            </div>

            <table border="0" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; border: none !important;">
                <tr>
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important;">Checkout reference:</td>
                    <td style="padding: 10px 0; font-weight: 700; border: none !important;">{{ $intent->reference }}</td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important;">Requested dates:</td>
                    <td style="padding: 10px 0; font-weight: 700; border: none !important;">{{ $intent->start_date?->format('M d, Y') }} - {{ $intent->end_date?->format('M d, Y') }}</td>
                </tr>
                @if($intent->paid_amount !== null)
                <tr>
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important;">Amount received:</td>
                    <td style="padding: 10px 0; font-weight: 700; border: none !important;">₱{{ number_format((float) $intent->paid_amount, 2) }}</td>
                </tr>
                @endif
            </table>

            <p style="font-size: 13px; color: #6E6E73;">Please keep this email and your checkout reference. If you have questions, reply to this email or contact the camp coordinators.</p>
        </div>

        <div class="footer-gradient">
            <h3 style="margin: 0 0 12px 0; font-size: 18px; font-weight: 800; color: #ffffff; letter-spacing: 0.2px;">Camp FreedivePH</h3>
            <p style="margin: 0 0 14px 0; font-size: 13px;">
                <a href="https://www.facebook.com/Campfreediveph/" target="_blank" rel="noopener noreferrer" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">Facebook</a>
                <a href="https://www.instagram.com/campfreediveph/" target="_blank" rel="noopener noreferrer" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">Instagram</a>
                <a href="mailto:campfreediveph@gmail.com" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">campfreediveph@gmail.com</a>
                <a href="tel:+639278879894" style="color: #ffffff; text-decoration: none; margin: 0 8px; font-weight: 600;">+63 927 887 9894</a>
            </p>
            <p style="margin: 0 auto; font-size: 12px; color: rgba(255, 255, 255, 0.75); line-height: 1.6; max-width: 440px;">
                The Shack Hideaway by Mayumi Resorts, Sitio Bagalangit Road, Barangay Bagalangit, Anilao, Mabini, Batangas, Philippines
            </p>
        </div>

        <div class="footer-sub">
            You're receiving this email because you started a booking with Camp FreedivePH.
        </div>
    </div>
</body>
</html>
