<?php

namespace App\Queries;

use App\Http\Resources\MemberResource;
use App\Http\Resources\TeamResource;
use App\Models\Member;
use App\Models\Team;

/**
 * Assembles the team roster payload shared by the Inertia web index and the
 * JSON API index. Single source of truth for the teams + members assembly.
 */
class TeamIndexQuery
{
    /**
     * @return array{teams: array<int, array<string, mixed>>, members: array<int, array<string, mixed>>}
     */
    public function data(): array
    {
        $teams = Team::query()->withCount('members')->with('members:id')->orderBy('name')->get();
        $members = Member::query()->with('teams:id')->orderBy('name')->get();

        return [
            'teams' => TeamResource::collection($teams)->resolve(),
            'members' => MemberResource::collection($members)->resolve(),
        ];
    }
}
