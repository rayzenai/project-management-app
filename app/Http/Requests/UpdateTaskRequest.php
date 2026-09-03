<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
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
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', 'string', Rule::in(array_keys((array) config('project-management.statuses', [])))],
            'priority' => ['sometimes', 'required', 'in:low,medium,high,urgent'],
            'progress' => ['sometimes', 'required', 'integer', 'min:0', 'max:100'],
            'deadline_at' => ['sometimes', 'nullable', 'date'],
            'status_note' => ['sometimes', 'nullable', 'string'],
            'source_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'item_number' => ['sometimes', 'nullable', 'integer'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'deadline_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'responsible_ministry' => ['sometimes', 'nullable', 'string', 'max:255'],
            'title_np' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description_np' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
