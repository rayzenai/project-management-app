<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\LeaveRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leave, or kaaj (official duty away from the office), over whole days.
 * Approved leave and kaaj take the person out of the expected count; kaaj
 * is duty, never absence.
 *
 * @property int $id
 * @property int $organization_id
 * @property int $member_id
 * @property string $kind leave or kaaj
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property string $reason
 * @property string|null $place
 * @property string $status pending, approved, rejected or cancelled
 * @property int|null $decided_by
 * @property CarbonImmutable|null $decided_at
 * @property string|null $decision_note
 * @property int|null $requested_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class LeaveRequest extends Model
{
    /** @use HasFactory<LeaveRequestFactory> */
    use HasFactory;

    public const KIND_LEAVE = 'leave';

    public const KIND_KAAJ = 'kaaj';

    public const KINDS = [self::KIND_LEAVE, self::KIND_KAAJ];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'organization_id',
        'member_id',
        'kind',
        'starts_on',
        'ends_on',
        'reason',
        'place',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'requested_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /**
     * Requests whose days touch the range (inclusive).
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString());
    }

    public function covers(CarbonInterface $date): bool
    {
        $day = $date->toDateString();

        return $this->starts_on->toDateString() <= $day && $this->ends_on->toDateString() >= $day;
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /**
     * @return BelongsTo<Member, $this>
     */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'decided_by');
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
