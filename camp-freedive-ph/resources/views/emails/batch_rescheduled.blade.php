<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Camp Schedule Rescheduled - Camp FreedivePH</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1D1D1F; background-color: #F2F2F7; margin: 0; padding: 20px 10px; font-size: 14px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 0; overflow: hidden; border: 1px solid #E5E5EA; }
        .top-bar { width: 100%; border-collapse: collapse; background-color: #780000; color: #ffffff; }
        .hero-box { background: #FEF3C7; background: linear-gradient(to bottom, rgba(245, 158, 11, 0.12) 0%, rgba(245, 158, 11, 0.02) 100%); border-radius: 12px; padding: 28px 24px; text-align: center; margin-bottom: 24px; }
        .content { padding: 24px; }
        .alert-box { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 10px; padding: 16px; color: #92400E; margin: 20px 0; }
        .protection-box { background: #ECFDF5; border: 1px solid #A7F3D0; border-radius: 10px; padding: 16px; color: #065F46; margin: 20px 0; }
        .pin-badge { display: inline-block; background: #F2F2F7; border: 1px solid #D1D1D6; border-radius: 6px; padding: 3px 8px; font-family: monospace; font-size: 14px; font-weight: 700; color: #1D1D1F; letter-spacing: 2px; }
        .btn { display: inline-block; background: #780000; color: #ffffff !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 700; font-size: 14px; text-align: center; }
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
            <!-- Centered Hero Subject Card -->
            <div class="hero-box">
                <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #1D1D1F; letter-spacing: -0.2px;">Camp Schedule Rescheduled</h1>
                <p style="margin: 0 auto; font-size: 14px; color: #4A4A4F; line-height: 1.6; max-width: 500px;">
                    Hi <strong>{{ $booking->contact_name }}</strong>, we are writing to inform you that your upcoming 2D1N freediving session with <strong>{{ $batch->name }}</strong> (originally scheduled for <strong>{{ $batch->start_date ? $batch->start_date->format('M d, Y') : ($booking->start_date ? $booking->start_date->format('M d, Y') : '') }} - {{ $batch->end_date ? $batch->end_date->format('M d, Y') : ($booking->end_date ? $booking->end_date->format('M d, Y') : '') }}</strong>) has been rescheduled by camp administration.
                </p>
            </div>

            <!-- Reschedule Reason / Advisory -->
            <div class="alert-box">
                <strong style="display: block; margin-bottom: 4px; font-size: 14px; color: #78350F;">Rescheduling Advisory / Reason:</strong>
                <span style="font-size: 13px; line-height: 1.5; color: #92400E;">{{ $rescheduleReason ?: 'Operational schedule adjustment by Camp Management.' }}</span>
            </div>

            <!-- 100% Protection Reassurance -->
            <div class="protection-box">
                <strong style="display: block; margin-bottom: 4px; font-size: 14px; color: #065F46;">100% Downpayment Protected & Free Reschedule:</strong>
                <span style="font-size: 13px; line-height: 1.5;">
                    Your booking balance and paid downpayment of <strong>₱{{ number_format($booking->downpayment_amount ?? 0, 2) }}</strong> are 100% preserved. Your booking has been unlocked so you can choose a new preferred camp date at no extra fee.
                </span>
            </div>

            <!-- Booking Summary Details -->
            <table border="0" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; margin: 20px 0; font-size: 14px; border: none !important;">
                <tr style="border: none !important; border-bottom: none !important;">
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important; border-bottom: none !important;">Booking Reference:</td>
                    <td style="padding: 10px 0; font-weight: 700; color: #780000; font-family: monospace; font-size: 14px; border: none !important; border-bottom: none !important;">{{ $booking->booking_number }}</td>
                </tr>
                <tr style="border: none !important; border-bottom: none !important;">
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important; border-bottom: none !important;">Booking PIN:</td>
                    <td style="padding: 10px 0; border: none !important; border-bottom: none !important;"><span class="pin-badge">{{ $booking->pin }}</span></td>
                </tr>
                <tr style="border: none !important; border-bottom: none !important;">
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important; border-bottom: none !important;">Course Package:</td>
                    <td style="padding: 10px 0; font-weight: 600; text-transform: capitalize; border: none !important; border-bottom: none !important;">{{ $booking->class_type }} Class ({{ $booking->participants_count ?? ($booking->participants ? $booking->participants->count() : 1) }} Participant/s)</td>
                </tr>
                <tr style="border: none !important; border-bottom: none !important;">
                    <td style="padding: 10px 0; color: #6E6E73; border: none !important; border-bottom: none !important;">Total Downpayment Paid:</td>
                    <td style="padding: 10px 0; font-weight: 700; color: #065F46; border: none !important; border-bottom: none !important;">₱{{ number_format($booking->downpayment_amount ?? 0, 2) }}</td>
                </tr>
            </table>

            <p style="font-size: 13px; color: #6E6E73; line-height: 1.5; margin: 16px 0 24px 0;">
                Click below to open your Manage Booking portal using your PIN (<strong>{{ $booking->pin }}</strong>) to review upcoming weekend dates and select your new schedule.
            </p>

            <div style="text-align: center; margin: 24px 0;">
                <a href="{{ route('manage.show', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin]) }}" class="btn">Select New Date in Manage Booking</a>
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
            You're receiving this email because you have an active booking with Camp FreedivePH.
        </div>
    </div>
</body>
</html>
