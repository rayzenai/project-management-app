<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Sets a password from an emailed link (web and API). Whether the link
 * still works is PasswordLinkService::setPassword's to say.
 */
class SetPasswordRequest extends FormRequest
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
}
