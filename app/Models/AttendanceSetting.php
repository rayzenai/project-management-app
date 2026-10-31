<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendanceSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's attendance: whether it is on (a super-admin's switch),
 * its working days and hours, seasonal hours and the grace before "late".
 * An organization without a row has attendance off, and these defaults
 * (config/attendance.php) once it is turned on.
 *
 * @property int $id
 * @property int $organization_id
 * @property bool $enabled
 * @property int|null $enabled_by
 * @property CarbonImmutable|null $enabled_at
 * @property list<int> $workdays ISO weekdays, 1 = Monday
 * @property string $start_time HH:MM
 * @property string $end_time HH:MM
 * @property list<array{name: string, from: string, to: string, start: string, end: string}> $seasons
 * @property int $grace_minutes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AttendanceSetting extends Model
{
    /** @use HasFactory<AttendanceSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'enabled',
        'enabled_by',
        'enabled_at',
        'workdays',
        'start_time',
        'end_time',
        'seasons',
        'grace_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'workdays' => 'array',
            'seasons' => 'array',
            'grace_minutes' => 'integer',
        ];
    }

    /**
     * The organization's settings, or unsaved defaults (attendance off).
     */
    public static function forOrganization(int $organizationId): self
    {
        return self::query()->where('organization_id', $organizationId)->first()
            ?? new self(['organization_id' => $organizationId, 'enabled' => false, ...self::defaults()]);
    }

    /**
     * @return array{workdays: list<int>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int}
     */
    public static function defaults(): array
    {
        /** @var array{workdays: list<int>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int} $defaults */
        $defaults = [
            'workdays' => array_values(array_map('intval', (array) config('attendance.workdays'))),
            'start_time' => (string) config('attendance.start_time'),
            'end_time' => (string) config('attendance.end_time'),
            'seasons' => array_values((array) config('attendance.seasons')),
            'grace_minutes' => (int) config('attendance.grace_minutes'),
        ];

        return $defaults;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Whoever last turned attendance on.
     *
     * @return BelongsTo<User, $this>
     */
    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }
}
