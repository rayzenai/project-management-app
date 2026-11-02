export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const d = new Date(value);

    return d.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function formatRelative(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    // Compare calendar days, not raw millisecond distance — "due today at
    // midnight" must read "today", never "yesterday". Date-only strings parse
    // as UTC midnight, so use UTC fields for them and local fields otherwise.
    const dateOnly = /^\d{4}-\d{2}-\d{2}$/.test(value);
    const target = new Date(value);
    const now = new Date();
    const targetDay = dateOnly
        ? Date.UTC(
              target.getUTCFullYear(),
              target.getUTCMonth(),
              target.getUTCDate(),
          )
        : Date.UTC(target.getFullYear(), target.getMonth(), target.getDate());
    const today = Date.UTC(now.getFullYear(), now.getMonth(), now.getDate());
    const diffDays = Math.round((targetDay - today) / 86_400_000);

    if (diffDays === 0) {
        return 'today';
    }

    if (diffDays === 1) {
        return 'tomorrow';
    }

    if (diffDays === -1) {
        return 'yesterday';
    }

    if (diffDays > 0) {
        return `in ${diffDays}d`;
    }

    return `${Math.abs(diffDays)}d overdue`;
}

/**
 * "just now", "5 minutes ago", "3 hours ago", "2 days ago", "3 weeks ago",
 * "2 months ago", "1 year ago"; empty for none.
 */
export function formatTimeAgo(
    value: string | null | undefined,
    now: Date = new Date(),
): string {
    if (!value) {
        return '';
    }

    const minutes = Math.floor(
        Math.max(0, now.getTime() - new Date(value).getTime()) / 60_000,
    );
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (minutes < 1) {
        return 'just now';
    }

    if (minutes < 60) {
        return `${plural(minutes, 'minute')} ago`;
    }

    if (hours < 24) {
        return `${plural(hours, 'hour')} ago`;
    }

    if (days < 7) {
        return `${plural(days, 'day')} ago`;
    }

    if (days < 35) {
        return `${plural(Math.floor(days / 7), 'week')} ago`;
    }

    if (days < 365) {
        return `${plural(Math.max(1, Math.floor(days / 30)), 'month')} ago`;
    }

    return `${plural(Math.floor(days / 365), 'year')} ago`;
}

export function initials(name: string | null | undefined): string {
    if (!name) {
        return '?';
    }

    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0).toUpperCase())
        .join('');
}

export function priorityColor(priority: string | null | undefined): string {
    switch (priority) {
        case 'urgent':
            return 'bg-danger-soft text-danger';
        case 'high':
            return 'bg-warn-soft text-warn';
        case 'medium':
            return 'bg-surface-alt text-fg-muted';
        default:
            return 'bg-surface-alt text-fg-faint';
    }
}

/** "40 m", "3.4 km", "12 km"; empty for none. */
export function formatDistance(metres: number | null): string {
    if (metres === null) {
        return '';
    }

    return metres < 1000
        ? `${Math.round(metres)} m`
        : `${(metres / 1000).toFixed(metres < 10_000 ? 1 : 0)} km`;
}

/** "1 commit", "3 commits", "2 people". */
export function plural(n: number, one: string, many = `${one}s`): string {
    return `${n.toLocaleString()} ${n === 1 ? one : many}`;
}
