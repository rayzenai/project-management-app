<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An organization's link to a forge organization (Forgejo). The bot token and
 * webhook secret are encrypted at rest and never serialized.
 *
 * @property int $id
 * @property int $organization_id
 * @property string $base_url
 * @property string $owner
 * @property string $token
 * @property string|null $webhook_secret
 * @property int|null $webhook_id
 * @property int|null $enabled_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class GitConnection extends Model
{
    protected $fillable = [
        'organization_id',
        'base_url',
        'owner',
        'token',
        'webhook_secret',
        'webhook_id',
        'enabled_by',
    ];

    /** @var list<string> */
    protected $hidden = ['token', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<GitRepository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(GitRepository::class);
    }

    /**
     * A forge address as stored and compared: scheme and host in lower case,
     * port and path kept, no trailing slash.
     */
    public static function normalizeBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');

        $url = (string) preg_replace_callback('#^[a-z][a-z0-9+.-]*://[^/?\#]*#i', fn (array $m): string => strtolower($m[0]), $url);

        // A default port names the same forge as no port.
        return (string) preg_replace('#^(https://[^/?\#]*):443$|^(http://[^/?\#]*):80$#', '$1$2', $url);
    }

    public function url(): string
    {
        return rtrim($this->base_url, '/').'/'.$this->owner;
    }

    /**
     * The bot token, decrypted.
     *
     * @throws DecryptException when it was sealed with another APP_KEY (a
     *                          copy of production's database on another
     *                          machine, or a rotated key)
     */
    public function decryptedToken(): string
    {
        return (string) $this->token;
    }

    /**
     * The token for scrubbing it out of a forge error message; empty when it
     * can't be read with this app key (ForgejoClient::for already refused to
     * use it then), so logging a failure never fails itself.
     */
    public function readableToken(): string
    {
        try {
            return $this->decryptedToken();
        } catch (DecryptException) {
            return '';
        }
    }
}
