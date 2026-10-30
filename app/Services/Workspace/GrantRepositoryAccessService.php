<?php

namespace App\Services\Workspace;

use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Models\User;
use App\Observers\GitRepositoryGrantObserver;
use App\Support\ServiceResult;

/**
 * Gives a person read or write access to a linked repository, or sets the
 * access they already have. Who may give it to whom is checked by the
 * request (WorkspaceAccess::canGrantRepositoryAccessTo); the activity row
 * and the forge sync follow from the grant's observer
 * ({@see GitRepositoryGrantObserver}).
 */
class GrantRepositoryAccessService
{
    public function execute(GitRepository $repository, Member $member, string $permission, ?User $actor): ServiceResult
    {
        $grant = GitRepositoryGrant::query()->updateOrCreate(
            ['git_repository_id' => $repository->id, 'member_id' => $member->id],
            ['permission' => $permission, 'granted_by' => $actor?->id],
        );
        $grant->setRelation('member', $member->loadMissing('user'));

        $message = ! $grant->wasRecentlyCreated && $grant->wasChanged('permission')
            ? "{$member->name} now has {$permission} access to {$repository->full_name}."
            : "Gave {$member->name} {$permission} access to {$repository->full_name}.";

        return ServiceResult::success($grant, $message);
    }
}
