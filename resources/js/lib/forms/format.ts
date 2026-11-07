/** "8 Nov 2026, 5:00 pm" by the office's clock (Nepal), whatever the browser's timezone. */
export function when(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Kathmandu',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(new Date(iso));
}

/** "8 Nov 2026" by the office's calendar. */
export function day(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Kathmandu',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(iso));
}

export const PHASES: Record<string, string> = {
    draft: 'Draft',
    scheduled: 'Scheduled',
    open: 'Open',
    closed: 'Closed',
};
