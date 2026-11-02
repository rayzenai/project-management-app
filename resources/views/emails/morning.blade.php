@extends('emails.layout')

@section('title', $subjectLine)

@section('content')
<h1 style="margin:26px 0 0;font-size:24px;line-height:30px;font-weight:600;letter-spacing:-0.01em;color:#14171f;">Good morning, {{ $firstName }}.</h1>
<p style="margin:6px 0 0;font-size:15px;line-height:22px;color:#636a78;">{{ $dateLine }}@if ($bs) · <span style="font-family:Mukta,Geist,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">{{ $bs }}</span>@endif</p>
@foreach ($sections as $section)
<p style="margin:26px 0 8px;font-size:13px;line-height:18px;font-weight:600;color:#636a78;">{{ $section['heading'] }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
@foreach ($section['rows'] as $row)
<tr>
<td style="padding:12px 0;border-top:1px solid #eceef2;">
<a href="{{ $row['url'] }}" style="display:block;font-size:16px;line-height:22px;font-weight:500;color:#14171f;text-decoration:none;">{{ $row['title'] }}</a>
<span style="display:block;margin-top:3px;font-size:14px;line-height:20px;color:#636a78;">{{ $row['meta'] }}@if ($row['late']) · <span style="color:#c2372d;font-weight:500;">{{ $row['late'] }}</span>@endif</span>
</td>
</tr>
@endforeach
</table>
@endforeach
<p style="margin:26px 0 0;"><a href="{{ $myWorkUrl }}" style="display:inline-block;padding:0 16px;height:40px;line-height:40px;border-radius:8px;background:#2f62d8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">Open my work</a></p>
@endsection

@section('footer')
You get this on working days at {{ $sentAt }}, only when something is waiting for you. <a href="{{ $settingsUrl }}" style="color:#2f62d8;text-decoration:none;">Change your emails</a>
@endsection
