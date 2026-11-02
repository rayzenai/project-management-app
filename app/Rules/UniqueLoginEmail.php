<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * No login has this email, in any case: "Deepak@x" and "deepak@x" are one
 * address, so the person form can't make a second login beside one an
 * invite stored in lowercase. The login being edited is ignored.
 */
final class UniqueLoginEmail implements ValidationRule
{
    public function __construct(private ?int $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreUserId !== null, fn ($query) => $query->whereKeyNot($this->ignoreUserId))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
