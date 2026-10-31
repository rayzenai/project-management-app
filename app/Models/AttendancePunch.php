<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AttendancePunchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One press of the clock: clock in, start lunch, end lunch or clock out, at
 * the server's time, with where it came from. A day holds each kind at
 * most once, in that order. Punches are never edited or deleted.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $member_id
 * @property CarbonImmutable $work_date
 * @property string $kind in, lunch_start, lunch_end or out
 * @property CarbonImmutable $punched_at
 * @property string $source web, app or device
 * @property string|null $device_ref
 * @property string|null $ip
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int|null $accuracy_m
 * @property string $place office_network, near_office, outside or unknown
 * @property int|null $attendance_place_id
 * @property int|null $distance_m
 * @property string|null $note
 * @property string|null $user_agent
 * @property int|null $recorded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AttendancePunch extends Model
{
    /** @use HasFactory<AttendancePunchFactory> */
    use HasFactory;

    public const IN = 'in';

    public const LUNCH_START = 'lunch_start';

    public const LUNCH_END = 'lunch_end';

    public const OUT = 'out';

    /** The day's order. */
    public const KINDS = [self::IN, self::LUNCH_START, self::LUNCH_END, self::OUT];

    public const SOURCE_WEB = 'web';

    public const SOURCE_APP = 'app';

    public const SOURCE_DEVICE = 'device';

    public const PLACE_OFFICE_NETWORK = 'office_network';

    public const PLACE_NEAR_OFFICE = 'near_office';

    public const PLACE_OUTSIDE = 'outside';

    public const PLACE_UNKNOWN = 'unknown';

    protected $fillable = [
        'organization_id',
        'member_id',
        'work_date',
        'kind',
        'punched_at',
        'source',
        'device_ref',
        'ip',
        'latitude',
        'longitude',
        'accuracy_m',
        'place',
        'attendance_place_id',
        'distance_m',
        'note',
        'user_agent',
        'recorded_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'punched_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_m' => 'integer',
            'distance_m' => 'integer',
        ];
    }

    /**
     * The kinds that may follow the day's punches so far, in order: clock in
     * first; then start lunch or clock out; after lunch starts only end
     * lunch; after lunch ends only clock out; nothing after clocking out.
     *
     * @param  list<string>  $done  kinds already punched today
     * @return list<string>
     */
    public static function nextKinds(array $done): array
    {
        return match (true) {
            in_array(self::OUT, $done, true) => [],
            in_array(self::LUNCH_END, $done, true) => [self::OUT],
            in_array(self::LUNCH_START, $done, true) => [self::LUNCH_END],
            in_array(self::IN, $done, true) => [self::LUNCH_START, self::OUT],
            default => [self::IN],
        };
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<AttendancePlace, $this>
     */
    public function attendancePlace(): BelongsTo
    {
        return $this->belongsTo(AttendancePlace::class);
    }
}
