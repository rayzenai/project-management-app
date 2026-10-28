<?php

namespace App\Queries;

use App\Models\Member;
use App\Models\Team;

/**
 * People a leader can add to a team, found by name or email: active people of
 * the team's organization, or of any organization for a cross-organization
 * team, who are not on it yet. Returns a handful of matches, never a list.
 */
class TeamCandidatesQuery
{
    public const LIMIT = 8;

    /**
     * @return list<array{id: int, name: string, email: string|null, title: string|null, organization: string|null}>
     */
    public function get(Team $team, string $term): array
    {
        $like = '%'.mb_strtolower(str_replace(['%', '_'], '', trim($term))).'%';

        return array_values(Member::query()
            ->active()
            ->whereDoesntHave('teams', fn ($q) => $q->where('teams.id', $team->getKey()))
            ->when(! $team->is_cross_organization, fn ($q) => $q->where('organization_id', $team->organization_id))
            ->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$like])->orWhereRaw('LOWER(email) LIKE ?', [$like]))
            ->with('organization:id,name')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Member $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'title' => $member->title,
                'organization' => $member->organization?->name,
            ])
            ->all());
    }
}
