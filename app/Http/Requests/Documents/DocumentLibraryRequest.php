<?php

namespace App\Http\Requests\Documents;

use App\Support\Documents\DocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Documents page's filters, for anyone signed in while documents are on:
 * `show` (the tab: recent, personal, shared, projects; links made before
 * the four tabs say all, mine or organization, read as OLD_SHOWS maps
 * them), `project` (a slug), `type`, `q` (in the title) and `cursor` (Show
 * more).
 */
class DocumentLibraryRequest extends FormRequest
{
    public const SHOWS = ['recent', 'personal', 'shared', 'projects'];

    /** The tabs' earlier names, still in people's links and bookmarks. */
    public const OLD_SHOWS = ['all' => 'recent', 'mine' => 'personal', 'organization' => 'shared'];

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
            'show' => ['nullable', Rule::in([...self::SHOWS, ...array_keys(self::OLD_SHOWS)])],
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
        $show = (string) ($this->validated('show') ?? 'recent');

        return [
            'show' => self::OLD_SHOWS[$show] ?? $show,
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
