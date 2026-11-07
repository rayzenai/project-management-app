<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line of a response's history: `submitted`, `edited`, `stage` (data
 * `from`, `to`, optional `note`), `note` (data `text`) or `task` (data
 * `task_id`).
 *
 * @property int $id
 * @property int $form_response_id
 * @property string $kind
 * @property int|null $user_id
 * @property array<string, mixed> $data
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
class FormResponseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['form_response_id', 'kind', 'user_id', 'data'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
