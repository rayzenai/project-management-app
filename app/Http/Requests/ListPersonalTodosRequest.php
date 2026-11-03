<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Read your own to-do list (PersonalTodosQuery). Everyone signed in has
 * one, and nobody reads anyone else's: the list is always the caller's.
 */
class ListPersonalTodosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function owner(): User
    {
        $user = $this->user();

        return $user instanceof User ? $user : abort(401);
    }
}
