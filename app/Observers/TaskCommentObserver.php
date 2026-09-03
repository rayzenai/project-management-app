<?php

namespace App\Observers;

use App\Models\ProjectActivity;
use App\Models\TaskComment;
use App\Services\ProjectActivityRecorder;

class TaskCommentObserver
{
    public function created(TaskComment $comment): void
    {
        ProjectActivityRecorder::record(
            taskId: $comment->task_id,
            subject: $comment,
            action: ProjectActivity::ACTION_COMMENTED,
            description: 'Commented: "'.ProjectActivityRecorder::truncate($comment->body, 60).'"',
        );
    }
}
