<?php

namespace App\Observers;

use App\Jobs\SyncRepositoryAccess;
use App\Models\GitRepository;
use App\Models\Member;
use App\Support\Background;

/**
 * A person moved to another organization: their grants count only in their
 * organization or on a project's team (GitRepositoryGrant::scopeEffective),
 * so each repository they hold one on is synced with the forge.
 */
class MemberObserver
{
    public function updated(Member $member): void
    {
        if (! $member->wasChanged('organization_id')) {
            return;
        }

        $repositories = GitRepository::query()
            ->whereIn('id', $member->repositoryGrants()->select('git_repository_id'))
            ->orderBy('id')
            ->get();

        foreach ($repositories as $repository) {
            Background::dispatch(new SyncRepositoryAccess($repository));
        }
    }
}
