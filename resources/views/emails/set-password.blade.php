@extends('emails.layout')

@section('title', $subjectLine)

@section('content')
<h1 style="margin:26px 0 0;font-size:24px;line-height:30px;font-weight:600;letter-spacing:-0.01em;color:#14171f;">{{ $subjectLine }}</h1>
<p style="margin:14px 0 0;font-size:16px;line-height:24px;color:#14171f;">{{ $body }}</p>
<p style="margin:26px 0 0;"><a href="{{ $url }}" style="display:inline-block;padding:0 16px;height:40px;line-height:40px;border-radius:8px;background:#2f62d8;color:#ffffff;font-size:15px;font-weight:600;text-decoration:none;">Set my password</a></p>
@endsection
