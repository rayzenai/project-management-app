<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $member_id
 * @property int $task_id
 * @property string|null $role
 * @property string $priority
 * @property int $personal_progress
 * @property CarbonImmutable|null $personal_due_at
 * @property string|null $personal_status_note
 * @property bool $is_focused
 * @property CarbonImmutable|null $snoozed_until
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class ProjectAssignment extends Model
{
    use SoftDeletes;

    protected $table = 'project_assignments';

    protected $fillable = [
        'member_id',
        'task_id',
        'role',
        'priority',
        'is_focused',
        'snoozed_until',
        'personal_progress',
        'personal_due_at',
        'personal_status_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_due_at' => 'date',
            'snoozed_until' => 'date',
            'is_focused' => 'boolean',
            'personal_progress' => 'integer',
        ];
    }

    /**
     * Snoozed assignments are those with a future snoozed_until date.
     */
    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
