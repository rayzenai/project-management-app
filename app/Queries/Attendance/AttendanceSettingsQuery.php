<?php

namespace App\Queries\Attendance;

use App\Http\Resources\Attendance\HolidayResource;
use App\Http\Resources\Attendance\NetworkResource;
use App\Http\Resources\Attendance\PlaceResource;
use App\Http\Resources\Attendance\SettingResource;
use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\AttendanceSetting;
use App\Models\Holiday;
use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An organization's attendance settings page: the switch, the hours, its
 * office places and networks, and its holidays from the start of this year.
 * An organization never turned on shows the defaults, off.
 */
final class AttendanceSettingsQuery
{
    /**
     * @return array{organization: array{id: int, name: string}, settings: array{enabled: bool, enabled_at: string|null, enabled_by: string|null, workdays: list<int>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int}, places: list<array{id: int, name: string, latitude: float, longitude: float, radius_m: int}>, networks: list<array{id: int, label: string, cidr: string}>, holidays: list<array{id: int, date: string, name: string}>}
     */
    public function get(Organization $organization): array
    {
        $setting = AttendanceSetting::forOrganization((int) $organization->id);

        if ($setting->exists) {
            $setting->load('enabledBy:id,name');
        }

        /** @var array{enabled: bool, enabled_at: string|null, enabled_by: string|null, workdays: list<int>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int} $settings */
        $settings = (new SettingResource($setting))->resolve();

        /** @var list<array{id: int, name: string, latitude: float, longitude: float, radius_m: int}> $places */
        $places = array_values(PlaceResource::collection(
            AttendancePlace::query()->where('organization_id', $organization->id)->orderBy('name')->get(),
        )->resolve());

        /** @var list<array{id: int, label: string, cidr: string}> $networks */
        $networks = array_values(NetworkResource::collection(
            AttendanceNetwork::query()->where('organization_id', $organization->id)->orderBy('label')->get(),
        )->resolve());

        /** @var list<array{id: int, date: string, name: string}> $holidays */
        $holidays = array_values(HolidayResource::collection(
            Holiday::query()
                ->where('organization_id', $organization->id)
                ->whereDate('date', '>=', CarbonImmutable::today()->startOfYear()->toDateString())
                ->orderBy('date')
                ->get(),
        )->resolve());

        return [
            'organization' => ['id' => (int) $organization->id, 'name' => $organization->name],
            'settings' => $settings,
            'places' => $places,
            'networks' => $networks,
            'holidays' => $holidays,
        ];
    }

    /**
     * The organization the settings page opens: the one asked for, else the
     * viewer's own, else (a super-admin, who belongs to none) the first by
     * name. Whether the viewer may open it is the request's check.
     */
    public function organizationFor(?Authenticatable $viewer, ?int $requested): ?Organization
    {
        if ($requested !== null) {
            return Organization::query()->find($requested);
        }

        $own = WorkspaceAccess::memberFor($viewer)?->organization;

        if ($own !== null || ! WorkspaceAccess::isSuperAdmin($viewer)) {
            return $own;
        }

        return Organization::query()->orderBy('name')->first();
    }

    /**
     * Every organization, by name, for a super-admin's switcher.
     *
     * @return list<array{id: int, name: string}>
     */
    public function organizations(): array
    {
        return array_values(Organization::query()->orderBy('name')->get(['id', 'name'])
            ->map(fn (Organization $organization): array => ['id' => (int) $organization->id, 'name' => $organization->name])
            ->all());
    }
}
