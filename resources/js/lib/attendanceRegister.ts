/**
 * The attendance register, leave and kaaj: the shapes the server sends
 * (AttendanceRegisterQuery, AttendanceSummaryQuery, LeaveQuery) and the
 * small helpers the pages share.
 */

export type RegisterState =
    'in' | 'at_lunch' | 'out' | 'not_in' | 'on_leave' | 'on_kaaj' | 'off';

export type PunchPlace =
    'office_network' | 'near_office' | 'outside' | 'unknown';

export type LeaveKind = 'leave' | 'kaaj';

export type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export interface RegisterPerson {
    id: number;
    name: string;
    designation: string | null;
}

export interface DaySchedule {
    date: string;
    is_workday: boolean;
    holiday: string | null;
    expects_work: boolean;
    start: string;
    end: string;
    late_after: string;
}

export interface RegisterSummary {
    staff: number;
    on_leave: number;
    on_kaaj: number;
    expected: number;
    present: number;
    late: number;
    not_in: number;
    outside_office: number;
    percentage: number | null;
}

export interface RegisterRow {
    member: RegisterPerson;
    state: RegisterState;
    in_at: string | null;
    lunch_start_at: string | null;
    lunch_end_at: string | null;
    out_at: string | null;
    late: boolean;
    place: PunchPlace | null;
    distance_m: number | null;
    note: string | null;
    leave: {
        kind: LeaveKind;
        place: string | null;
        starts_on: string;
        ends_on: string;
    } | null;
}

export interface RegisterFilters {
    date: string;
    under: number | null;
    organization: number;
}

export interface AttendanceSummaryRow {
    member: RegisterPerson;
    days_expected: number;
    days_present: number;
    days_late: number;
    minutes_worked: number;
    no_clock_out_days: number;
    outside_punches: number;
    leave_days: number;
    kaaj_days: number;
    usual_in: string | null;
}

export interface LeaveItem {
    id: number;
    kind: LeaveKind;
    starts_on: string;
    ends_on: string;
    days: number;
    reason: string;
    place: string | null;
    status: LeaveStatus;
    can_cancel: boolean;
    member?: RegisterPerson | null;
    decided_by?: { id: number; name: string } | null;
    decided_at: string | null;
    decision_note: string | null;
    created_at: string | null;
}

export interface RecordablePerson extends RegisterPerson {
    organization: string;
}

/**
 * The office's clock time of a server timestamp ("2026-11-02T09:41:00+05:45"
 * reads 9:41), whatever the browser's timezone.
 */
export function clockTime(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const [hours, minutes] = iso.slice(11, 16).split(':');

    return `${Number(hours)}:${minutes}`;
}

/** 30 m, 3.4 km. */
export function distance(metres: number | null): string {
    if (metres === null) {
        return '';
    }

    return metres < 1000
        ? `${metres} m`
        : `${(metres / 1000).toFixed(metres < 10_000 ? 1 : 0)} km`;
}

/** A Y-m-d day as the browser's local date, never shifted by UTC. */
export function localDay(iso: string): Date {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(y, m - 1, d);
}

export function isoDay(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The day before or after, Y-m-d. */
export function shiftDay(iso: string, days: number): string {
    const date = localDay(iso);
    date.setDate(date.getDate() + days);

    return isoDay(date);
}

/** "Monday 2 November". */
export function longDay(iso: string): string {
    return localDay(iso).toLocaleDateString('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

/** "2 Nov", "2 to 4 Nov", "30 Oct to 2 Nov". */
export function dayRange(from: string, to: string): string {
    const short = (iso: string, month: boolean) =>
        localDay(iso).toLocaleDateString('en-GB', {
            day: 'numeric',
            ...(month ? { month: 'short' } : {}),
        });

    if (from === to) {
        return short(from, true);
    }

    const sameMonth = from.slice(0, 7) === to.slice(0, 7);

    return `${short(from, !sameMonth)} to ${short(to, true)}`;
}

export const LEAVE_KIND_LABEL: Record<LeaveKind, string> = {
    leave: 'Leave',
    kaaj: 'Kaaj',
};

export const LEAVE_STATUS_LABEL: Record<LeaveStatus, string> = {
    pending: 'Waiting',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};
