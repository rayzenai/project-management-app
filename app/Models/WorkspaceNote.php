<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property string $body
 * @property int $position_x
 * @property int $position_y
 * @property string $color
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class WorkspaceNote extends Model
{
    use SoftDeletes;

    public const COLORS = ['amber', 'rose', 'sky', 'emerald', 'violet'];

    protected $table = 'workspace_notes';

    protected $fillable = [
        'user_id',
        'title',
        'body',
        'position_x',
        'position_y',
        'color',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position_x' => 'integer',
            'position_y' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
