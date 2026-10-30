<?php

namespace App\Http\Middleware;

use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a person deactivated while signed in, on their next request
 * ({@see WorkspaceAccess::canSignIn}): a web session ends with a full page
 * visit to the login page, which then says why; an API token answers 401
 * until they are turned back on.
 */
class EnsureCanSignIn
{
    public const DEACTIVATED = 'This account is deactivated. Ask your organizational admin to turn it back on.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || WorkspaceAccess::canSignIn($user)) {
            return $next($request);
        }

        if (! $request->hasSession()) {
            return response()->json(['message' => self::DEACTIVATED], 401);
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(route('workspace.login'));
    }
}
