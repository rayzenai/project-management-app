<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get this user's PMOPM appearance and notification preferences.
     *
     * @return HasOne<UserPreference, $this>
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * @return array{theme: string, font_override: array<string, string|null>|null, email_notifications: bool}
     */
    public function appearance(): array
    {
        $preferences = $this->preferences()->firstOrNew([], [
            'theme' => config('themes.default', 'system'),
            'email_notifications' => true,
        ]);

        // A theme or font saved under an earlier catalogue no longer resolves;
        // fall back to the defaults rather than sharing half-valid appearance.
        /** @var array<string, mixed> $themes */
        $themes = config('themes.themes', []);
        $theme = array_key_exists($preferences->theme, $themes)
            ? $preferences->theme
            : (string) config('themes.default', 'system');

        /** @var array{display?: list<string>, body?: list<string>, mono?: list<string>} $allowed */
        $allowed = config('themes.font_allow_list', []);
        $fontOverride = null;

        if (is_array($preferences->font_override)) {
            $fontOverride = [];

            foreach (['display', 'body', 'mono'] as $role) {
                $value = $preferences->font_override[$role] ?? null;
                $fontOverride[$role] = is_string($value) && in_array($value, $allowed[$role] ?? [], true)
                    ? $value
                    : null;
            }
        }

        return [
            'theme' => $theme,
            'font_override' => $fontOverride,
            'email_notifications' => $preferences->email_notifications,
        ];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
