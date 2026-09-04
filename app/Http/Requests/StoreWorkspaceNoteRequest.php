<?php

namespace App\Http\Requests;

use App\Models\WorkspaceNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkspaceNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'color' => ['nullable', 'string', Rule::in(WorkspaceNote::COLORS)],
        ];
    }
}
