<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendancePlaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An office of the organization: a punch whose location falls within
 * `radius_m` metres of it is "near office".
 *
 * @property int $id
 * @property int $organization_id
 * @property string $name
 * @property float $latitude
 * @property float $longitude
 * @property int $radius_m
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AttendancePlace extends Model
{
    /** @use HasFactory<AttendancePlaceFactory> */
    use HasFactory;

    protected $fillable = ['organization_id', 'name', 'latitude', 'longitude', 'radius_m'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'radius_m' => 'integer'];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
