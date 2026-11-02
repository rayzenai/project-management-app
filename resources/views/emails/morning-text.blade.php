Good morning, {!! $firstName !!}.
{!! $dateLine !!}@if ($bs) · {!! $bs !!}@endif

@foreach ($sections as $section)

{!! $section['heading'] !!}
@foreach ($section['rows'] as $row)

{!! $row['title'] !!}
{!! $row['meta'] !!}@if ($row['late']) · {!! $row['late'] !!}@endif

{!! $row['url'] !!}
@endforeach
@endforeach

Open my work: {!! $myWorkUrl !!}

You get this on working days at {!! $sentAt !!}, only when something is waiting for you. Change your emails: {!! $settingsUrl !!}
