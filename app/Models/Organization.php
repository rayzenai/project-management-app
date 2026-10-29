<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * An office or ministry: the tenant boundary. Its teams, projects and members
 * are invisible to every other organization, except through a team a
 * super-admin marked cross-organization. Super-admins belong to none.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $members_count
 * @property-read int|null $teams_count
 * @property-read int|null $projects_count
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    protected static function booted(): void
    {
        static::saving(function (Organization $organization): void {
            if (empty($organization->slug)) {
                $base = Str::slug($organization->name) ?: 'organization';
                $slug = $base;
                $i = 2;
                while (static::query()->where('slug', $slug)->whereKeyNot($organization->getKey())->exists()) {
                    $slug = $base.'-'.$i;
                    $i++;
                }
                $organization->slug = $slug;
            }
        });
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }

    /**
     * @return HasMany<Team, $this>
     */
    public function teams(): HasMany
    {
        return $this->hasMany(Team::class);
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Program, $this>
     */
    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    /**
     * @return HasOne<GitConnection, $this>
     */
    public function gitConnection(): HasOne
    {
        return $this->hasOne(GitConnection::class);
    }

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }
}
