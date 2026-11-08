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
 * @property bool $email_notifications right-away email (SendEmailUpdates)
 * @property bool $email_morning the morning email
 * @property bool $email_weekly the weekly brief
 * @property bool $task_notes_enabled
 * @property bool $contacts_enabled
 * @property string|null $locale the language they chose to read the app in (App\Support\Locales); null: not chosen
 */
#[Fillable(['user_id', 'theme', 'font_override', 'email_notifications', 'email_morning', 'email_weekly', 'task_notes_enabled', 'contacts_enabled', 'locale'])]
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
            'email_morning' => 'boolean',
            'email_weekly' => 'boolean',
            'task_notes_enabled' => 'boolean',
            'contacts_enabled' => 'boolean',
        ];
    }
}
