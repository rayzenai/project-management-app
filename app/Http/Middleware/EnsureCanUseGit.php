<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Git\GitAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The forge sign-in gate on /oauth/authorize: no code is issued to anyone
 * GitAccess gives no organization (deactivated, no member, or an office
 * without git), however valid their session.
 */
class EnsureCanUseGit
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && GitAccess::organizationsFor($user)->isNotEmpty(),
            403,
            "You don't have access to git. Ask your office admin.",
        );

        return $next($request);
    }
}
