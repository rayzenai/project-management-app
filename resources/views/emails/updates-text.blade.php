Hello, {!! $firstName !!}.
@foreach ($items as $item)

{!! $item['title'] !!}
{!! $item['line'] !!}
{!! $item['url'] !!}
@endforeach

Open my work: {!! $myWorkUrl !!}

You get these when something is given to you, you are mentioned, or you join a project. Change your emails: {!! $settingsUrl !!}
