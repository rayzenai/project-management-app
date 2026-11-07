<?php

namespace App\Queries\Forms;

use App\Models\Form;
use App\Models\Member;
use App\Models\Project;
use App\Queries\Structure\PersonRef;

/**
 * Pickers by name: whom to share a form with and whom to choose as its
 * respondents (active people of its project's organization, the twin of
 * WorkspaceAccess::canShareFormWith), and whom its tasks may go to (those a
 * project's tasks may be assigned to). PersonRefs, never emails.
 */
class FormCandidatesQuery
{
    public const LIMIT = 8;

    /** @return list<array{id: int, name: string, designation: string|null}> */
    public function people(Form $form, string $term, bool $excludeShared): array
    {
        $like = $this->like($term);

        return array_values(Member::query()
            ->active()
            ->where('organization_id', $form->project->organization_id)
            ->when($excludeShared, fn ($q) => $q->whereNotIn('id', $form->shares()->select('member_id')))
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))
            ->with('designation:id,name')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Member $member): array => PersonRef::of($member))
            ->all());
    }

    /** @return list<array{id: int, name: string, designation: string|null}> */
    public function assignees(Project $project, string $term): array
    {
        return array_values(Member::query()
            ->assignableFor($project)
            ->whereRaw('LOWER(members.name) LIKE ?', [$this->like($term)])
            ->with('designation:id,name')
            ->orderBy('members.name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Member $member): array => PersonRef::of($member))
            ->all());
    }

    private function like(string $term): string
    {
        return '%'.mb_strtolower(str_replace(['%', '_'], '', trim($term))).'%';
    }
}
