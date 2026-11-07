<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who a form is for, as lists: the people who fill it in (each person), the
 * organizations that answer (a data call), whom publishing and Remind tell,
 * and who hasn't answered yet. The rule itself is
 * WorkspaceAccess::isFormRespondent; these lists follow it.
 */
class FormRespondentsQuery
{
    /**
     * Active people of the form's own organization it is for.
     *
     * @return Builder<Member>
     */
    public function ownPeople(Form $form): Builder
    {
        $query = Member::query()->active()->where('organization_id', $form->project->organization_id);

        return match ($form->respondents) {
            Form::RESPONDENTS_TEAM => $query->whereHas('teams', fn (Builder $team): Builder => $team->whereIn('teams.id', $form->project->teams()->select('teams.id'))),
            Form::RESPONDENTS_PEOPLE => $query->whereIn('members.id', $form->respondentMembers()->select('members.id')),
            default => $query,
        };
    }

    /**
     * Active people of the other organizations it was sent to.
     *
     * @return Builder<Member>
     */
    public function otherPeople(Form $form): Builder
    {
        return Member::query()->active()->whereIn('organization_id', $form->respondentOrganizations()->select('organizations.id'));
    }

    /**
     * The organizations that answer a data call: its own, then the others,
     * by name.
     *
     * @return Collection<int, Organization>
     */
    public function organizations(Form $form): Collection
    {
        $others = $form->respondentOrganizations()->orderBy('name')->get();
        $own = Organization::query()->whereKey($form->project->organization_id)->get();

        return $own->merge($others)->unique('id')->values();
    }

    /**
     * Whom publishing (and Remind, among those who haven't answered) tells:
     * each respondent; for a data call, the chosen people or team of its own
     * organization (else its admins) and the admins of every other one.
     *
     * @param  list<int>|null  $organizationIds  only these answering organizations (data calls)
     * @param  list<int>|null  $memberIds  only these people (each person)
     * @return Collection<int, Member>
     */
    public function notifyTargets(Form $form, ?array $organizationIds = null, ?array $memberIds = null): Collection
    {
        if (! $form->answersByOrganization()) {
            $people = $this->ownPeople($form)->get()->merge($this->otherPeople($form)->get())->unique('id');

            return ($memberIds === null ? $people : $people->whereIn('id', $memberIds))->load('user')->values();
        }

        $ownId = (int) $form->project->organization_id;
        $targets = new Collection;

        foreach ($this->organizations($form) as $organization) {
            if ($organizationIds !== null && ! in_array($organization->id, $organizationIds, true)) {
                continue;
            }

            $own = $organization->id === $ownId && $form->respondents !== Form::RESPONDENTS_EVERYONE;
            $people = $own
                ? $this->ownPeople($form)->get()
                : Member::query()->active()->where('organization_id', $organization->id)->where('is_office_admin', true)->get();
            $targets = $targets->merge($people);
        }

        return $targets->unique('id')->load('user')->values();
    }

    /**
     * Who hasn't answered: by name inside the form's organization, counted
     * by organization outside it; for a data call, the organizations that
     * haven't.
     *
     * @return array{mode: string, people: list<array{id: int, name: string, designation: string|null}>, organizations: list<array{id: int, name: string, answered: int, total: int}>, answered: int, total: int}
     */
    public function pending(Form $form): array
    {
        $keys = FormResponse::query()->where('form_id', $form->id)->whereNotNull('respondent_key')->pluck('respondent_key')->all();

        if ($form->answersByOrganization()) {
            $organizations = $this->organizations($form)->map(fn (Organization $o): array => [
                'id' => $o->id, 'name' => $o->name, 'answered' => in_array('o:'.$o->id, $keys, true) ? 1 : 0, 'total' => 1,
            ]);

            return [
                'mode' => 'organization',
                'people' => [],
                'organizations' => array_values($organizations->filter(fn (array $o): bool => $o['answered'] === 0)->all()),
                'answered' => $organizations->sum('answered'),
                'total' => $organizations->count(),
            ];
        }

        $own = $this->ownPeople($form)->with('designation:id,name')->orderBy('name')->get();
        $answeredOwn = $own->filter(fn (Member $m): bool => in_array('m:'.$m->id, $keys, true));
        $others = $this->otherPeople($form)->get(['members.id', 'members.organization_id'])->groupBy('organization_id');
        $names = Organization::query()->whereIn('id', $others->keys())->orderBy('name')->pluck('name', 'id');
        $organizations = [];
        $answeredOthers = 0;
        $totalOthers = 0;

        foreach ($names as $id => $name) {
            $people = $others->get($id) ?? collect();
            $answered = $people->filter(fn (Member $m): bool => in_array('m:'.$m->id, $keys, true))->count();
            $answeredOthers += $answered;
            $totalOthers += $people->count();
            $organizations[] = ['id' => (int) $id, 'name' => (string) $name, 'answered' => $answered, 'total' => $people->count()];
        }

        return [
            'mode' => 'person',
            'people' => array_values($own->reject(fn (Member $m): bool => in_array('m:'.$m->id, $keys, true))
                ->map(fn (Member $m): array => ['id' => $m->id, 'name' => $m->name, 'designation' => $m->designation?->name])->all()),
            'organizations' => $organizations,
            'answered' => $answeredOwn->count() + $answeredOthers,
            'total' => $own->count() + $totalOthers,
        ];
    }
}
