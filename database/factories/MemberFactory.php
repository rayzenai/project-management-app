<?php

namespace Database\Factories;

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
            'title' => $this->faker->optional()->jobTitle(),
            'is_active' => true,
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
}
