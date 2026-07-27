<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Workspace') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=syne:600,700,800|hanken-grotesk:400,500,600|ibm-plex-mono:400,500,600|inter:400,500,600|inter-tight:500,600,700|jetbrains-mono:400,500|fraunces:500,600,700|newsreader:400,500|space-grotesk:500,600,700" rel="stylesheet" />

        {{--
            The package path below depends on how this package is installed:
              composer install -> vendor/rayzenai/project-management/...
              path repo / monorepo -> packages/project-management/...
            It MUST match the input you add to vite.config.* (see the README).
        --}}
        @vite(['vendor/rayzenai/project-management/resources/js/styles/workspace.css', 'resources/js/workspace/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
