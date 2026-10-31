<?php

namespace Database\Factories;

use App\Models\AttendancePlace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An office in Kathmandu (Singha Durbar) unless told otherwise.
 *
 * @extends Factory<AttendancePlace>
 */
class AttendancePlaceFactory extends Factory
{
    protected $model = AttendancePlace::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): int => OrganizationFactory::defaultId(),
            'name' => 'Head office',
            'latitude' => 27.6980,
            'longitude' => 85.3240,
            'radius_m' => 150,
        ];
    }
}
