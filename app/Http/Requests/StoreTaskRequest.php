<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'max:64'],
            'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'deadline_at' => ['nullable', 'date'],
            'status_note' => ['nullable', 'string'],
            'source_url' => ['nullable', 'url', 'max:500'],
            'item_number' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:64'],
            'deadline_type' => ['nullable', 'string', 'max:64'],
            'responsible_ministry' => ['nullable', 'string', 'max:255'],
            'title_np' => ['nullable', 'string', 'max:255'],
            'description_np' => ['nullable', 'string'],
        ];
    }
}
