<?php

namespace Database\Factories;

use App\Models\AttendancePunch;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A clock-in from the office network today, at 9:50 unless told otherwise.
 * Give it a member (`for(Member)` or `member_id`); the organization follows
 * the member's.
 *
 * @extends Factory<AttendancePunch>
 */
class AttendancePunchFactory extends Factory
{
    protected $model = AttendancePunch::class;

    public function definition(): array
    {
        return [
            'member_id' => fn (): int => Member::factory()->create()->id,
            'organization_id' => fn (array $attributes): int => (int) Member::query()->whereKey($attributes['member_id'])->value('organization_id'),
            'work_date' => fn (array $attributes): string => isset($attributes['punched_at'])
                ? now()->parse($attributes['punched_at'])->toDateString()
                : now()->toDateString(),
            'kind' => AttendancePunch::IN,
            'punched_at' => fn (): string => now()->setTime(9, 50)->toDateTimeString(),
            'source' => AttendancePunch::SOURCE_WEB,
            'ip' => '10.5.20.15',
            'place' => AttendancePunch::PLACE_OFFICE_NETWORK,
        ];
    }

    /**
     * A punch of the given kind at the given time, on that time's day.
     */
    public function at(string $kind, string $dateTime): self
    {
        return $this->state([
            'kind' => $kind,
            'punched_at' => $dateTime,
            'work_date' => now()->parse($dateTime)->toDateString(),
        ]);
    }
}
