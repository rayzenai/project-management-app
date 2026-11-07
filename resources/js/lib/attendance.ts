import { clockTime } from './dates';
import { formatDistance } from './format';
import { digits, msg, t } from './i18n';

/**
 * Attendance: the clock (MyAttendanceQuery, shared as the `clock` prop) and
 * an organization's settings (AttendanceSettingsQuery).
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
// English keys (msg), drawn with t() where they show.
export const ACTION_LABELS: Record<PunchKind, string> = {
    in: msg('Clock in'),
    lunch_start: msg('Start lunch'),
    lunch_end: msg('End lunch'),
    out: msg('Clock out'),
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

        return punch ? digits(clockTime(punch.at)) : '';
    };

    // In the reader's language: the top bar's clock button draws it.
    switch (day.state) {
        case 'in':
            return t('In since :time', { time: since('in') });
        case 'at_lunch':
            return t('At lunch since :time', { time: since('lunch_start') });
        case 'out':
            return t('Out at :time', { time: since('out') });
        default:
            return t(ACTION_LABELS.in);
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
