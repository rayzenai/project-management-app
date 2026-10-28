<?php

namespace App\Services\Workspace\Concerns;

use App\Models\Member;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Support\MentionParser;
use App\Support\WorkspaceAccess;
use Illuminate\Support\Str;

trait NotifiesMentions
{
    /**
     * The mentioned members who may see the task: a login that can view its
     * project, or a person without a login who could be assigned to it. The
     * rest are dropped before storing, so a hand-made `@[x](member:ID)` can
     * neither notify an outsider nor reveal their name.
     *
     * @param  list<int>  $memberIds
     * @return list<int>
     */
    private function mentionableIds(Task $task, array $memberIds): array
    {
        if ($memberIds === []) {
            return [];
        }

        $assignable = Member::assignableFor($task->project)->whereIn('id', $memberIds)->pluck('id')->all();

        return array_values(array_filter($memberIds, function (int $id) use ($assignable, $task): bool {
            if (in_array($id, $assignable, true)) {
                return true;
            }

            $user = Member::query()->with('user')->find($id)?->user;

            return $user !== null && WorkspaceAccess::canViewProject($user, $task->project);
        }));
    }

    /**
     * Notify each mentioned member that has a linked login and can see the
     * task, excluding the author. The comment box only offers the project's
     * people; this holds for hand-made requests too, so a mention never
     * shows a task title to someone outside the project.
     *
     * @param  list<int>  $memberIds
     */
    private function notifyMentions(Task $task, User $author, array $memberIds, string $body): void
    {
        if ($memberIds === []) {
            return;
        }

        $excerpt = Str::limit(MentionParser::toDisplayText($body), 80);

        Member::with('user')->whereIn('id', $memberIds)->get()
            ->pluck('user')->filter()
            ->reject(fn ($user): bool => $user->id === $author->id)
            ->filter(fn ($user): bool => WorkspaceAccess::canViewProject($user, $task->project))
            ->unique('id')
            ->each(fn ($user) => $user->notify(
                new MentionedInComment($task, $author->name, $excerpt)
            ));
    }
}
