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
 * @property string $name
 * @property string|null $organization
 * @property string|null $role
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class ProjectContact extends Model
{
    use SoftDeletes;

    protected $table = 'project_contacts';

    protected $fillable = [
        'task_id',
        'user_id',
        'name',
        'organization',
        'role',
        'email',
        'phone',
        'notes',
    ];

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
