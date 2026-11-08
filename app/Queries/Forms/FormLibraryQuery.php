<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Project;
use App\Support\Forms\FormTemplates;
use App\Support\ProjectChoices;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Forms page: To fill (open forms meant for the viewer that they, or
 * for a data call their office, haven't answered, nearest close first),
 * Filled (what they or their office sent) and Managing (forms they may build
 * or whose responses they see), plus where they may make one.
 */
class FormLibraryQuery
{
    /**
     * @return array{to_fill: list<array<string, mixed>>, filled: list<array<string, mixed>>, managing: list<array<string, mixed>>, places: array{projects: list<array{id: int, slug: string, title: string, program: ?string, organization_id: int, mine: bool}>, organizations: list<array{id: int, name: string}>}, templates: list<array{id: string, name: string, description: string}>}
     */
    public function for(Authenticatable $viewer): array
    {
        $toFill = $this->toFill($viewer);
        $filled = $this->filled($viewer);
        $managing = Form::query()->managedBy($viewer)
            ->with(['project.program', 'project.organization'])
            ->withCount(['responses as counted_responses_count' => fn (Builder $q): Builder => $q->where('is_test', false)])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get();

        WorkspaceAccess::preloadFormAccess($viewer, $toFill->merge($managing));

        return [
            'to_fill' => array_values($toFill->map(fn (Form $form): array => FormItems::of($form, $viewer))->all()),
            'filled' => $filled,
            'managing' => array_values($managing->map(fn (Form $form): array => FormItems::of($form, $viewer))->all()),
            'places' => $this->places($viewer),
            'templates' => FormTemplates::all(),
        ];
    }

    /**
     * Open forms meant for the viewer that they (or, for a data call, their
     * office) haven't answered, nearest close first. My work shows them too.
     *
     * @return Collection<int, Form>
     */
    public function toFill(Authenticatable $viewer): Collection
    {
        $member = WorkspaceAccess::memberFor($viewer);

        if (! config('forms.enabled') || $member === null) {
            return new Collection;
        }

        $answerable = Form::query()->answerableBy($viewer)
            ->where('status', Form::STATUS_OPEN)
            ->whereNotNull('published_version_id')
            ->with(['project.program', 'project.organization'])
            ->get();
        $keys = $answerable->map(fn (Form $form): string => $form->respondentKeyFor($member))->unique()->values()->all();
        $mine = FormResponse::query()->whereIn('form_id', $answerable->modelKeys())->whereIn('respondent_key', $keys)->get()->keyBy('form_id');

        return $answerable->filter(fn (Form $form): bool => $form->isOpenNow() && ! $mine->has($form->id))
            ->sortBy(fn (Form $form): string => $form->closes_at?->toIso8601String() ?? '9999')
            ->values();
    }

    /**
     * What the viewer (or, for a data call, their office) sent, newest
     * first, whatever the form's status now.
     *
     * @return list<array<string, mixed>>
     */
    private function filled(Authenticatable $viewer): array
    {
        $member = WorkspaceAccess::memberFor($viewer);

        if ($member === null) {
            return [];
        }

        $responses = FormResponse::query()
            ->whereIn('respondent_key', ['m:'.$member->id, 'o:'.(int) $member->organization_id])
            ->whereIn('form_id', Form::query()->answerableBy($viewer)->select('forms.id'))
            ->with(['form.project.program', 'form.project.organization'])
            ->orderByDesc('updated_at')
            ->limit(200)
            ->get()
            // An organization's response counts only on its data calls.
            ->filter(fn (FormResponse $response): bool => $response->respondent_key === $response->form->respondentKeyFor($member));

        return array_values($responses->map(fn (FormResponse $response): array => FormItems::of($response->form, $viewer, $response))->all());
    }

    /**
     * The projects the viewer may make a form in, for the project picker.
     *
     * @return array{projects: list<array{id: int, slug: string, title: string, program: ?string, organization_id: int, mine: bool}>, organizations: list<array{id: int, name: string}>}
     */
    public function places(Authenticatable $viewer): array
    {
        if (! config('forms.enabled')) {
            return ProjectChoices::for($viewer, []);
        }

        $member = WorkspaceAccess::memberFor($viewer);
        $query = Project::query()->active()->with('program');

        if (! WorkspaceAccess::isSuperAdmin($viewer)) {
            if ($member === null || ! $member->is_active) {
                return ProjectChoices::for($viewer, []);
            }

            $adminOrganizationId = WorkspaceAccess::officeAdminOrganizationId($viewer);
            $programIds = WorkspaceAccess::managedProgramIds($viewer);
            $query->where(function (Builder $q) use ($member, $adminOrganizationId, $programIds): void {
                $q->whereHas('teams.members', fn (Builder $m): Builder => $m->where('members.id', $member->getKey()));

                if ($adminOrganizationId !== null) {
                    $q->orWhere('organization_id', $adminOrganizationId);
                }

                if ($programIds !== []) {
                    $q->orWhere(fn (Builder $program): Builder => Project::inPrograms($program, $programIds, $member));
                }
            });
        }

        return ProjectChoices::for($viewer, $query->orderBy('title')->limit(300)->get());
    }
}
