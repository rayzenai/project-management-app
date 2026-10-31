<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of an organization's designations (Executive Director, Director,
 * Software Engineer, ...). `rank` orders them, smaller first (more senior).
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property int $rank
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Designation extends Model
{
    /** @use HasFactory<DesignationFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'name', 'rank'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rank' => 'integer'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<Member, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Member::class);
    }
}
