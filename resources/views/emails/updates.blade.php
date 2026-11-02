@extends('emails.layout')

@section('title', count($items) === 1 ? $items[0]['title'] : count($items).' updates for you')

@section('content')
<h1 style="margin:26px 0 0;font-size:24px;line-height:30px;font-weight:600;letter-spacing:-0.01em;color:#14171f;">Hello, {{ $firstName }}.</h1>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">
@foreach ($items as $item)
<tr>
<td style="padding:12px 0;border-top:1px solid #eceef2;">
<a href="{{ $item['url'] }}" style="display:block;font-size:16px;line-height:22px;font-weight:500;color:#14171f;text-decoration:none;">{{ $item['title'] }}</a>
<span style="display:block;margin-top:3px;font-size:14px;line-height:20px;color:#636a78;">{{ $item['line'] }}</span>
</td>
</tr>
@endforeach
</table>
<p style="margin:26px 0 0;"><a href="{{ $myWorkUrl }}" style="display:inline-block;padding:0 16px;height:40px;line-height:40px;border-radius:8px;background:#2f62d8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">Open my work</a></p>
@endsection

@section('footer')
You get these when something is given to you, you are mentioned, or you join a project. <a href="{{ $settingsUrl }}" style="color:#2f62d8;text-decoration:none;">Change your emails</a>
@endsection
