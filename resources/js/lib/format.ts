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

export function formatTimeAgo(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const then = new Date(value).getTime();
    const secs = Math.max(0, Math.round((Date.now() - then) / 1000));

    if (secs < 60) {
        return 'just now';
    }

    const mins = Math.round(secs / 60);

    if (mins < 60) {
        return `${mins}m ago`;
    }

    const hrs = Math.round(mins / 60);

    if (hrs < 24) {
        return `${hrs}h ago`;
    }

    const days = Math.round(hrs / 24);

    if (days < 7) {
        return `${days}d ago`;
    }

    const weeks = Math.round(days / 7);

    if (weeks < 5) {
        return `${weeks}w ago`;
    }

    const months = Math.round(days / 30);

    if (months < 12) {
        return `${months}mo ago`;
    }

    return `${Math.round(days / 365)}y ago`;
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
