<?php

namespace App\Services\Workspace;

use App\Models\GitRepository;
use App\Services\Git\ForgejoClient;
use App\Support\ServiceResult;

/**
 * Stops tracking a repository: its branches, commits, pull requests and
 * task links go with the row (database cascades). The repository itself is
 * untouched on the forge, except that its own issue tracker is handed back
 * (best effort, so `TP-12` there stops leading here); a forge that refuses
 * or is down never blocks the unlink. Its collaborators stay as they are: the
 * access sync no longer writes them, and offboarding still takes a
 * deactivated person off them.
 */
class UnlinkRepositoryService
{
    public function __construct(private DisableGitService $disable) {}

    public function execute(GitRepository $repo): ServiceResult
    {
        $this->disable->restoreIssueTracker(ForgejoClient::for($repo->gitConnection), $repo);

        $repo->delete();

        return ServiceResult::success(null, "Unlinked {$repo->full_name}. The repository is untouched on the forge. People who had access keep it on the forge until it's changed there or the repository is linked again.");
    }
}
