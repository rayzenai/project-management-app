<?php

namespace App\Http\Requests\Concerns;

/**
 * Emails are stored trimmed and lowercase (User::email, Member::email), so
 * a request that looks a person up by email reads it the same way, from
 * its prepareForValidation.
 */
trait LowercasesEmail
{
    protected function lowercaseEmail(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }
}
