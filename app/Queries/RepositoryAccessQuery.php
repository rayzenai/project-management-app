<?php

namespace App\Queries;

use App\Http\Resources\GitRepositoryGrantResource;
use App\Models\GitRepository;
use App\Models\Member;
use App\Support\WorkspaceAccess;

/**
 * Who reaches a linked repository through the tracker: its maintainers
 * (the project's manager and its program's manager, admin on the forge,
 * their access following the role) and the people given access, by name.
 * `waiting` marks someone the forge can't have yet (never signed in to
 * it). Organizational admins and super-admins reach every repository and
 * are not listed, nor are a deleted person's grants (which give nothing).
 * No email.
 */
class RepositoryAccessQuery
{
    /**
     * @return array{maintainers: list<array{id: int, name: string, title: string|null, role: string, waiting: bool}>, grants: list<array<string, mixed>>}
     */
    public function get(GitRepository $repository): array
    {
        $project = $repository->project;
        $managerId = $project->manager()?->id;
        $ids = WorkspaceAccess::repositoryMaintainerIds($project);
        $members = Member::query()->with('user')->whereKey($ids)->get()->keyBy('id');

        $maintainers = [];

        foreach ($ids as $id) {
            $member = $members->get($id);

            if ($member !== null) {
                $maintainers[] = [
                    'id' => $member->id,
                    'name' => $member->name,
                    'title' => $member->title,
                    'role' => $member->id === $managerId ? 'manager' : 'program manager',
                    'waiting' => blank($member->user?->git_username),
                ];
            }
        }

        $grants = $repository->grants()
            ->whereHas('member')
            ->with('member.user')
            ->orderBy(Member::query()->select('name')->whereColumn('members.id', 'git_repository_grants.member_id'))
            ->orderBy('id')
            ->get();

        return [
            'maintainers' => $maintainers,
            'grants' => array_values(GitRepositoryGrantResource::collection($grants)->resolve()),
        ];
    }
}
