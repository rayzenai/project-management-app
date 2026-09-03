<?php

namespace App\Services\Workspace;

use App\Models\TaskComment;
use App\Services\Workspace\Concerns\NotifiesMentions;
use App\Support\MentionParser;
use App\Support\ServiceResult;
use Throwable;

class UpdateTaskCommentService
{
    use NotifiesMentions;

    public function execute(TaskComment $comment, string $body): ServiceResult
    {
        try {
            $existing = $comment->mentioned_member_ids ?? [];
            $new = MentionParser::memberIds($body);
            $added = array_values(array_diff($new, $existing));

            $comment->update(['body' => $body, 'mentioned_member_ids' => $new]);

            $this->notifyMentions($comment->task, $comment->user, $added, $body);

            return ServiceResult::success($comment->fresh(), 'Comment updated.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
