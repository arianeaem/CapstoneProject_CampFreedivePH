<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Your Password - Camp FreedivePH</title>
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1D1D1F; background-color: #F2F2F7; margin: 0; padding: 20px 10px; font-size: 14px; }
        .container { max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 0; overflow: hidden; border: 1px solid #E5E5EA; }
        .top-bar { width: 100%; border-collapse: collapse; background-color: #780000; color: #ffffff; }
        .hero-box { background: #F8EAEA; background: linear-gradient(to bottom, rgba(120, 0, 0, 0.12) 0%, rgba(120, 0, 0, 0) 100%); border: 1px solid #F1D5D5; border-radius: 12px; padding: 28px 24px; text-align: center; margin-bottom: 24px; }
        .icon-cell { display: table-cell; vertical-align: middle; text-align: center; }
        .content { padding: 24px; }
        .reset-box { background: #F8EAEA; border: 1px solid #F1D5D5; border-radius: 10px; padding: 20px; text-align: center; margin: 20px 0; }
        .btn { display: inline-block; background: #780000; color: #ffffff !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 700; font-size: 14px; text-align: center; }
        .url-text { word-break: break-all; color: #780000; font-size: 13px; }
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
                <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #1D1D1F; letter-spacing: -0.2px;">Reset Your Password</h1>
                <p style="margin: 0 auto; font-size: 14px; color: #4A4A4F; line-height: 1.6; max-width: 440px;">
                    Hello <strong>{{ $user->name }}</strong>, we received a request to reset the password for your Camp FreedivePH staff account (<strong>{{ $user->email }}</strong>).
                </p>
            </div>

            <div class="reset-box">
                <p style="margin: 0 0 14px 0; font-size: 14px; color: #470000; font-weight: 700;">Click the button below to choose a new password:</p>
                <a href="{{ $resetUrl }}" class="btn" target="_blank">Reset My Password</a>
                <p style="font-size: 13px; color: #6E6E73; margin: 14px 0 0 0;">This password reset link will expire in 60 minutes.</p>
            </div>

            <p style="font-size: 13px; color: #6E6E73; line-height: 1.5;">If you did not request a password reset, no further action is required and your account remains safe.</p>

            <div style="margin: 20px 0;"></div>

            <p style="font-size: 12px; color: #8E8E93; line-height: 1.5;">If you're having trouble clicking the button, copy and paste the URL below into your web browser:</p>
            <p class="url-text">{{ $resetUrl }}</p>
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
            You're receiving this email because you hold an account with Camp FreedivePH.
        </div>
    </div>
</body>
</html>
