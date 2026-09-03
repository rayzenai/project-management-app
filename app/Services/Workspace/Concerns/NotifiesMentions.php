<?php

namespace App\Services\Workspace\Concerns;

use App\Models\Member;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Support\MentionParser;
use Illuminate\Support\Str;

trait NotifiesMentions
{
    /**
     * Notify each mentioned member that has a linked login, excluding the author.
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
            ->unique('id')
            ->each(fn ($user) => $user->notify(
                new MentionedInComment($task, $author->name, $excerpt)
            ));
    }
}
