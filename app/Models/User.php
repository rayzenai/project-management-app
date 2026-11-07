<?php

namespace App\Models;

use App\Support\Locales;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string|null $git_username
 * @property int|null $workspace_organization_id the organization a super-admin's workspace is switched to (WorkspaceOrganization); null is all
 * @property Carbon|null $last_seen_at when they last used the workspace, to the hour (TouchLastSeen); null is never
 * @property Carbon|null $invited_at when they were last invited: their login made by an invite (InvitePeopleService) or an invite link sent (PasswordLinkService::sendInvite); null is never
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
     * Get this user's appearance and notification preferences.
     *
     * @return HasOne<UserPreference, $this>
     */
    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /**
     * The language they chose (App\Support\Locales), or $fallback while they
     * have chosen none the app has.
     */
    public function locale(?string $fallback = Locales::DEFAULT): ?string
    {
        $saved = $this->preferences?->locale;

        return Locales::isSupported($saved) ? $saved : $fallback;
    }

    /**
     * @return array{theme: string, font_override: array<string, string|null>|null}
     */
    public function appearance(): array
    {
        $preferences = $this->preferences()->firstOrNew([], [
            'theme' => config('themes.default', 'system'),
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
        ];
    }

    /**
     * Which emails this user gets: right away (`email_notifications`), the
     * morning email and the weekly brief. All on until they turn one off.
     *
     * @return array{now: bool, morning: bool, weekly: bool}
     */
    public function emailPreferences(): array
    {
        $preferences = $this->preferences;

        return [
            'now' => $preferences === null || $preferences->email_notifications,
            'morning' => $preferences === null || $preferences->email_morning,
            'weekly' => $preferences === null || $preferences->email_weekly,
        ];
    }

    /**
     * The optional features this user turned on in Settings; both start off.
     *
     * @return array{task_notes: bool, contacts: bool}
     */
    public function features(): array
    {
        $preferences = $this->preferences()->first();

        return [
            'task_notes' => $preferences !== null && $preferences->task_notes_enabled,
            'contacts' => $preferences !== null && $preferences->contacts_enabled,
        ];
    }

    /**
     * Stored trimmed and lowercase, so sign-in and password links, which
     * look it up lowercase, find it however it was typed.
     *
     * @return Attribute<string, ?string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null ? null : mb_strtolower(trim($value)));
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
            'workspace_organization_id' => 'integer',
            'last_seen_at' => 'datetime',
            'invited_at' => 'datetime',
        ];
    }
}
