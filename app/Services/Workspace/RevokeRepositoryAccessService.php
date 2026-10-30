<?php

namespace App\Services\Workspace;

use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Observers\GitRepositoryGrantObserver;
use App\Support\ServiceResult;

/**
 * Takes a person's access to a linked repository away. The activity row
 * and the forge sync follow from the grant's observer
 * ({@see GitRepositoryGrantObserver}).
 */
class RevokeRepositoryAccessService
{
    public function execute(GitRepositoryGrant $grant): ServiceResult
    {
        $member = Member::withTrashed()->findOrFail($grant->member_id);
        $repository = $grant->repository;

        $grant->delete();

        return ServiceResult::success(null, "Removed {$member->name}'s access to {$repository->full_name}.");
    }
}
