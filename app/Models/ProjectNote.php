<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $type
 * @property string $body
 * @property CarbonImmutable|null $happened_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class ProjectNote extends Model
{
    use SoftDeletes;

    public const TYPES = [
        'general' => 'General note',
        'action_taken' => 'Action taken',
        'meeting' => 'Meeting',
        'blocker' => 'Blocker',
        'milestone' => 'Milestone',
        'decision' => 'Decision',
    ];

    protected $table = 'project_notes';

    protected $fillable = [
        'task_id',
        'user_id',
        'type',
        'body',
        'happened_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'happened_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
