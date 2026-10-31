<?php

namespace App\Http\Requests\Attendance;

/**
 * An office place: a name, its position and the radius within which a punch
 * counts as near it.
 */
class SaveAttendancePlaceRequest extends ManageAttendanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_m' => ['required', 'integer', 'between:20,5000'],
        ];
    }

    /**
     * @return array{name: string, latitude: float, longitude: float, radius_m: int}
     */
    public function place(): array
    {
        return [
            'name' => trim($this->string('name')->value()),
            'latitude' => (float) $this->input('latitude'),
            'longitude' => (float) $this->input('longitude'),
            'radius_m' => $this->integer('radius_m'),
        ];
    }
}
