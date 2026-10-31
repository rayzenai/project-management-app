import { clockTime } from './dates';
import type { DayRange } from './dates';
import type { Id, PersonRef, ReportOptions } from './types';

/**
 * The work reports' shapes (App\Queries\Work) and their small helpers:
 * today's work (Work/Day), a team's week (Work/Team), one person's week
 * (Work/Person). The API twins answer the same shapes under `data`.
 */

/** A task someone finished or started, at its latest change in the range. */
export interface WorkTask {
    id: Id;
    /** KEY-12 */
    code: string;
    title: string;
    /** The project's label: "General (Revenue)". */
    project: string;
    project_slug: string;
    slug: string;
    /** The status the task entered (done or failed for a closed one). */
    status: string;
    /** When it entered it, ISO-8601. */
    at: string;
}

/** A task assigned to the person that is overdue now. */
export interface WorkOverdueTask {
    id: Id;
    code: string;
    title: string;
    project: string;
    project_slug: string;
    slug: string;
    status: string;
    deadline_at: string;
}

/** Counted commits: not merges, still reachable. */
export interface WorkCode {
    count: number;
    code_added: number;
    code_removed: number;
    comment_added: number;
    comment_removed: number;
    /** Only repositories whose code the viewer may see, most commits first. */
    repositories: { name: string; count: number }[];
    /** Commits in repositories the viewer may not see: counted, never named. */
    hidden_commits: number;
}

export interface WorkPullRequest {
    title: string;
    number: number;
    repository: string;
    url: string;
    merged_at: string;
}

/**
 * One person's attendance on a day, from AttendanceRegisterQuery. Null when
 * the organization's attendance is off.
 */
export interface WorkAttendanceDay {
    /** The register's state: in, at_lunch, out, not_in, on_leave, on_kaaj or off. */
    state: string;
    /** Clock-in time, ISO-8601, or null when not in. */
    in_at: string | null;
    late: boolean;
    /** Where the clock-in came from: office_network, near_office, outside, unknown. */
    place: string | null;
}

/**
 * One person's attendance over a range, from AttendanceSummaryQuery. Null
 * when the organization's attendance is off.
 */
export interface WorkAttendanceSummary {
    days_expected: number;
    days_present: number;
    days_late: number;
    minutes_worked: number;
    no_clock_out_days: number;
    outside_punches: number;
    leave_days: number;
    kaaj_days: number;
    /** Their usual arrival, HH:MM, or null. */
    usual_in: string | null;
}

// ---- Today's work ----

export interface WorkDayRow {
    member: PersonRef;
    attendance: WorkAttendanceDay | null;
    closed: WorkTask[];
    hidden_closed: number;
    started: WorkTask[];
    hidden_started: number;
    commits: WorkCode;
    pull_requests_merged: WorkPullRequest[];
    hidden_pull_requests: number;
    /** No task closed or started, no commit, no pull request merged. */
    nothing_recorded: boolean;
}

export interface WorkDaySummary {
    people: number;
    tasks_closed: number;
    tasks_started: number;
    commits: number;
    committers: number;
    pull_requests_merged: number;
    nothing_recorded: number;
}

export interface WorkDayFilters {
    organization: Id;
    date: string;
    under: Id | null;
}

export interface WorkDayProps {
    date: string;
    organization_id: Id;
    under: { id: Id; name: string } | null;
    summary: WorkDaySummary;
    people: WorkDayRow[];
    options: ReportOptions;
    filters: WorkDayFilters;
}

// ---- A team's week ----

export interface WorkTeamRow {
    member: PersonRef;
    attendance: WorkAttendanceSummary | null;
    tasks_closed: number;
    tasks_started: number;
    /** Overdue now, whatever the range. */
    overdue: number;
    commits: number;
    code_added: number;
    code_removed: number;
    pull_requests_merged: number;
}

export type WorkRangeFilters = DayRange;

export interface WorkTeamFilters extends WorkRangeFilters {
    organization: Id;
    under: Id | null;
}

export interface WorkTeamProps {
    range: WorkRangeFilters;
    organization_id: Id;
    under: { id: Id; name: string } | null;
    people: WorkTeamRow[];
    options: ReportOptions;
    filters: WorkTeamFilters;
}

// ---- One person's week ----

export interface WorkPersonProps {
    member: PersonRef & {
        organization_id: Id | null;
        reports_to: { id: Id; name: string } | null;
    };
    range: WorkRangeFilters;
    attendance: WorkAttendanceSummary | null;
    tasks: {
        closed: WorkTask[];
        started: WorkTask[];
        overdue: WorkOverdueTask[];
        counts: {
            closed: number;
            started: number;
            overdue: number;
            hidden_closed: number;
            hidden_started: number;
            hidden_overdue: number;
        };
    };
    code: WorkCode;
    pull_requests_merged: WorkPullRequest[];
    hidden_pull_requests: number;
    filters: WorkRangeFilters;
}

// ---- Helpers ----

export function taskHref(task: { project_slug: string; slug: string }): string {
    return `/workspace/projects/${task.project_slug}/tasks/${task.slug}`;
}

export function personWorkHref(id: Id): string {
    return `/workspace/people/${id}/work`;
}

/** A day's attendance in a few words, and whether it reads as a warning. */
export function attendanceDayLabel(day: WorkAttendanceDay): {
    text: string;
    warn: boolean;
} {
    if (day.in_at) {
        const parts = [`In ${clockTime(day.in_at)}`];

        if (day.late) {
            parts.push('late');
        }

        if (day.place === 'outside') {
            parts.push('outside office');
        }

        if (day.place === 'unknown') {
            parts.push('no location');
        }

        return {
            text: parts.join(', '),
            warn:
                day.late || day.place === 'outside' || day.place === 'unknown',
        };
    }

    if (day.state === 'on_leave') {
        return { text: 'On leave', warn: false };
    }

    if (day.state === 'on_kaaj') {
        return { text: 'On kaaj', warn: false };
    }

    if (day.state === 'off') {
        return { text: 'Day off', warn: false };
    }

    return { text: 'Not in', warn: false };
}
