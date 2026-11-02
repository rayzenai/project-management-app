<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Sets a password from an emailed link (web and API). Whether the link
 * still works is PasswordLinkService::setPassword's to say. Ten refused
 * links a minute per address, checked before anything else: a refused link
 * is held to a fixed answer time, which must not tie up the server. A
 * password that breaks the rules is no refusal (an office shares one
 * address, and typos are common).
 */
class SetPasswordRequest extends FormRequest
{
    public const THROTTLED = 'Too many tries. Wait a minute.';

    private const MAX_ATTEMPTS = 10;

    private const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:reset,invite'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return 'reset'|'invite'
     */
    public function kind(): string
    {
        return $this->validated('kind') === 'invite' ? 'invite' : 'reset';
    }

    /**
     * The rules the new password must meet, in plain words, from the same
     * Password::defaults() the server checks: "At least 8 characters." here,
     * production's stricter set there.
     */
    public static function describeRules(): string
    {
        $rules = Password::defaults()->appliedRules();

        $with = array_values(array_filter([
            $rules['mixedCase'] ? 'upper and lower case letters' : ($rules['letters'] ? 'a letter' : null),
            $rules['numbers'] ? 'a number' : null,
            $rules['symbols'] ? 'a symbol' : null,
        ]));

        $words = "At least {$rules['min']} characters".($with === [] ? '' : ', with '.Arr::join($with, ', ', ' and ')).'.';

        return $rules['uncompromised'] ? $words.' Not one that has shown up in a data leak.' : $words;
    }

    /**
     * Counts a link the service refused toward this address's limit.
     */
    public function countRefusal(): void
    {
        RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
    }

    /**
     * @throws ValidationException 429 on `password` once the address used up its tries
     */
    protected function prepareForValidation(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['password' => self::THROTTLED])->status(429);
        }
    }

    private function throttleKey(): string
    {
        return 'set-password:ip:'.$this->ip();
    }
}
