<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $theme
 * @property array<string, string|null>|null $font_override
 * @property bool $email_notifications
 * @property bool $task_notes_enabled
 * @property bool $contacts_enabled
 */
#[Fillable(['user_id', 'theme', 'font_override', 'email_notifications', 'task_notes_enabled', 'contacts_enabled'])]
class UserPreference extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'font_override' => 'array',
            'email_notifications' => 'boolean',
            'task_notes_enabled' => 'boolean',
            'contacts_enabled' => 'boolean',
        ];
    }
}
