<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Documents page's filters, for anyone signed in while documents are on:
 * `show` (all, mine, shared, organization, projects), `project` (a slug),
 * `type`, `q` (in the title) and `cursor` (Show more).
 */
class DocumentLibraryRequest extends FormRequest
{
    public const SHOWS = ['all', 'mine', 'shared', 'organization', 'projects'];

    public function authorize(): bool
    {
        return config('documents.enabled') && $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'show' => ['nullable', Rule::in(self::SHOWS)],
            'project' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(DocumentTypes::all())],
            'q' => ['nullable', 'string', 'max:200'],
            'cursor' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array{show: string, project: string|null, type: string|null, q: string|null}
     */
    public function filters(): array
    {
        $q = trim((string) $this->validated('q'));

        return [
            'show' => (string) ($this->validated('show') ?? 'all'),
            'project' => $this->validated('project') === null ? null : (string) $this->validated('project'),
            'type' => $this->validated('type') === null ? null : (string) $this->validated('type'),
            'q' => $q === '' ? null : $q,
        ];
    }

    public function cursor(): ?string
    {
        return $this->validated('cursor') === null ? null : (string) $this->validated('cursor');
    }
}
