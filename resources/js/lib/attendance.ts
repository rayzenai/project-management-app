/**
 * Attendance: the clock (MyAttendanceQuery, shared as the `attendance`
 * prop) and an organization's settings (AttendanceSettingsQuery).
 */

export type PunchKind = 'in' | 'lunch_start' | 'lunch_end' | 'out';

/** Where a punch came from, judged by the server at the press. */
export type PunchPlace =
    'office_network' | 'near_office' | 'outside' | 'unknown';

export type DayState = 'not_in' | 'in' | 'at_lunch' | 'out';

export interface DaySchedule {
    date: string;
    is_workday: boolean;
    holiday: string | null;
    expects_work: boolean;
    start: string;
    end: string;
    late_after: string;
}

export interface Punch {
    id: number;
    kind: PunchKind;
    /** ISO 8601 with the server's offset. */
    at: string;
    work_date: string;
    source: 'web' | 'app' | 'device';
    place: PunchPlace;
    /** The nearest office place, when there was a location. */
    place_name: string | null;
    distance_m: number | null;
    note: string | null;
}

export interface MyAttendance {
    today: DaySchedule;
    punches: Punch[];
    next: PunchKind[];
    state: DayState;
}

export interface Season {
    name: string;
    /** MM-DD */
    from: string;
    /** MM-DD */
    to: string;
    /** HH:MM */
    start: string;
    /** HH:MM */
    end: string;
}

export interface AttendanceSettings {
    enabled: boolean;
    enabled_at: string | null;
    enabled_by: string | null;
    workdays: number[];
    start_time: string;
    end_time: string;
    seasons: Season[];
    grace_minutes: number;
}

export interface OfficePlace {
    id: number;
    name: string;
    latitude: number;
    longitude: number;
    radius_m: number;
}

export interface OfficeNetwork {
    id: number;
    label: string;
    cidr: string;
}

export interface Holiday {
    id: number;
    date: string;
    name: string;
}

/** The button for each kind of punch. */
export const ACTION_LABELS: Record<PunchKind, string> = {
    in: 'Clock in',
    lunch_start: 'Start lunch',
    lunch_end: 'End lunch',
    out: 'Clock out',
};

const DONE_LABELS: Record<PunchKind, string> = {
    in: 'Clocked in',
    lunch_start: 'Started lunch',
    lunch_end: 'Ended lunch',
    out: 'Clocked out',
};

const PLACE_PHRASES: Record<PunchPlace, string> = {
    office_network: 'on the office network',
    near_office: 'near the office',
    outside: 'outside the office',
    unknown: 'from an unknown place',
};

/**
 * "9:52": the server's local time read straight from the ISO string, so a
 * browser in another timezone shows the office's clock.
 */
export function clockTime(iso: string): string {
    const match = /T(\d{2}):(\d{2})/.exec(iso);

    return match ? `${Number(match[1])}:${match[2]}` : '';
}

/** "40 m" or "3.4 km". */
export function formatDistance(metres: number): string {
    return metres < 1000
        ? `${Math.round(metres)} m`
        : `${(metres / 1000).toFixed(1)} km`;
}

/** "Clocked in at 9:52 on the office network, 40 m from SDOC." */
export function describePunch(punch: Punch): string {
    const distance =
        punch.distance_m !== null && punch.place_name
            ? `, ${formatDistance(punch.distance_m)} from ${punch.place_name}`
            : '';

    return `${DONE_LABELS[punch.kind]} at ${clockTime(punch.at)} ${PLACE_PHRASES[punch.place]}${distance}.`;
}

/** The top bar's label for where the day stands. */
export function stateLabel(day: MyAttendance): string {
    const since = (kind: PunchKind): string => {
        const punch = day.punches.find((p) => p.kind === kind);

        return punch ? clockTime(punch.at) : '';
    };

    switch (day.state) {
        case 'in':
            return `In since ${since('in')}`;
        case 'at_lunch':
            return `At lunch since ${since('lunch_start')}`;
        case 'out':
            return `Out at ${since('out')}`;
        default:
            return ACTION_LABELS.in;
    }
}

/** ISO weekdays, Monday first, for the settings page. */
export const WEEKDAYS: { value: number; short: string }[] = [
    { value: 1, short: 'Mon' },
    { value: 2, short: 'Tue' },
    { value: 3, short: 'Wed' },
    { value: 4, short: 'Thu' },
    { value: 5, short: 'Fri' },
    { value: 6, short: 'Sat' },
    { value: 7, short: 'Sun' },
];
