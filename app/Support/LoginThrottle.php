<?php

namespace App\Support;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The wrong-password limit both logins share: five per email and address,
 * counted together, so switching between the web and the API buys no
 * extra guesses. A right password starts the count again.
 */
final class LoginThrottle
{
    public const MAX_ATTEMPTS = 5;

    public static function key(string $email, ?string $ip): string
    {
        return Str::transliterate(Str::lower($email).'|'.$ip);
    }

    /**
     * @throws ValidationException when the key used up its attempts, on
     *                             `email`, at `$status`
     */
    public static function ensureNotLimited(string $key, int $status = 422): void
    {
        if (! RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ])->status($status);
    }
}
