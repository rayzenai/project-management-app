<?php

namespace App\Observers;

use App\Jobs\SyncRepositoryAccess;
use App\Models\GitRepository;
use App\Models\Member;
use App\Services\Structure\MoveReportsUpService;
use App\Support\Background;

/**
 * A person moved to another organization: their grants count only in their
 * organization or on a project's team (GitRepositoryGrant::scopeEffective),
 * so each repository they hold one on is synced with the forge.
 *
 * Reporting lines: when a person moves to another organization or is
 * deleted, the people who reported to them move up to their own higher
 * authority (MoveReportsUpService). Deactivation keeps every line.
 */
class MemberObserver
{
    public function __construct(private MoveReportsUpService $reports) {}

    public function updated(Member $member): void
    {
        if (! $member->wasChanged('organization_id')) {
            return;
        }

        $previousBoss = $member->getOriginal('reports_to_id');
        $this->reports->execute($member, is_numeric($previousBoss) ? (int) $previousBoss : null, leftOrganization: true);

        $repositories = GitRepository::query()
            ->whereIn('id', $member->repositoryGrants()->select('git_repository_id'))
            ->orderBy('id')
            ->get();

        foreach ($repositories as $repository) {
            Background::dispatch(new SyncRepositoryAccess($repository));
        }
    }

    /**
     * Before the row goes (a hard delete would null the lines first).
     */
    public function deleting(Member $member): void
    {
        $this->reports->execute($member, $member->reports_to_id);
    }
}
