<?php

namespace App\Services\Workspace;

use App\Models\WorkspaceNote;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class DeleteWorkspaceNoteService
{
    public function execute(WorkspaceNote $note): ServiceResult
    {
        try {
            DB::transaction(fn () => $note->delete());

            return ServiceResult::success(null, 'Note deleted.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
