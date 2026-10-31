<?php

namespace App\Http\Requests\Attendance;

use Closure;

/**
 * An office network range: a label and an IPv4 or IPv6 range (CIDR) or a
 * single address. A range wider than /8 (IPv4) or /16 (IPv6) is refused:
 * it would make most of the internet "the office".
 */
class SaveAttendanceNetworkRequest extends ManageAttendanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'cidr' => ['required', 'string', 'max:49', function (string $attribute, mixed $value, Closure $fail): void {
                $error = self::rangeError(trim((string) $value));

                if ($error !== null) {
                    $fail($error);
                }
            }],
        ];
    }

    /**
     * @return array{label: string, cidr: string}
     */
    public function network(): array
    {
        return [
            'label' => trim($this->string('label')->value()),
            'cidr' => strtolower(trim($this->string('cidr')->value())),
        ];
    }

    private static function rangeError(string $value): ?string
    {
        $invalid = 'Enter an address like 203.0.113.7 or a range like 203.0.113.0/24.';
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);

        $v4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $v6 = ! $v4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        if (! $v4 && ! $v6) {
            return $invalid;
        }

        if ($prefix === null) {
            return null;
        }

        if (preg_match('/^\d{1,3}$/', $prefix) !== 1 || (int) $prefix > ($v4 ? 32 : 128)) {
            return $invalid;
        }

        return (int) $prefix < ($v4 ? 8 : 16) ? 'That range is too wide for an office network.' : null;
    }
}
