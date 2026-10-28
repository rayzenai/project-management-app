<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Your login for {{ config('app.name') }} Workspace</title>
</head>
<body style="margin:0;padding:24px 16px;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
<div style="max-width:520px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:24px;">
<p style="margin:0 0 16px;font-size:15px;">Hello {{ $name }},</p>
<p style="margin:0 0 16px;font-size:14px;line-height:1.5;">{{ $sentBy }} has created a login for you on the {{ config('app.name') }} Workspace.</p>
<table role="presentation" style="margin:0 0 20px;font-size:14px;border-collapse:collapse;">
<tr><td style="padding:4px 16px 4px 0;color:#6b7280;">Email</td><td style="padding:4px 0;font-family:Menlo,Consolas,monospace;">{{ $email }}</td></tr>
<tr><td style="padding:4px 16px 4px 0;color:#6b7280;">Password</td><td style="padding:4px 0;font-family:Menlo,Consolas,monospace;">{{ $password }}</td></tr>
</table>
<p style="margin:0 0 20px;"><a href="{{ $loginUrl }}" style="display:inline-block;background:#3b6fd8;color:#ffffff;text-decoration:none;font-size:14px;padding:9px 16px;border-radius:6px;">Sign in</a></p>
<p style="margin:0;font-size:13px;line-height:1.5;color:#6b7280;">Keep this password to yourself. If you need it changed, ask {{ $sentBy }}.</p>
</div>
</body>
</html>
