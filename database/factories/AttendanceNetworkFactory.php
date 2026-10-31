<?php

namespace Database\Factories;

use App\Models\AttendanceNetwork;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceNetwork>
 */
class AttendanceNetworkFactory extends Factory
{
    protected $model = AttendanceNetwork::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): int => OrganizationFactory::defaultId(),
            'label' => 'Office Wi-Fi',
            'cidr' => '10.5.20.0/24',
        ];
    }
}
