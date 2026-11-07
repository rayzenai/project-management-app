<?php

namespace App\Http\Requests\Documents\Concerns;

use App\Models\Project;
use App\Support\WorkspaceAccess;
use Closure;

/**
 * A `project` field naming, by slug, a project whose documents the person
 * may use (WorkspaceAccess::canUseProjectDocuments); any other, or an
 * unknown slug, gets the same message.
 */
trait ChoosesUsableProject
{
    private ?Project $chosenProject = null;

    /** The project, once the request has validated; null when none was given. */
    public function project(): ?Project
    {
        return $this->chosenProject;
    }

    protected function usableProject(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $project = Project::query()->where('slug', (string) $value)->first();

            if ($project === null || ! WorkspaceAccess::canUseProjectDocuments($this->user(), $project)) {
                $fail(__('Choose one of your projects.'));

                return;
            }

            $this->chosenProject = $project;
        };
    }
}
