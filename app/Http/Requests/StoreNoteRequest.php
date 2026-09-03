<?php

namespace App\Http\Requests;

use App\Models\ProjectNote;
use Illuminate\Foundation\Http\FormRequest;

class StoreNoteRequest extends FormRequest
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
            'body' => ['required', 'string'],
            'type' => ['nullable', 'string', 'in:'.implode(',', array_keys(ProjectNote::TYPES))],
            'happened_at' => ['nullable', 'date'],
        ];
    }
}
