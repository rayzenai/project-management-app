<?php

namespace App\Http\Requests\Attendance;

use App\Models\Holiday;
use Illuminate\Validation\Rule;

/**
 * A holiday: a date (one per organization) and its name.
 */
class SaveHolidayRequest extends ManageAttendanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $holiday = $this->route('holiday');

        return [
            'date' => [
                'required',
                'date_format:Y-m-d',
                Rule::unique('holidays', 'date')
                    ->where('organization_id', $this->organizationId())
                    ->ignore($holiday instanceof Holiday ? $holiday->id : null),
            ],
            'name' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['date.unique' => 'That date is already a holiday.'];
    }

    /**
     * @return array{date: string, name: string}
     */
    public function holiday(): array
    {
        return [
            'date' => $this->string('date')->value(),
            'name' => trim($this->string('name')->value()),
        ];
    }
}
