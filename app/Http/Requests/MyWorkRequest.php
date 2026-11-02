<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * My work's one filter: `done`, whether to add the last week's finished
 * work. Everyone signed in has a My work.
 */
class MyWorkRequest extends FormRequest
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
        return [
            'done' => ['sometimes', 'boolean'],
        ];
    }
}
