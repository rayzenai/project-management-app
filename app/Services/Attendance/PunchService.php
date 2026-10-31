<?php

namespace App\Services\Attendance;

use App\Models\AttendancePunch;
use App\Models\Member;
use App\Models\User;
use App\Support\Attendance\PunchPlace;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * One press of the clock: clock in, start lunch, end lunch or clock out, in
 * that order, each once a day. The caller checks
 * {@see WorkspaceAccess::canPunch()}. Under a lock on the member's row it
 * reads today's punches (the app's timezone), refuses a kind out of order,
 * judges where the press came from ({@see PunchPlace}), asks for a note when
 * that is outside the office or unknown, and records the punch at the
 * server's time, never the client's. A double press the lock did not catch
 * meets the unique index and is answered the same way.
 */
class PunchService
{
    public const NOTE_NEEDED = "Say why you're away from the office.";

    public const ALREADY_DONE = 'Already done.';

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy_m?: int|null, note?: string|null, source: string, ip?: string|null, user_agent?: string|null, device_ref?: string|null}  $context
     */
    public function punch(User $user, string $kind, array $context): ServiceResult
    {
        $member = WorkspaceAccess::memberFor($user);

        if ($member === null || $member->organization_id === null) {
            return ServiceResult::failure('You have no organization to clock in to.', 403);
        }

        try {
            return DB::transaction(fn (): ServiceResult => $this->record($member, $user, $kind, $context));
        } catch (UniqueConstraintViolationException) {
            return ServiceResult::failure(self::ALREADY_DONE, 422, ['kind' => self::ALREADY_DONE]);
        }
    }

    /**
     * @param  array{latitude?: float|null, longitude?: float|null, accuracy_m?: int|null, note?: string|null, source: string, ip?: string|null, user_agent?: string|null, device_ref?: string|null}  $context
     */
    private function record(Member $member, User $user, string $kind, array $context): ServiceResult
    {
        Member::query()->whereKey($member->id)->lockForUpdate()->first();

        $now = CarbonImmutable::now();
        $organizationId = (int) $member->organization_id;

        /** @var list<string> $done */
        $done = AttendancePunch::query()
            ->where('member_id', $member->id)
            ->whereDate('work_date', $now->toDateString())
            ->pluck('kind')
            ->all();

        if (! in_array($kind, AttendancePunch::nextKinds($done), true)) {
            $refusal = self::outOfOrder($kind, $done);

            return ServiceResult::failure($refusal, 422, ['kind' => $refusal]);
        }

        $latitude = $context['latitude'] ?? null;
        $longitude = $context['longitude'] ?? null;
        $accuracy = $context['accuracy_m'] ?? null;
        $ip = $context['ip'] ?? null;
        $verdict = PunchPlace::judge($organizationId, $ip, $latitude, $longitude, $accuracy);

        $note = trim((string) ($context['note'] ?? ''));
        $away = in_array($verdict['place'], [AttendancePunch::PLACE_OUTSIDE, AttendancePunch::PLACE_UNKNOWN], true);

        if ($away && $note === '') {
            return ServiceResult::failure(self::NOTE_NEEDED, 422, ['note' => self::NOTE_NEEDED]);
        }

        $punch = AttendancePunch::query()->create([
            'organization_id' => $organizationId,
            'member_id' => $member->id,
            'work_date' => $now->toDateString(),
            'kind' => $kind,
            'punched_at' => $now,
            'source' => $context['source'],
            'device_ref' => $context['device_ref'] ?? null,
            'ip' => $ip,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => $accuracy,
            ...$verdict,
            'note' => $note === '' ? null : $note,
            'user_agent' => isset($context['user_agent']) ? mb_substr($context['user_agent'], 0, 255) : null,
        ]);

        return ServiceResult::success(
            ['punch' => $punch, 'next' => AttendancePunch::nextKinds([...$done, $kind])],
            self::confirmation($kind, $now),
        );
    }

    /**
     * Why a kind can't come next, given the day's punches so far.
     *
     * @param  list<string>  $done
     */
    private static function outOfOrder(string $kind, array $done): string
    {
        $has = fn (string $k): bool => in_array($k, $done, true);

        return match (true) {
            $has(AttendancePunch::OUT) => "You've already clocked out today.",
            $has($kind) => match ($kind) {
                AttendancePunch::IN => "You've already clocked in today.",
                AttendancePunch::LUNCH_START => "You've already started lunch today.",
                default => "You've already ended lunch today.",
            },
            ! $has(AttendancePunch::IN) => 'Clock in first.',
            $kind === AttendancePunch::LUNCH_END => 'Start lunch first.',
            default => 'End lunch first.',
        };
    }

    private static function confirmation(string $kind, CarbonImmutable $at): string
    {
        $time = $at->format('G:i');

        return match ($kind) {
            AttendancePunch::IN => "Clocked in at {$time}.",
            AttendancePunch::LUNCH_START => "Lunch started at {$time}.",
            AttendancePunch::LUNCH_END => "Lunch ended at {$time}.",
            default => "Clocked out at {$time}.",
        };
    }
}
