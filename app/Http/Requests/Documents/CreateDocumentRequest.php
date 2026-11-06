<?php

namespace App\Http\Requests\Documents;

use App\Models\Project;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Make a document, blank or uploaded: in a project whose documents the
 * person may use (`project`, a slug; any other is the same 422), or, with no
 * project, a personal one ({@see WorkspaceAccess::canCreatePersonalDocuments()}).
 */
abstract class CreateDocumentRequest extends FormRequest
{
    private ?Project $project = null;

    public function authorize(): bool
    {
        if (! config('documents.enabled') || $this->user() === null) {
            return false;
        }

        return $this->filled('project') || WorkspaceAccess::canCreatePersonalDocuments($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project' => ['bail', 'nullable', 'string', 'max:255', $this->usableProject()],
        ];
    }

    /** Where it goes, once the request has validated: null is personal. */
    public function project(): ?Project
    {
        return $this->project;
    }

    private function usableProject(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $project = Project::query()->where('slug', (string) $value)->first();

            if ($project === null || ! WorkspaceAccess::canUseProjectDocuments($this->user(), $project)) {
                $fail('Choose one of your projects.');

                return;
            }

            $this->project = $project;
        };
    }
}
