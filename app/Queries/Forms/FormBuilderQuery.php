<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Queries\Structure\PersonRef;
use App\Services\Forms\FormPublishChecks;
use App\Support\Forms\FormSchema;
use App\Support\Forms\FormTemplates;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The builder page: the form, its draft and what stops it from being
 * published, its settings, who fills it in, its task settings, its versions
 * and what the viewer may change.
 */
class FormBuilderQuery
{
    public function __construct(private FormPublishChecks $checks, private FormLibraryQuery $library) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Form $form, Authenticatable $viewer): array
    {
        $form->loadMissing(['project.program', 'project.organization', 'creator', 'respondentMembers.designation', 'respondentOrganizations']);
        $project = $form->project;
        $tasks = $form->taskSettings();
        $taskProject = $tasks === null ? null : Project::query()->with('program')->find($tasks['project_id']);
        $assignee = $tasks === null ? null : Member::query()->with('designation:id,name')->find($tasks['assignee_id']);
        $responses = $form->countedResponses();

        return [
            'form' => [
                ...FormItems::of($form, $viewer),
                'status' => $form->status,
                'respondents' => $form->respondents,
                'response_limit' => $form->response_limit,
                'settings' => [
                    'allow_edit' => (bool) $form->setting('allow_edit', true),
                    'review_page' => (bool) $form->setting('review_page', true),
                    'notify' => (bool) $form->setting('notify', true),
                ],
                'stages' => $form->stages(),
                'maker' => $form->creator?->name,
                'published' => $form->published_version_id !== null,
                'responses_count' => $responses,
                'answering_locked' => $responses > 0,
                'can_delete' => $responses === 0,
            ],
            'draft' => $form->draft,
            'problems' => $this->checks->problems($form),
            'changed' => $this->checks->changedSincePublished($form),
            'versions' => $form->versions()->with('form')->get(['id', 'form_id', 'number', 'published_at', 'published_by'])
                ->map(fn ($version): array => ['number' => $version->number, 'published_at' => $version->published_at->toIso8601String()])->all(),
            'files' => (object) FormFileRefs::content($form->id, $form->draft),
            'respondents' => [
                'people' => $form->respondentMembers->map(fn (Member $member): array => PersonRef::of($member))->values()->all(),
                'organizations' => $form->respondentOrganizations->map(fn (Organization $o): array => ['id' => $o->id, 'name' => $o->name])->values()->all(),
                'can_add_organizations' => WorkspaceAccess::canAddFormOrganizations($viewer, $form),
                'organization_choices' => WorkspaceAccess::canAddFormOrganizations($viewer, $form)
                    ? Organization::query()->whereKeyNot($project->organization_id)->orderBy('name')->get(['id', 'name'])->map(fn (Organization $o): array => ['id' => $o->id, 'name' => $o->name])->all()
                    : [],
            ],
            'tasks' => [
                'settings' => $tasks,
                'project' => $taskProject === null ? null : ['slug' => $taskProject->slug, 'label' => $taskProject->label()],
                'assignee' => $assignee === null ? null : PersonRef::of($assignee),
                'projects' => array_values(Project::query()->active()->where('organization_id', $project->organization_id)->with('program')->orderBy('title')->limit(300)->get()
                    ->filter(fn (Project $choice): bool => WorkspaceAccess::canSendFormTasksTo($viewer, $form, $choice))
                    ->map(fn (Project $choice): array => ['slug' => $choice->slug, 'label' => $choice->label()])
                    ->all()),
            ],
            'templates' => FormTemplates::all(),
            // Where a copy may go.
            'places' => $this->library->places($viewer),
            'limits' => ['max_upload_mb' => FormSchema::maxUploadMb(), 'max_steps' => FormSchema::MAX_STEPS, 'max_blocks' => FormSchema::MAX_BLOCKS],
        ];
    }
}
