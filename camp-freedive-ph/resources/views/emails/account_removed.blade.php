<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account removed - Camp FreedivePH</title>
</head>
<body style="margin:0;padding:24px 12px;background:#F2F2F7;color:#1D1D1F;font-family:Arial,sans-serif;line-height:1.6;">
    <div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #E5E5EA;">
        <div style="padding:18px 24px;background:#780000;color:#fff;font-weight:800;">Camp FreedivePH</div>
        <div style="padding:28px 24px;">
            <h1 style="margin:0 0 12px;font-size:22px;">Your account has been removed</h1>
            <p>Hello {{ $user->name }},</p>
            <p>Your Camp FreedivePH staff account has been removed, so you can no longer sign in to the staff portal.</p>
            <table style="width:100%;border-collapse:collapse;margin:20px 0;">
                <tr><td style="padding:8px 0;color:#6E6E73;">Account</td><td style="padding:8px 0;font-weight:700;">{{ $user->email }}</td></tr>
                <tr><td style="padding:8px 0;color:#6E6E73;">Removed on</td><td style="padding:8px 0;font-weight:700;">{{ now('Asia/Manila')->format('M d, Y g:i A') }}</td></tr>
                <tr><td style="padding:8px 0;color:#6E6E73;">Removed by</td><td style="padding:8px 0;font-weight:700;">{{ $removedByName }}</td></tr>
            </table>
            <p>Your past records (such as dives you coached) are kept for the camp's history. If you think this is a mistake, please contact the camp owner.</p>
            <p style="color:#6E6E73;font-size:13px;">Thank you for diving with Camp FreedivePH.</p>
        </div>
    </div>
</body>
</html>
