<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The app's languages (docs/superpowers/specs/2026-11-07-languages-design.md):
 * English, the default, and Nepali. A person's choice is their preference
 * (`user_preferences.locale`), copied to a cookie so the sign-in page keeps
 * it after they sign out; someone signed out chooses with the cookie alone.
 */
final class Locales
{
    public const COOKIE = 'locale';

    /** The cookie outlives a sign-out by a year. */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    /** Each language by its own name, as the switch shows it. */
    public const NAMES = ['en' => 'English', 'ne' => 'नेपाली'];

    public const DEFAULT = 'en';

    /** @return list<string> */
    public static function supported(): array
    {
        return array_keys(self::NAMES);
    }

    /** @return list<array{value: string, label: string}> */
    public static function choices(): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => self::NAMES[$value]], self::supported());
    }

    /** @phpstan-assert-if-true string $locale */
    public static function isSupported(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::NAMES);
    }

    /** The person's saved language, else the cookie's, else English. */
    public static function forRequest(Request $request, ?User $user): string
    {
        $saved = $user?->locale(fallback: null);

        if ($saved !== null) {
            return $saved;
        }

        $cookie = $request->cookie(self::COOKIE);

        return self::isSupported($cookie) ? $cookie : self::DEFAULT;
    }

    /**
     * Signing in: a language chosen on the sign-in page (the cookie) becomes
     * the person's own when they have chosen none, so it follows them to
     * the app and other devices. A saved choice is never replaced.
     */
    public static function adoptFromCookie(Request $request, User $user): void
    {
        $cookie = $request->cookie(self::COOKIE);

        if ($user->locale(fallback: null) === null && self::isSupported($cookie)) {
            $user->preferences()->updateOrCreate(['user_id' => $user->id], ['locale' => $cookie]);
        }
    }

    public static function cookie(string $locale): Cookie
    {
        return cookie(self::COOKIE, $locale, self::COOKIE_MINUTES);
    }

    public static function isNepali(): bool
    {
        return app()->getLocale() === 'ne';
    }

    /** A number as the current language writes it: १२ in Nepali. */
    public static function number(int|string $value): string
    {
        return self::isNepali() ? BikramSambat::digits($value) : (string) $value;
    }
}
