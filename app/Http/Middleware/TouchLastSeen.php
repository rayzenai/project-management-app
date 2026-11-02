<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers when a signed-in person last used the workspace
 * (`users.last_seen_at`), at most once an hour, after the response is built.
 * A base query update (not Eloquent's, which would stamp `updated_at`): no
 * model events and `updated_at` stays put.
 */
class TouchLastSeen
{
    public const REFRESH_MINUTES = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();

        if ($user instanceof User) {
            $seen = $user->last_seen_at;

            if ($seen === null || $seen->lte(now()->subMinutes(self::REFRESH_MINUTES))) {
                User::query()->whereKey($user->getKey())->toBase()->update(['last_seen_at' => now()]);
            }
        }

        return $response;
    }
}
