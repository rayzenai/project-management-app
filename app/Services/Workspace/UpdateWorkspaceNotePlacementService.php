<?php

namespace App\Services\Workspace;

use App\Models\WorkspaceNote;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpdateWorkspaceNotePlacementService
{
    /**
     * Updates only the supplied placement attributes (position and/or colour);
     * the note body is left untouched.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function execute(WorkspaceNote $note, array $attributes): ServiceResult
    {
        $changes = array_intersect_key($attributes, array_flip(['position_x', 'position_y', 'color']));
        $changes = array_filter($changes, static fn ($value): bool => $value !== null);

        if ($changes === []) {
            return ServiceResult::failure('Nothing to update.', 422);
        }

        try {
            DB::transaction(fn () => $note->update($changes));

            return ServiceResult::success($note->fresh(), 'Note moved.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
