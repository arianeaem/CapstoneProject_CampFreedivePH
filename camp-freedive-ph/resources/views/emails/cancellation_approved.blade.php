<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellation Request Approved - Camp FreedivePH</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1D1D1F; background-color: #F2F2F7; margin: 0; padding: 20px 10px; font-size: 14px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 0; overflow: hidden; border: 1px solid #E5E5EA; }
        .top-bar { width: 100%; border-collapse: collapse; background-color: #780000; color: #ffffff; }
        .hero-box { background: #ECFDF5; background: linear-gradient(to bottom, rgba(16, 185, 129, 0.12) 0%, rgba(16, 185, 129, 0) 100%); border-radius: 12px; padding: 28px 24px; text-align: center; margin-bottom: 24px; }
        .icon-cell { display: table-cell; vertical-align: middle; text-align: center; }
        .content { padding: 24px; }
        .status-box { padding: 16px; color: #991B1B; margin: 20px 0; }
        .refund-box { padding: 16px; color: #065F46; margin: 20px 0; }
        .btn { display: inline-block; background: #780000; color: #ffffff !important; text-decoration: none; padding: 12px 24px; border-radius: 8px; font-weight: 700; font-size: 14px; text-align: center; }
        .footer-gradient { background: #380000; background: linear-gradient(180deg, #470000 0%, #220000 100%); padding: 32px 24px 28px 24px; text-align: center; color: #ffffff; }
        .footer-sub { background: #ffffff; padding: 16px 20px; text-align: center; font-size: 12px; color: #6E6E73; border-top: 1px solid #E5E5EA; }
    </style>
</head>
<body>
    <div class="container">
        <!-- Top Bar Header with Background #780000 and Logo -->
        <table class="top-bar">
            <tr>
                <td style="padding: 16px 24px; text-align: left; vertical-align: middle;">
                    <table style="border-collapse: collapse;">
                        <tr>
                            <td style="vertical-align: middle;">
                                <span style="font-size: 16px; font-weight: 800; color: #ffffff; letter-spacing: -0.2px;">Camp FreedivePH</span>
                            </td>
                        </tr>
                    </table>
                </td>
                <td style="padding: 16px 24px; text-align: right; vertical-align: middle; font-size: 13px; color: rgba(255, 255, 255, 0.9); font-weight: 500;">
                    {{ now()->setTimezone('Asia/Manila')->format('l, F jS, Y') }}
                </td>
            </tr>
        </table>

        <div class="content">
            <!-- Centered Hero Subject Card with gradient & subtle curve -->
            <div class="hero-box">
                <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #1D1D1F; letter-spacing: -0.2px;">Cancellation Approved</h1>
                <p style="margin: 0 auto; font-size: 14px; color: #4A4A4F; line-height: 1.6; max-width: 480px;">
                    Hi <strong>{{ $booking->contact_name }}</strong>, your cancellation request for Booking <strong>#{{ $booking->booking_number }}</strong> has been approved and processed.
                </p>
            </div>

            @if(!$isForfeited && $refundAmount > 0)
                <div class="refund-box">
                    <strong>Refund Approved & Queued for Processing</strong><br>
                    <span>Approved Refund Amount: <strong>₱{{ number_format($refundAmount, 2) }}</strong></span><br>
                    <span style="font-size: 13px;">Our finance staff has queued your refund back to your original payment method.</span>
                </div>
            @else
                <div class="status-box">
                    <strong>Cancellation Approved (Downpayment Forfeited)</strong><br>
                    <span style="font-size: 13px;">Per camp policy, cancellations made within 14 days of departure result in downpayment forfeiture.</span>
                </div>
            @endif

            <table border="0" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; border: none !important;">
                <tr style="border: none !important; border-bottom: none !important;">
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important; border-bottom: none !important;">Cancelled Dates:</td>
                    <td style="padding: 10px 0; font-weight: 600; border: none !important; border-bottom: none !important;">{{ $booking->start_date ? $booking->start_date->format('M d, Y') : '' }} - {{ $booking->end_date ? $booking->end_date->format('M d, Y') : '' }}</td>
                </tr>
                <tr style="border: none !important; border-bottom: none !important;">
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important; border-bottom: none !important;">Booking Reference:</td>
                    <td style="padding: 10px 0; font-weight: 700; color: #780000; font-family: monospace; border: none !important; border-bottom: none !important;">{{ $booking->booking_number }}</td>
                </tr>
                <tr style="border: none !important; border-bottom: none !important;">
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important; border-bottom: none !important;">Total Paid:</td>
                    <td style="padding: 10px 0; font-weight: 600; border: none !important; border-bottom: none !important;">₱{{ number_format($booking->paid_amount, 2) }}</td>
                </tr>
            </table>

            <p style="font-size: 13px; color: #6E6E73;">We hope to welcome you to the water on another weekend. You can review your account history anytime via Manage Booking.</p>
            
            <div style="text-align: center; margin-top: 24px;">
                <a href="{{ url('/manage-booking/' . $booking->booking_number) }}" class="btn">View Cancellation Summary</a>
            </div>
        </div>

        <!-- Gradient Footer with Logo -->
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

        <!-- Sub Footer Disclaimer -->
        <div class="footer-sub">
            You're receiving this email because you booked a trip with Camp FreedivePH.
        </div>
    </div>
</body>
</html>
