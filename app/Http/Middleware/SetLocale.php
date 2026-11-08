<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Answers in the person's language (App\Support\Locales): their saved one,
 * else the cookie's, else English. On the web the session's user; on the
 * API the token's, read before `auth:sanctum` runs (it resolves the same
 * user again).
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? ($request->bearerToken() !== null ? $request->user('sanctum') : null);

        App::setLocale(Locales::forRequest($request, $user instanceof User ? $user : null));

        return $next($request);
    }
}
