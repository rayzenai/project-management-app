<?php

namespace App\Queries;

use App\Models\PersonalTodo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The top bar's to-do list: a person's own private to-dos, in one query,
 * as three lists. Doing: started and not done, the oldest start first. To
 * do: neither, by date (undated last), then the oldest first. Done: done in
 * the last DONE_DAYS days (as My work's Done), the newest first, at most
 * DONE_LIMIT. Nobody else's, ever; deleted ones are left out. `openCount`
 * is the number beside the button: To do and Doing.
 *
 * @phpstan-type Todo array{id: int, title: string, due_on: string|null, started_at: string|null, done_at: string|null}
 * @phpstan-type TodoLists array{doing: list<Todo>, open: list<Todo>, done: list<Todo>}
 */
final class PersonalTodosQuery
{
    public const DONE_DAYS = 7;

    public const DONE_LIMIT = 20;

    /**
     * @return TodoLists
     */
    public static function for(User $user): array
    {
        $since = CarbonImmutable::today()->subDays(self::DONE_DAYS);
        $todos = PersonalTodo::query()
            ->where('user_id', $user->id)
            ->where(fn (Builder $q) => $q->whereNull('done_at')->orWhere('done_at', '>=', $since))
            ->get()
            ->all();

        $doing = array_filter($todos, fn (PersonalTodo $todo): bool => $todo->done_at === null && $todo->started_at !== null);
        $open = array_filter($todos, fn (PersonalTodo $todo): bool => $todo->done_at === null && $todo->started_at === null);
        $done = array_filter($todos, fn (PersonalTodo $todo): bool => $todo->done_at !== null);

        usort($doing, fn (PersonalTodo $a, PersonalTodo $b): int => [$a->started_at, $a->id] <=> [$b->started_at, $b->id]);
        usort($open, fn (PersonalTodo $a, PersonalTodo $b): int => [$a->due_on === null, $a->due_on, $a->created_at, $a->id]
            <=> [$b->due_on === null, $b->due_on, $b->created_at, $b->id]);
        usort($done, fn (PersonalTodo $a, PersonalTodo $b): int => [$b->done_at, $b->id] <=> [$a->done_at, $a->id]);

        return [
            'doing' => array_map(self::row(...), $doing),
            'open' => array_map(self::row(...), $open),
            'done' => array_map(self::row(...), array_slice($done, 0, self::DONE_LIMIT)),
        ];
    }

    /** The open to-dos (To do and Doing) of `$user`: the top bar's count. */
    public static function openCount(User $user): int
    {
        return PersonalTodo::query()->where('user_id', $user->id)->whereNull('done_at')->count();
    }

    /**
     * @return array{id: int, title: string, due_on: string|null, started_at: string|null, done_at: string|null}
     */
    private static function row(PersonalTodo $todo): array
    {
        return [
            'id' => $todo->id,
            'title' => $todo->title,
            'due_on' => $todo->due_on?->toDateString(),
            'started_at' => $todo->started_at?->toIso8601String(),
            'done_at' => $todo->done_at?->toIso8601String(),
        ];
    }
}
