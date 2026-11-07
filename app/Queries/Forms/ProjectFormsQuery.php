<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\Project;
use App\Support\Forms\FormTemplates;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A project's Forms tab: its forms, newest first, each with what the viewer
 * may do (the team sees every one's title, phase and dates; their
 * responses only with a level), and whether they may make one there.
 */
class ProjectFormsQuery
{
    /**
     * @return array{forms: list<array<string, mixed>>, can_create: bool, templates: list<array{id: string, name: string, description: string}>}
     */
    public function tab(Project $project, Authenticatable $viewer): array
    {
        $forms = Form::query()->where('project_id', $project->id)
            ->with(['project.program', 'project.organization'])
            ->withCount(['responses as counted_responses_count' => fn (Builder $q): Builder => $q->where('is_test', false)])
            ->orderByDesc('updated_at')
            ->get();

        WorkspaceAccess::preloadFormAccess($viewer, $forms);

        return [
            'forms' => array_values($forms->map(fn (Form $form): array => FormItems::of($form, $viewer))->all()),
            'can_create' => WorkspaceAccess::canCreateForm($viewer, $project),
            'templates' => FormTemplates::all(),
        ];
    }
}
