<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendancePunch;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A press of the clock, from the web or the app: the kind, and the
 * location the device reported at that moment (or none). The time is never
 * the client's.
 */
class PunchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canPunch($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(AttendancePunch::KINDS)],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * What the punch keeps besides its kind: the reported location, the
     * note, where the request came from and the surface it came through.
     *
     * @return array{latitude: float|null, longitude: float|null, accuracy_m: int|null, note: string|null, source: string, ip: string|null, user_agent: string|null, device_ref: null}
     */
    public function context(string $source): array
    {
        $number = fn (string $key): ?float => $this->filled($key) ? (float) $this->input($key) : null;
        $accuracy = $number('accuracy_m');

        return [
            'latitude' => $number('latitude'),
            'longitude' => $number('longitude'),
            'accuracy_m' => $accuracy === null ? null : (int) round($accuracy),
            'note' => $this->filled('note') ? (string) $this->input('note') : null,
            'source' => $source,
            'ip' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'device_ref' => null,
        ];
    }
}
