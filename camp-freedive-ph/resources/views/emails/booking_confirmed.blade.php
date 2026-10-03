    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Booking Confirmed - Camp FreedivePH</title>
        <style>
            body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; line-height: 1.6; color: #1D1D1F; background-color: #F2F2F7; margin: 0; padding: 20px 10px; font-size: 14px; }
            .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 0; overflow: hidden; border: 1px solid #E5E5EA; }
            .top-bar { width: 100%; border-collapse: collapse; background-color: #780000; color: #ffffff; }
            .hero-box { background: #F8EAEA; background: linear-gradient(to bottom, rgba(120, 0, 0, 0.12) 0%, rgba(120, 0, 0, 0) 100%); border-radius: 12px; padding: 28px 24px; text-align: center; margin-bottom: 24px; }
            .icon-cell { display: table-cell; vertical-align: middle; text-align: center; }
            .content { padding: 24px; }
            .pin-box { background: #00C3D0; border: 1.5px dashed #00C3D030; border-radius: 10px; padding: 18px; text-align: center; margin: 20px 0; }
            .pin-box .label { font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color: #FFFFFF; font-weight: 700; }
            .pin-box .number { font-size: 24px; font-weight: 800; color: #FFFFFF; margin: 4px 0; font-family: monospace; letter-spacing: 2px; }
            .pin-box .pin { font-size: 16px; font-weight: 600; color: #FFFFFF; }
            .table { width: 100%; border-collapse: collapse; margin: 20px 0; border: none !important; }
            .table, .table tr, .table th, .table td { padding: 10px 6px; text-align: left; border: none !important; border-bottom: none !important; font-size: 14px; vertical-align: top;}
            .table th { color: #6E6E73; font-weight: 600; width: 28%; padding-right: 10px; border: none !important; border-bottom: none !important; }
            .total-row { font-weight: 700; color: #1D1D1F; border: none !important; border-bottom: none !important; }
            .badge { display: inline-block; font-size: 13px; font-weight: 700; color: #065F46; }
            .btn { display: inline-block; background: #780000; color: #ffffff !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-weight: 700; font-size: 14px; margin-top: 8px; text-align: center; }
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
                    <h1 style="margin: 0 0 8px 0; font-size: 22px; font-weight: 800; color: #1D1D1F; letter-spacing: -0.2px;">Booking Confirmed</h1>
                    <p style="margin: 0 auto; font-size: 14px; color: #4A4A4F; line-height: 1.6; max-width: 480px;">
                        Hi <strong>{{ $booking->contact_name }}</strong>, thank you for booking with Camp FreedivePH. Your downpayment has been received and your slot is officially secured.
                    </p>
                </div>

                <!-- PIN & Access Box -->
                <div class="pin-box">
                    <div class="label">Your Access Credentials</div>
                    <div class="number">{{ $booking->booking_number }}</div>
                    <div class="pin">PIN: <strong>{{ $booking->pin }}</strong></div>
                    <p style="font-size: 13px; color: #FFFFFF; margin: 6px 0 0 0;">Save this email or screenshot these credentials to manage your booking anytime.</p>
                </div>

                <div style="text-align: center; margin-top: 24px;">
                    <a href="{{ route('manage.show', ['booking_number' => $booking->booking_number, 'pin' => $booking->pin]) }}" class="btn">Manage Your Booking Online</a>
                </div>

                <!-- Booking Summary Table -->
                <table class="table" border="0" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; border: none !important; margin: 20px 0;">
                    <tr style="border: none !important; border-bottom: none !important;">
                        <th style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; color: #6E6E73; font-weight: 600; width: 28%; vertical-align: top;">Class Package</th>
                        <td style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; vertical-align: top;"><strong>{{ $booking->formatted_class_type }}</strong></td>
                    </tr>
                    <tr style="border: none !important; border-bottom: none !important;">
                        <th style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; color: #6E6E73; font-weight: 600; width: 28%; vertical-align: top;">Trip Dates</th>
                        <td style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; vertical-align: top;">{{ $booking->start_date ? $booking->start_date->format('F d, Y (l)') : '' }} - {{ $booking->end_date ? $booking->end_date->format('F d, Y (l)') : '' }}</td>
                    </tr>
                    <tr style="border: none !important; border-bottom: none !important;">
                        <th style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; color: #6E6E73; font-weight: 600; width: 28%; vertical-align: top;">Participants ({{ $booking->participants ? $booking->participants->count() : 1 }})</th>
                        <td style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; vertical-align: top;">
                            @if($booking->participants && $booking->participants->count() > 0)
                                @foreach($booking->participants as $participant)
                                    <div>{{ $participant->name }} (Age {{ $participant->age }})</div>
                                @endforeach
                            @else
                                <div>{{ $booking->contact_name }}</div>
                            @endif
                        </td>
                    </tr>

                    <tr style="border: none !important; border-bottom: none !important;">
                        <th style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; color: #6E6E73; font-weight: 600; width: 28%; vertical-align: top;">Mabini LGU Pass &amp; Env. Fee</th>
                        <td style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; vertical-align: top;">
                            ₱{{ number_format(($booking->lgu_fee ?? 0) + ($booking->environmental_fee ?? 0), 2) }}
                            <span style="font-size: 12px; color: #6E6E73; display: block;">(₱350 / head × {{ $booking->participants ? $booking->participants->count() : 1 }} pax)</span>
                        </td>
                    </tr>

                    <tr class="total-row" style="border: none !important; border-bottom: none !important;">
                        <th style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; width: 28%; color: #1D1D1F; font-weight: 700; vertical-align: top;">Total Trip Cost</th>
                        <td style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; color: #1D1D1F; font-weight: 700; vertical-align: top;">₱{{ number_format($booking->total_amount, 2) }}</td>
                    </tr>

                    @if($booking->priceAdjustments && $booking->priceAdjustments->count() > 0)
                        <tr style="border: none !important; border-bottom: none !important;">
                            <th style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; color: #6E6E73; font-weight: 600; width: 28%; vertical-align: top;">Applied Pricing Rules</th>
                            <td style="border: none !important; border-bottom: none !important; padding: 10px 6px; text-align: left; vertical-align: top;">
                                @foreach($booking->priceAdjustments as $adj)
                                    <div style="font-size: 13px; margin-bottom: 3px;">
                                        <strong>{{ $adj->rule_name }}:</strong> 
                                        <span style="color: {{ $adj->adjustment_amount >= 0 ? '#D70015' : '#065F46' }}; font-weight: 700;">
                                            {{ $adj->adjustment_amount >= 0 ? '+' : '−' }}₱{{ number_format(abs($adj->adjustment_amount) * ($booking->participants ? $booking->participants->count() : 1), 2) }}
                                        </span>
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                    @endif

                    <!-- Downpayment + Balance -->
                    <tr style="border: none !important; border-bottom: none !important;">
                        <td colspan="2" style="padding: 8px 0 0 0; border: none !important; border-bottom: none !important;">
                            <table border="0" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; border: none !important;">
                                <tr style="border: none !important; border-bottom: none !important;">
                                    <th style="width: 50%; padding: 8px 6px 4px 6px; text-align: left; border: none !important; border-bottom: none !important; color: #6E6E73; font-weight: 600;">
                                        Downpayment Paid
                                    </th>
                                    <th style="width: 50%; padding: 8px 6px 4px 6px; text-align: left; border: none !important; border-bottom: none !important; color: #6E6E73; font-weight: 600;">
                                        Balance Due at Camp Check-in
                                    </th>
                                </tr>
                                <tr style="border: none !important; border-bottom: none !important;">
                                    <td style="width: 50%; padding: 2px 6px 8px 6px; border: none !important; border-bottom: none !important;">
                                        <span class="badge">₱{{ number_format($booking->downpayment_amount, 2) }} Paid</span>
                                    </td>
                                    <td style="width: 50%; padding: 2px 6px 8px 6px; border: none !important; border-bottom: none !important; color: #780000; font-weight: 700;">
                                        ₱{{ number_format($booking->balance_amount, 2) }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Transportation + Boat Dive -->
                    <tr style="border: none !important; border-bottom: none !important;">
                        <td colspan="2" style="padding: 8px 0; border: none !important; border-bottom: none !important;">
                            <table border="0" cellpadding="0" cellspacing="0" style="width: 100%; border-collapse: collapse; border: none !important;">
                                <tr style="border: none !important; border-bottom: none !important;">
                                    <th style="width: 50%; padding: 8px 6px 4px 6px; text-align: left; border: none !important; border-bottom: none !important; color: #6E6E73; font-weight: 600;">
                                        Transportation
                                    </th>
                                    <th style="width: 50%; padding: 8px 6px 4px 6px; text-align: left; border: none !important; border-bottom: none !important; color: #6E6E73; font-weight: 600;">
                                        Boat Dive
                                    </th>
                                </tr>
                                <tr style="border: none !important; border-bottom: none !important;">
                                    <td style="width: 50%; padding: 2px 6px 8px 6px; border: none !important; border-bottom: none !important;">
                                        @if($booking->pickup_option === 'carpool')
                                            Carpool: <strong>{{ $booking->pickup_location }}</strong>
                                        @else
                                            Own Transportation
                                        @endif
                                    </td>
                                    <td style="width: 50%; padding: 2px 6px 8px 6px; border: none !important; border-bottom: none !important;">
                                        {{ $booking->boat_dive ? 'Yes (Included)' : 'No' }}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                

                <!-- Things to Bring & Carpool Reminder -->
                <div style="margin-top: 20px; font-size: 14px;">
                    <h4 style="margin: 0 0 8px 0; color: #1D1D1F; font-size: 14px; font-weight: 700;">Things to Bring (Towels, shampoo and soap are all provided):</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #4A4A4F; line-height: 1.8; font-size: 13px;">
                        <li>Swimming clothes (anything you’re comfortable wearing)</li>
                        <li>Toiletries</li>
                        <li>Personal things</li>
                        <li>A pair of socks (in any kind) for fin fitting</li>
                    </ul>
                    @if($booking->pickup_option === 'carpool')
                    <div style="margin-top: 12px; padding-top: 6px;">
                        <h4 style="margin: 0 0 4px 0; color: #1D1D1F; font-size: 13px; font-weight: 700;">Carpool Reminder:</h4>
                        <p style="margin: 0; color: #4A4A4F; line-height: 1.5; font-size: 13px;">
                            Please arrive at your selected pickup location (<strong>{{ $booking->pickup_location }}</strong>) before the designated departure time. A strict <strong>30-minute grace period</strong> will be provided before the van departs but we are kindly asking to not maximize it.
                        </p>
                    </div>
                    @endif
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
