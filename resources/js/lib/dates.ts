/**
 * Days and times as the office reads them. Days travel as Y-m-d strings and
 * are read as local calendar days, never shifted by a timezone. Server
 * timestamps carry the office's offset ("2026-11-02T09:41:00+05:45"), and
 * their clock time and day are read from the string itself, so a browser in
 * another timezone still shows the office's clock.
 */

/** A range of whole days, Y-m-d, both ends included. */
export interface DayRange {
    from: string;
    to: string;
}

/** Y-m-d as a local calendar day. */
export function localDay(iso: string): Date {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(y, m - 1, d);
}

/** A local calendar day as Y-m-d. */
export function isoDay(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The day `days` before or after, Y-m-d. */
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

/** "Thu": the office's weekday of a server timestamp. */
export function weekday(timestamp: string): string {
    return localDay(timestamp.slice(0, 10)).toLocaleDateString('en-GB', {
        weekday: 'short',
    });
}

/** "9:41": the office's clock time of a server timestamp. */
export function clockTime(timestamp: string | null): string {
    const match = timestamp ? /T(\d{2}):(\d{2})/.exec(timestamp) : null;

    return match ? `${Number(match[1])}:${match[2]}` : '';
}

/** "22h 40m", "45m". */
export function hoursLabel(minutes: number): string {
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return h === 0 ? `${m}m` : `${h}h ${String(m).padStart(2, '0')}m`;
}

/** This week (Monday to today) and last week (Monday to Sunday). */
export function weekRanges(today: Date = new Date()): {
    this_week: DayRange;
    last_week: DayRange;
} {
    const monday = new Date(today);
    monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
    const lastMonday = new Date(monday);
    lastMonday.setDate(monday.getDate() - 7);
    const lastSunday = new Date(monday);
    lastSunday.setDate(monday.getDate() - 1);

    return {
        this_week: { from: isoDay(monday), to: isoDay(today) },
        last_week: { from: isoDay(lastMonday), to: isoDay(lastSunday) },
    };
}
