<?php

namespace App\Services\Workspace;

use App\Models\ProjectNote;
use App\Support\ServiceResult;
use Throwable;

class DeleteNoteService
{
    public function execute(ProjectNote $note): ServiceResult
    {
        try {
            $note->delete();

            return ServiceResult::success(message: 'Note deleted.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
