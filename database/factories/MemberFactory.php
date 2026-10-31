<?php

namespace Database\Factories;

use App\Models\Designation;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Member>
 */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'is_active' => true,
            'organization_id' => fn (): int => OrganizationFactory::defaultId(),
        ];
    }

    public function linkedTo(mixed $user): self
    {
        return $this->state([
            'user_id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    public function withUser(): self
    {
        return $this->state(function (): array {
            $user = User::factory()->create();

            return [
                'user_id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ];
        });
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * Holds the designation of this name in their organization, made when
     * the organization has none by that name.
     */
    public function designated(string $name): self
    {
        return $this->afterCreating(function (Member $member) use ($name): void {
            $designation = Designation::query()->firstOrCreate(['organization_id' => $member->organization_id, 'name' => $name]);
            $member->update(['designation_id' => $designation->getKey()]);
        });
    }
}
