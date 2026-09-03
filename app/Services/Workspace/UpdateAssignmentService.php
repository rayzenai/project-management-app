<?php

namespace App\Services\Workspace;

use App\Models\ProjectAssignment;
use App\Support\ServiceResult;
use Throwable;

class UpdateAssignmentService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(ProjectAssignment $assignment, array $attributes): ServiceResult
    {
        try {
            foreach (['role', 'priority', 'is_focused', 'snoozed_until', 'personal_progress', 'personal_due_at', 'personal_status_note'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $assignment->{$key} = $attributes[$key];
                }
            }

            $assignment->save();

            return ServiceResult::success($assignment->fresh('member'), 'Assignment updated.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
