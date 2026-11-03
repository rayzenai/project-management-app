<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A private to-do ("Just for me"): its owner's alone, on every surface
 * (WorkspaceAccess::ownsPersonalTodo). It shows on My work and nowhere else,
 * is never counted, logged or notified, and "Move to a project" turns it
 * into a task (MovePersonalTodoService).
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $done_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PersonalTodo extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'due_on',
        'done_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'due_on' => 'date',
            'done_at' => 'datetime',
        ];
    }
}
