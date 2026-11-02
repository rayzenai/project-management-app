{{--
    Every workspace email: one white card on a grey ground with the app's
    mark and name, and a quiet line under the card (why you get it and where
    to change that). Inline styles and tables only, so mail clients that
    drop <style> still draw it; the one media query only tightens phones.
    Sections: title (the document title), content (inside the card), footer.
--}}
@php($font = "Geist,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif")
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>@yield('title', config('app.name'))</title>
<style>
@media (max-width: 520px) {
    .ws-outer { padding: 16px 8px !important; }
    .ws-card { padding: 24px 20px 22px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background:#eef0f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef0f4;">
<tr>
<td class="ws-outer" align="center" style="padding:36px 16px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:640px;">
<tr>
<td class="ws-card" style="padding:32px 40px 28px;background:#ffffff;border:1px solid #e3e6eb;border-radius:12px;font-family:{!! $font !!};color:#14171f;text-align:left;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0">
<tr>
<td width="26" height="26" align="center" valign="middle" style="width:26px;height:26px;border-radius:7px;background:#2f62d8;color:#ffffff;font-family:{!! $font !!};font-size:12px;line-height:26px;font-weight:700;">{{ mb_strtoupper(mb_substr(config('app.name'), 0, 1)) }}</td>
<td style="padding-left:10px;font-family:{!! $font !!};font-size:14.5px;line-height:20px;font-weight:600;color:#14171f;">{{ config('app.name') }}</td>
</tr>
</table>
@yield('content')
</td>
</tr>
<tr>
<td style="padding:16px 4px 0;font-family:{!! $font !!};font-size:13px;line-height:20px;color:#7a808c;text-align:left;">@yield('footer')</td>
</tr>
</table>
</td>
</tr>
</table>
</body>
</html>
