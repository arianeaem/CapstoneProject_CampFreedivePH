<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px 12px;background:#F2F2F7;color:#1D1D1F;font-family:Arial,sans-serif;line-height:1.6;">
<div style="max-width:600px;margin:0 auto;background:#fff;border:1px solid #E5E5EA;">
    <div style="padding:18px 24px;background:#780000;color:#fff;font-weight:800;">Camp FreedivePH Operations</div>
    <div style="padding:28px 24px;">
        <h1 style="margin:0 0 12px;font-size:22px;">{{ $eventTitle }}</h1>
        <p>{{ $message }}</p>
        <table style="width:100%;border-collapse:collapse;">
            @foreach($details as $label => $value)
                <tr><td style="padding:8px 0;color:#6E6E73;">{{ $label }}</td><td style="padding:8px 0;font-weight:700;">{{ $value }}</td></tr>
            @endforeach
        </table>
        <p style="color:#6E6E73;font-size:13px;">Please review the relevant admin module and take action if needed.</p>
    </div>
</div>
</body>
</html>
