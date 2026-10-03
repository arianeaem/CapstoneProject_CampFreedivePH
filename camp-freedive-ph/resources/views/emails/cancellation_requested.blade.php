<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cancellation Request Received - Camp FreedivePH</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1D1D1F; background-color: #F2F2F7; margin: 0; padding: 20px 10px; font-size: 14px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 0; overflow: hidden; border: 1px solid #E5E5EA; }
        .top-bar { width: 100%; border-collapse: collapse; background-color: #780000; color: #ffffff; }
        .hero-box { background: #FEF2F2; background: linear-gradient(to bottom, rgba(220, 38, 38, 0.12) 0%, rgba(220, 38, 38, 0) 100%); border-radius: 12px; padding: 28px 24px; text-align: center; margin-bottom: 24px; }
        .icon-cell { display: table-cell; vertical-align: middle; text-align: center; }
        .content { padding: 24px; }
        .status-box { padding: 24px; color: #991B1B; margin: 20px 0; }
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
                <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #1D1D1F; letter-spacing: -0.2px;">Cancellation Request Received</h1>
                <p style="margin: 0 auto; font-size: 14px; color: #4A4A4F; line-height: 1.6; max-width: 480px;">
                    Hi <strong>{{ $booking->contact_name }}</strong>, we received your request to cancel Booking <strong>#{{ $booking->booking_number }}</strong>.
                </p>
            </div>

            <div class="status-box">
                <strong>Status: Cancellation Pending Camp Review</strong><br>
                Calculated Policy Refund: <strong>₱{{ number_format($cancellationRequest->calculated_refund_amount, 2) }}</strong>
                @if($cancellationRequest->force_majeure_flag)
                    <br><span style="font-size: 13px; color: #991B1B;">(Force Majeure / Marine Safety Advisory applied)</span>
                @endif
            </div>

            <p style="font-size: 14px; color: #1D1D1F; line-height: 1.6;">
                Our finance and booking team is reviewing your request according to the camp cancellation policy. Approved refunds are processed back via GCash or original payment channel within 3-5 banking days.
            </p>

            @if($cancellationRequest->reason)
            <div style="border-radius: 8px; font-size: 13px; color: #6E6E73; margin: 16px 0;">
                <strong>Reason Provided:</strong> {{ $cancellationRequest->reason }}
            </div>
            @endif

            <div style="text-align: center; margin-top: 24px;">
                <a href="{{ url('/manage-booking/' . $booking->booking_number) }}" class="btn">View Booking Details</a>
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
