<?php

namespace App\Services\Attendance\Concerns;

use App\Models\LeaveRequest;
use App\Models\Member;
use App\Support\ServiceResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The rules every new leave or kaaj meets, asked for or recorded: a kind,
 * whole days with the start on or before the end and at most 90 of them, a
 * reason, a place for kaaj, and no overlap with the person's own pending
 * or approved leave and kaaj. Saved under a lock on the person's row, so
 * two requests at once can't both pass the overlap check.
 */
trait SavesLeave
{
    public const MAX_DAYS = 90;

    /**
     * @param  array<string, mixed>  $attributes  kind, starts_on, ends_on (Y-m-d), reason, place
     * @param  array<string, mixed>  $extra  status and who asked or decided
     */
    private function saveLeave(Member $member, array $attributes, array $extra, string $overlapMessage, string $savedMessage): ServiceResult
    {
        $kind = (string) ($attributes['kind'] ?? '');
        $reason = trim((string) ($attributes['reason'] ?? ''));
        $place = trim((string) ($attributes['place'] ?? ''));

        if (! in_array($kind, LeaveRequest::KINDS, true)) {
            return ServiceResult::failure('Choose leave or kaaj.', 422, ['kind' => 'Choose leave or kaaj.']);
        }

        $startsOn = self::day($attributes['starts_on'] ?? null);
        $endsOn = self::day($attributes['ends_on'] ?? null);

        if ($startsOn === null || $endsOn === null) {
            return ServiceResult::failure('Choose the days.', 422, [$startsOn === null ? 'starts_on' : 'ends_on' => 'Choose the days.']);
        }

        $failure = match (true) {
            $endsOn->lt($startsOn) => ['ends_on', "The last day can't be before the first."],
            (int) round($startsOn->diffInDays($endsOn)) + 1 > self::MAX_DAYS => ['ends_on', 'Ask for at most '.self::MAX_DAYS.' days at a time.'],
            $reason === '' => ['reason', 'Give a reason.'],
            $kind === LeaveRequest::KIND_KAAJ && $place === '' => ['place', 'Say where the kaaj is.'],
            default => null,
        };

        if ($failure !== null) {
            return ServiceResult::failure($failure[1], 422, [$failure[0] => $failure[1]]);
        }

        return DB::transaction(function () use ($member, $kind, $startsOn, $endsOn, $reason, $place, $extra, $overlapMessage, $savedMessage): ServiceResult {
            Member::query()->whereKey($member->id)->lockForUpdate()->first();

            $overlaps = LeaveRequest::query()
                ->where('member_id', $member->id)
                ->whereIn('status', [LeaveRequest::PENDING, LeaveRequest::APPROVED])
                ->overlapping($startsOn, $endsOn)
                ->exists();

            if ($overlaps) {
                return ServiceResult::failure($overlapMessage, 422, ['starts_on' => $overlapMessage]);
            }

            $leave = LeaveRequest::query()->create([
                'organization_id' => $member->organization_id,
                'member_id' => $member->id,
                'kind' => $kind,
                'starts_on' => $startsOn->toDateString(),
                'ends_on' => $endsOn->toDateString(),
                'reason' => $reason,
                'place' => $kind === LeaveRequest::KIND_KAAJ ? $place : null,
                ...$extra,
            ]);

            return ServiceResult::success($leave->load(['member.designation', 'decider']), $savedMessage);
        });
    }

    /** A Y-m-d day, or null for anything else. */
    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
