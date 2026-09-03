<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TaskCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string $body
 * @property list<int>|null $mentioned_member_ids
 * @property CarbonImmutable|null $deleted_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class TaskComment extends Model
{
    /** @use HasFactory<TaskCommentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['task_id', 'user_id', 'body', 'mentioned_member_ids'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['mentioned_member_ids' => 'array'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): TaskCommentFactory
    {
        return TaskCommentFactory::new();
    }
}
