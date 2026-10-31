<?php

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending day of leave today. Give it a member; the organization follows
 * the member's.
 *
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'member_id' => fn (): int => Member::factory()->create()->id,
            'organization_id' => fn (array $attributes): int => (int) Member::query()->whereKey($attributes['member_id'])->value('organization_id'),
            'kind' => LeaveRequest::KIND_LEAVE,
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->toDateString(),
            'reason' => 'Family matter',
            'status' => LeaveRequest::PENDING,
        ];
    }

    public function approved(): self
    {
        return $this->state(['status' => LeaveRequest::APPROVED, 'decided_at' => now()]);
    }

    public function kaaj(string $place = 'Pokhara'): self
    {
        return $this->state(['kind' => LeaveRequest::KIND_KAAJ, 'place' => $place]);
    }
}
