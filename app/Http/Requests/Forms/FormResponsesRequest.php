<?php

namespace App\Http\Requests\Forms;

use App\Support\WorkspaceAccess;

/**
 * The form's responses: Can see responses or Can edit
 * ({@see WorkspaceAccess::canSeeFormResponses()}), with the table's filters.
 */
class FormResponsesRequest extends FormRouteRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canSeeFormResponses($this->user(), $this->form());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stage' => ['nullable', 'string', 'max:40'],
            'organization' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'question' => ['nullable', 'string', 'max:24'],
            'answer' => ['nullable', 'string', 'max:300'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{stage?: string|null, organization?: int|null, q?: string|null, question?: string|null, answer?: string|null, from?: string|null, to?: string|null}
     */
    public function filters(): array
    {
        /** @var array{stage?: string|null, organization?: int|null, q?: string|null, question?: string|null, answer?: string|null, from?: string|null, to?: string|null} $filters */
        $filters = array_filter($this->safe()->only(['stage', 'organization', 'q', 'question', 'answer', 'from', 'to']), fn (mixed $v): bool => $v !== null && $v !== '');

        return $filters;
    }
}
