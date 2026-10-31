<?php

namespace App\Http\Requests\Attendance\Concerns;

use App\Models\LeaveRequest;
use Illuminate\Validation\Rule;

/**
 * The form of leave or kaaj, asked for or recorded. The services hold the
 * rest of the rules (at most 90 days, no overlap).
 */
trait LeaveRules
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(LeaveRequest::KINDS)],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'max:500'],
            'place' => ['nullable', 'required_if:kind,'.LeaveRequest::KIND_KAAJ, 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ends_on.after_or_equal' => "The last day can't be before the first.",
            'reason.required' => 'Give a reason.',
            'place.required_if' => 'Say where the kaaj is.',
        ];
    }
}
