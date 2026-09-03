<?php

namespace App\Services\Workspace;

use App\Models\Task;
use App\Models\User;
use App\Services\Workspace\Concerns\NotifiesMentions;
use App\Support\MentionParser;
use App\Support\ServiceResult;
use Throwable;

class CreateTaskCommentService
{
    use NotifiesMentions;

    public function execute(Task $task, User $author, string $body): ServiceResult
    {
        try {
            $ids = MentionParser::memberIds($body);

            $comment = $task->comments()->create([
                'user_id' => $author->id,
                'body' => $body,
                'mentioned_member_ids' => $ids,
            ]);

            $this->notifyMentions($task, $author, $ids, $body);

            return ServiceResult::success($comment, 'Comment added.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
