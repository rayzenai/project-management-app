<?php

namespace App\Services\Workspace;

use App\Models\PersonalTodo;
use App\Support\ServiceResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Changes a private to-do's title or date, starts or stops working on it
 * (`doing`, which keeps the first start), or ticks it done (`done`, which
 * keeps the first time it was done) or open again. Done leaves the start
 * as it was, so a to-do opened again is "doing" again if it was.
 */
class UpdatePersonalTodoService
{
    /**
     * @param  array<string, mixed>  $attributes  `title`, `due_on` (Y-m-d or null), `doing`, `done`
     */
    public function execute(PersonalTodo $todo, array $attributes): ServiceResult
    {
        try {
            $todo->fill(Arr::only($attributes, ['title', 'due_on']));

            if (array_key_exists('doing', $attributes)) {
                $todo->started_at = (bool) $attributes['doing'] ? ($todo->started_at ?? CarbonImmutable::now()) : null;
            }

            if (array_key_exists('done', $attributes)) {
                $todo->done_at = (bool) $attributes['done'] ? ($todo->done_at ?? CarbonImmutable::now()) : null;
            }

            $todo->save();

            return ServiceResult::success($todo->fresh(), 'To-do updated.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
