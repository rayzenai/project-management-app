<?php

namespace App\Http\Requests\Work;

use App\Http\Requests\Concerns\ReadsDayRange;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One person's work over a range of days (ReadsDayRange): for the person
 * themselves and whoever oversees them (WorkspaceAccess::canViewPersonOf).
 */
class PersonWorkRequest extends FormRequest
{
    use ReadsDayRange;

    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof Member && WorkspaceAccess::canViewPersonOf($this->user(), $member);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->rangeRules();
    }

    /**
     * The range as applied, for the page to show and send back.
     *
     * @return array{from: string, to: string}
     */
    public function appliedFilters(): array
    {
        [$from, $to] = $this->range();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
    }
}
