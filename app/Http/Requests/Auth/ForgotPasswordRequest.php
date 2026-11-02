<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asks for a forgotten-password link (web and API). Five requests per
 * address and five per email in 15 minutes, counted before anyone is looked
 * up, so a known and an unknown email are limited alike.
 */
class ForgotPasswordRequest extends FormRequest
{
    public const THROTTLED = 'Too many tries. Wait a few minutes.';

    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 15 * 60;

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
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function email(): string
    {
        return (string) $this->validated('email');
    }

    /**
     * @throws ValidationException 429 on `email` once either count is used up
     */
    protected function passedValidation(): void
    {
        $keys = [
            'forgot-password:ip:'.$this->ip(),
            'forgot-password:email:'.Str::transliterate(Str::lower($this->email())),
        ];

        foreach ($keys as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
                throw ValidationException::withMessages(['email' => self::THROTTLED])->status(429);
            }
        }

        foreach ($keys as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }
}
