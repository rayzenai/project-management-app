<?php

namespace App\Support\Attendance;

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\AttendancePunch;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Where a punch came from, judged once at the press from what the request
 * carried: the office network when its address is in one of the
 * organization's network ranges; near the office when the reported location
 * is within an office place's radius; outside when there is a location but
 * it isn't near; unknown when there is neither. The nearest place and the
 * distance to it are kept whenever there is a location, the office network
 * included.
 */
final class PunchPlace
{
    private const EARTH_RADIUS_M = 6_371_000;

    /**
     * A location counts as near when it is within the place's radius,
     * allowing for the reading's stated accuracy up to one more radius: a
     * vague reading never stretches "near" beyond twice the radius.
     *
     * @return array{place: string, attendance_place_id: int|null, distance_m: int|null}
     */
    public static function judge(int $organizationId, ?string $ip, ?float $lat, ?float $lng, ?int $accuracyM): array
    {
        $hasLocation = $lat !== null && $lng !== null;
        $best = null;

        if ($hasLocation) {
            $places = AttendancePlace::query()
                ->where('organization_id', $organizationId)
                ->get(['id', 'latitude', 'longitude', 'radius_m']);

            // The nearest place in reach, else the nearest place.
            foreach ($places as $candidate) {
                $distance = self::distanceM($lat, $lng, $candidate->latitude, $candidate->longitude);
                $inReach = $distance - min(max($accuracyM ?? 0, 0), $candidate->radius_m) <= $candidate->radius_m;

                if ($best === null
                    || ($inReach && ! $best['in_reach'])
                    || ($inReach === $best['in_reach'] && $distance < $best['distance'])) {
                    $best = ['id' => (int) $candidate->id, 'distance' => $distance, 'in_reach' => $inReach];
                }
            }
        }

        $place = match (true) {
            self::onOfficeNetwork($organizationId, $ip) => AttendancePunch::PLACE_OFFICE_NETWORK,
            $best !== null && $best['in_reach'] => AttendancePunch::PLACE_NEAR_OFFICE,
            $hasLocation => AttendancePunch::PLACE_OUTSIDE,
            default => AttendancePunch::PLACE_UNKNOWN,
        };

        return [
            'place' => $place,
            'attendance_place_id' => $best['id'] ?? null,
            'distance_m' => $best === null ? null : (int) round($best['distance']),
        ];
    }

    /**
     * Whether the address is in one of the organization's network ranges.
     * An IPv4 address written as IPv6 (::ffff:10.0.0.1) is read as IPv4.
     */
    public static function onOfficeNetwork(int $organizationId, ?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        if (str_starts_with(strtolower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = substr($ip, 7);
        }

        $ranges = AttendanceNetwork::query()
            ->where('organization_id', $organizationId)
            ->pluck('cidr')
            ->map(fn (mixed $cidr): string => (string) $cidr)
            ->all();

        return $ranges !== [] && IpUtils::checkIp($ip, $ranges);
    }

    /**
     * The great-circle distance in metres (haversine).
     */
    public static function distanceM(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * asin(min(1.0, sqrt($a)));
    }
}
