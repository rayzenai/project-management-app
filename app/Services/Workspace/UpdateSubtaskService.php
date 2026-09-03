<?php

namespace App\Services\Workspace;

use App\Models\Subtask;
use App\Support\ServiceResult;
use Throwable;

class UpdateSubtaskService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Subtask $subtask, array $attributes): ServiceResult
    {
        try {
            if (array_key_exists('is_done', $attributes)) {
                $done = (bool) $attributes['is_done'];
                $subtask->is_done = $done;
                $subtask->done_at = $done ? now() : null;
            }

            foreach (['body', 'due_at', 'position'] as $key) {
                if (array_key_exists($key, $attributes)) {
                    $subtask->{$key} = $attributes[$key];
                }
            }

            $subtask->save();

            return ServiceResult::success($subtask->fresh(), $subtask->is_done ? 'Marked done.' : 'Updated.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
