<?php

namespace App\Http\Requests\Auth;

use App\Http\Middleware\EnsureCanSignIn;
use App\Support\LoginThrottle;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Validates and authenticates a workspace web login. Holds the rate-limited
 * attempt logic so the controller stays thin (the documented pattern:
 * authorization/authentication lives in the FormRequest).
 */
class LoginRequest extends FormRequest
{
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
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials, applying a per
     * email + IP rate limit of five attempts per minute.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $deactivated = false;

        // The right password of a deactivated person signs nobody in, and
        // says why (only to someone who knows the password).
        $signsIn = function (Authenticatable $user) use (&$deactivated): bool {
            $deactivated = ! WorkspaceAccess::canSignIn($user);

            return ! $deactivated;
        };

        if (! Auth::attemptWhen($this->only('email', 'password'), $signsIn, $this->boolean('remember'))) {
            if ($deactivated) {
                throw ValidationException::withMessages([
                    'email' => EnsureCanSignIn::DEACTIVATED,
                ]);
            }

            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    protected function ensureIsNotRateLimited(): void
    {
        LoginThrottle::ensureNotLimited($this->throttleKey());
    }

    protected function throttleKey(): string
    {
        return LoginThrottle::key((string) $this->input('email'), $this->ip());
    }
}
