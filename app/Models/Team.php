<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'teams';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
    ];

    protected static function booted(): void
    {
        static::saving(function (Team $team) {
            if (empty($team->slug)) {
                $base = Str::slug($team->name) ?: 'team';
                $slug = $base;
                $i = 2;
                while (static::withTrashed()->where('slug', $slug)->whereKeyNot($team->getKey())->exists()) {
                    $slug = $base.'-'.$i;
                    $i++;
                }
                $team->slug = $slug;
            }
        });
    }

    /**
     * @return BelongsToMany<Member, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Members whose pivot role marks them as a leader of this team.
     *
     * @return BelongsToMany<Member, $this>
     */
    public function leaders(): BelongsToMany
    {
        return $this->members()->wherePivot('role', 'leader');
    }

    /**
     * @return BelongsToMany<Project, $this>
     */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps();
    }

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }
}
