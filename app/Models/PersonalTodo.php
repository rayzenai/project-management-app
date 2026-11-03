<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A private to-do ("Just for me"): its owner's alone, on every surface
 * (WorkspaceAccess::ownsPersonalTodo). It shows on My work and in the top
 * bar's to-do list and nowhere else, is never logged or notified, and
 * "Move to a project" turns it into a task (MovePersonalTodoService). It is
 * "doing" while `started_at` is set and it is not done. A deleted one stays
 * in the trash for TRASH_DAYS, so its Undo can bring it back.
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $done_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class PersonalTodo extends Model
{
    use SoftDeletes;

    /** workspace:prune-trashed removes a deleted to-do after this many days. */
    public const TRASH_DAYS = 7;

    /** Its owner (`user_id`), `started_at` and `done_at` are set by the services only. */
    protected $fillable = [
        'title',
        'due_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'due_on' => 'date',
            'started_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }
}
