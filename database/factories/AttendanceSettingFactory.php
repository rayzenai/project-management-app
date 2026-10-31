<?php

namespace Database\Factories;

use App\Models\AttendanceSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Attendance turned on with the config defaults.
 *
 * @extends Factory<AttendanceSetting>
 */
class AttendanceSettingFactory extends Factory
{
    protected $model = AttendanceSetting::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): int => OrganizationFactory::defaultId(),
            'enabled' => true,
            'enabled_at' => now(),
            ...AttendanceSetting::defaults(),
        ];
    }

    public function off(): self
    {
        return $this->state(['enabled' => false, 'enabled_at' => null]);
    }
}
