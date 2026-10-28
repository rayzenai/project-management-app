<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `task` cannot start until `dependsOn` is finished. Both belong to the same
 * project (StoreTaskDependencyRequest), and the links never form a loop.
 *
 * @property int $id
 * @property int $task_id
 * @property int $depends_on_task_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TaskDependency extends Model
{
    protected $fillable = [
        'task_id',
        'depends_on_task_id',
    ];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function dependsOn(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'depends_on_task_id');
    }

    /**
     * Whether `$from` already depends on `$target`, directly or through other
     * tasks. Adding "target depends on from" would then close a loop.
     */
    public static function reaches(int $from, int $target): bool
    {
        $seen = [];
        $frontier = [$from];

        while ($frontier !== []) {
            if (in_array($target, $frontier, true)) {
                return true;
            }

            $seen = array_merge($seen, $frontier);
            $frontier = array_values(array_diff(
                self::query()->whereIn('task_id', $frontier)->pluck('depends_on_task_id')->map(fn ($id): int => (int) $id)->all(),
                $seen,
            ));
        }

        return false;
    }
}
