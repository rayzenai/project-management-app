<?php

namespace App\Services\Workspace;

use App\Models\Organization;
use App\Support\ServiceResult;

class DisableGitService
{
    public function execute(Organization $organization): ServiceResult
    {
        $organization->gitConnection()->delete();

        return ServiceResult::success(null, 'Git is off.');
    }
}
