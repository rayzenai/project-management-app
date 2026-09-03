<?php

namespace App\Http\Requests;

use App\Models\WorkspaceNote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceNotePlacementRequest extends FormRequest
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
            'position_x' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'position_y' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'color' => ['nullable', 'string', Rule::in(WorkspaceNote::COLORS)],
        ];
    }
}
