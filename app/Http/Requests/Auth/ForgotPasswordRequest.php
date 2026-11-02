<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asks for a forgotten-password link (web and API). Five requests per email
 * and thirty per address (an office shares one) in 15 minutes, counted
 * before anyone is looked up, so a known and an unknown email are limited
 * alike.
 */
class ForgotPasswordRequest extends FormRequest
{
    public const THROTTLED = 'Too many tries. Wait a few minutes.';

    private const MAX_PER_EMAIL = 5;

    private const MAX_PER_ADDRESS = 30;

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
        $limits = [
            'forgot-password:ip:'.$this->ip() => self::MAX_PER_ADDRESS,
            'forgot-password:email:'.Str::transliterate(Str::lower($this->email())) => self::MAX_PER_EMAIL,
        ];

        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw ValidationException::withMessages(['email' => self::THROTTLED])->status(429);
            }
        }

        foreach (array_keys($limits) as $key) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
        }
    }
}
