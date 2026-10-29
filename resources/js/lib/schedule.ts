import { taskCode as codeFor } from './taskCode';
import type { ProgressTask } from './types';

/**
 * Finish-to-start scheduling for the team Gantt. Tasks carry no planned start,
 * so a task still waiting for unfinished work is drawn from when that work is
 * expected to finish, and lateness ripples down a chain: if A is late, B (which
 * waits for A) can only start after today, and C after B.
 */

export const DAY = 86_400_000;

/** Local midnight of a YYYY-MM-DD (or ISO) date. */
export function dayOf(iso: string): number {
    const [y, m, d] = iso.slice(0, 10).split('-').map(Number);

    return new Date(y, m - 1, d).getTime();
}

export function daysBetween(from: number, to: number): number {
    return Math.round((to - from) / DAY);
}

export function startOfToday(): number {
    const now = new Date();

    return new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
}

export interface TaskPlan {
    opened: number;
    /** Where its bar starts: when opened, or after the work it waits for. */
    start: number;
    /** Where its bar ends: finished, or its deadline while open. */
    end: number;
    /** When it can be done at the earliest, given today and what it waits for. */
    finish: number;
    deadline: number | null;
    completed: number | null;
    /** Unfinished tasks it waits for. */
    waitingOn: number[];
    /** Those of them that push it past its own deadline. */
    blockedBy: number[];
    /** True when its start was moved to after the work it waits for. */
    shifted: boolean;
}

export function planTasks(
    tasks: ProgressTask[],
    today: number,
): Record<number, TaskPlan> {
    const index: Record<number, ProgressTask> = {};

    for (const task of tasks) {
        index[task.id] = task;
    }

    const plans: Record<number, TaskPlan> = {};
    const visiting: Record<number, boolean> = {};

    function plan(task: ProgressTask): TaskPlan {
        const known = plans[task.id];

        if (known) {
            return known;
        }

        const opened = task.start ? dayOf(task.start) : today;
        const deadline = task.deadline_at ? dayOf(task.deadline_at) : null;
        const completed = task.completed_at ? dayOf(task.completed_at) : null;
        const finishes: Record<number, number> = {};
        const waitingOn: number[] = [];
        let ready: number | null = null;

        visiting[task.id] = true;

        for (const id of task.depends_on) {
            const before = index[id];

            // Outside this chart, or a loop the server should never allow.
            if (!before || visiting[id]) {
                continue;
            }

            const finish = plan(before).finish;
            finishes[id] = finish;
            ready =
                ready === null ? finish + DAY : Math.max(ready, finish + DAY);

            if (!before.is_complete) {
                waitingOn.push(id);
            }
        }

        visiting[task.id] = false;

        const waiting = !task.is_complete && waitingOn.length > 0;
        // Work already under way keeps its own start.
        const shifted =
            waiting && task.progress === 0 && ready !== null && ready > opened;
        const start = shifted && ready !== null ? ready : opened;

        let end: number;
        let finish: number;

        if (task.is_complete) {
            end = completed ?? deadline ?? start;
            finish = end;
        } else {
            end = Math.max(deadline ?? Math.max(today, start), start);
            // Open work ends today at the earliest, and never before the work
            // it waits for: this is how one late task delays the next.
            finish = Math.max(end, today, ready ?? end);
        }

        const result: TaskPlan = {
            opened,
            start,
            end,
            finish,
            deadline,
            completed,
            waitingOn,
            blockedBy: waiting
                ? waitingOn.filter(
                      (id) =>
                          deadline !== null && finishes[id] + DAY > deadline,
                  )
                : [],
            shifted,
        };

        plans[task.id] = result;

        return result;
    }

    for (const task of tasks) {
        plan(task);
    }

    return plans;
}

/** Task code as the app writes it: PROJECT-ITEM, or the project code alone. */
export function taskCode(task: {
    number: number;
    project: { code: string };
}): string {
    return codeFor(task.project.code, task.number);
}
