<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import AppShell from '../../components/AppShell.svelte';
    import Avatar from '../../components/Avatar.svelte';
    import ProgressRing from '../../components/ProgressRing.svelte';
    import { formatTimeAgo } from '../../lib/format';
    import { peek } from '../../lib/peek.svelte';
    import type { Status } from '../../lib/types';

    interface ProgressMember {
        id: number;
        name: string;
        title: string | null;
        organization: string | null;
        role: 'leader' | 'member';
        is_active: boolean;
        assigned: number;
        open: number;
        done: number;
        overdue: number;
        due_this_week: number;
        progress: number | null;
        last_active_at: string | null;
    }

    interface ProgressTask {
        id: number;
        slug: string;
        title: string;
        item_number: number | null;
        project: { slug: string; title: string; code: string };
        status: string;
        priority: string;
        progress: number;
        is_complete: boolean;
        is_late: boolean;
        start: string | null;
        end: string | null;
        deadline_at: string | null;
        completed_at: string | null;
        assignees: { id: number; name: string; in_team: boolean }[];
    }

    interface ActivityRow {
        id: number;
        description: string;
        user_name: string | null;
        task_title: string | null;
        task_slug: string | null;
        project_slug: string | null;
        happened_at: string | null;
    }

    let {
        progress,
    }: {
        progress: {
            team: {
                id: number;
                name: string;
                description: string | null;
                organization: string | null;
                is_cross_organization: boolean;
            };
            members: ProgressMember[];
            tasks: ProgressTask[];
            truncated: boolean;
            activity: ActivityRow[];
            generated_at: string;
        };
    } = $props();

    const REFRESH_MS = 15_000;
    const DAY = 86_400_000;

    // Live: re-request only this page's props on a timer, and only while the
    // tab is visible, so a page left open on a wall screen stays current.
    $effect(() => {
        const id = setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['progress'] });
            }
        }, REFRESH_MS);

        return () => clearInterval(id);
    });

    const statuses = $derived(((page.props as any).statuses ?? []) as Status[]);

    function statusLabel(value: string): string {
        return statuses.find((s) => s.value === value)?.label ?? value;
    }

    const updatedAt = $derived(
        new Date(progress.generated_at).toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        }),
    );

    // ---- Filters ----
    let memberFilter = $state<number | null>(null);
    let hideDone = $state(false);

    function toggleMember(id: number) {
        memberFilter = memberFilter === id ? null : id;
    }

    const filterName = $derived(
        progress.members.find((m) => m.id === memberFilter)?.name ?? null,
    );

    const visibleTasks = $derived(
        progress.tasks.filter(
            (t) =>
                (!hideDone || !t.is_complete) &&
                (memberFilter === null ||
                    t.assignees.some((a) => a.id === memberFilter)),
        ),
    );

    // ---- Summary ----
    const now = new Date();
    const today = new Date(
        now.getFullYear(),
        now.getMonth(),
        now.getDate(),
    ).getTime();

    function dayOf(iso: string): number {
        const [y, m, d] = iso.slice(0, 10).split('-').map(Number);

        return new Date(y, m - 1, d).getTime();
    }

    const summary = $derived.by(() => {
        const open = progress.tasks.filter((t) => !t.is_complete);

        return {
            open: open.length,
            overdue: open.filter((t) => t.is_late).length,
            dueThisWeek: open.filter((t) => {
                if (!t.end) {
                    return false;
                }

                const end = dayOf(t.end);

                return end >= today && end <= today + 7 * DAY;
            }).length,
            done: progress.tasks.length - open.length,
        };
    });

    // ---- Gantt ----
    // A bar runs from when the task was created to when it was finished, or
    // to its deadline while open. Open tasks without a deadline run to today
    // with a dashed edge.
    const bars = $derived(
        visibleTasks.map((task) => {
            const start = task.start ? dayOf(task.start) : today;
            const end = task.end ? dayOf(task.end) : null;

            return {
                task,
                from: Math.min(start, end ?? start),
                to:
                    end === null
                        ? Math.max(today, start)
                        : Math.max(start, end),
                openEnded: end === null && !task.is_complete,
            };
        }),
    );

    const range = $derived.by(() => {
        if (bars.length === 0) {
            return null;
        }

        const min = Math.min(today, ...bars.map((b) => b.from)) - 3 * DAY;
        const max = Math.max(today, ...bars.map((b) => b.to)) + 7 * DAY;

        return { min, max, span: max - min + DAY };
    });

    function pct(time: number): number {
        return range ? ((time - range.min) / range.span) * 100 : 0;
    }

    const ticks = $derived.by(() => {
        if (!range) {
            return [] as { at: number; label: string }[];
        }

        const weekly = range.span <= 100 * DAY;
        const format = new Intl.DateTimeFormat(
            undefined,
            weekly
                ? { day: 'numeric', month: 'short' }
                : { month: 'short', year: 'numeric' },
        );
        const out: { at: number; label: string }[] = [];
        const first = new Date(range.min);
        const year = first.getFullYear();
        const month = first.getMonth();
        // Weekly ticks on Mondays, otherwise on the first of each month.
        const firstMonday = first.getDate() + ((8 - first.getDay()) % 7);

        for (let i = 0; ; i++) {
            const tick = weekly
                ? new Date(year, month, firstMonday + i * 7)
                : new Date(year, month + 1 + i, 1);

            if (tick.getTime() > range.max) {
                break;
            }

            out.push({ at: pct(tick.getTime()), label: format.format(tick) });
        }

        return out;
    });

    // Rows grouped under their project, in the order the server sent them.
    const groups = $derived(
        [...new Set(bars.map((b) => b.task.project.slug))].map((slug) => {
            const rows = bars.filter((b) => b.task.project.slug === slug);

            return { project: rows[0].task.project, rows };
        }),
    );

    function tone(task: ProgressTask): { base: string; fill: string } {
        if (task.is_complete) {
            return { base: 'bg-success-soft', fill: 'bg-success' };
        }

        if (task.is_late) {
            return { base: 'bg-danger-soft', fill: 'bg-danger' };
        }

        if (task.status === 'in_progress') {
            return { base: 'bg-accent-soft', fill: 'bg-accent' };
        }

        return {
            base: 'bg-surface-alt border border-line',
            fill: 'bg-fg-faint',
        };
    }

    const dateFormat = new Intl.DateTimeFormat(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

    function describe(task: ProgressTask): string {
        const lines = [
            task.title,
            `${statusLabel(task.status)} · ${task.progress}%`,
        ];

        if (task.start) {
            lines.push(`Opened ${dateFormat.format(dayOf(task.start))}`);
        }

        if (task.completed_at) {
            lines.push(
                `Finished ${dateFormat.format(dayOf(task.completed_at))}`,
            );
        } else if (task.deadline_at) {
            lines.push(`Due ${dateFormat.format(dayOf(task.deadline_at))}`);
        } else {
            lines.push('No deadline');
        }

        if (task.assignees.length > 0) {
            lines.push(task.assignees.map((a) => a.name).join(', '));
        }

        return lines.join('\n');
    }

    function taskCode(task: ProgressTask): string {
        return task.item_number !== null
            ? `${task.project.code}-${task.item_number}`
            : task.project.code;
    }
</script>

<svelte:head
    ><title>{progress.team.name} progress · Workspace</title></svelte:head
>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a
                href="/workspace/team"
                use:inertia
                class="text-fg-muted hover:text-fg">Team</a
            >
            <span class="text-fg-faint">/</span>
            <span class="truncate font-medium">{progress.team.name}</span>
            {#if progress.team.is_cross_organization}
                <span class="chip">Cross-organization</span>
            {/if}
        </div>
        <span class="shrink-0 text-xs text-fg-muted"
            >Live · updated <span class="font-mono tabular-nums"
                >{updatedAt}</span
            ></span
        >
    {/snippet}

    <div class="space-y-8">
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            {#each [{ label: 'Open tasks', value: summary.open, tone: 'text-fg' }, { label: 'Overdue', value: summary.overdue, tone: summary.overdue > 0 ? 'text-danger' : 'text-fg' }, { label: 'Due in 7 days', value: summary.dueThisWeek, tone: summary.dueThisWeek > 0 ? 'text-warn' : 'text-fg' }, { label: 'Done', value: summary.done, tone: 'text-fg' }] as stat (stat.label)}
                <div class="panel px-3 py-2.5">
                    <div class="text-xs text-fg-muted">{stat.label}</div>
                    <div class={`font-mono text-xl tabular-nums ${stat.tone}`}>
                        {stat.value}
                    </div>
                </div>
            {/each}
        </section>

        <!-- People -->
        <section>
            <h2 class="section-title mb-2">
                People
                <span class="section-count">{progress.members.length}</span>
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] text-[13px]">
                    <thead>
                        <tr class="border-b border-line text-left">
                            <th class="col-head py-1.5 pr-3 font-normal"
                                >Person</th
                            >
                            <th class="col-head px-3 text-right font-normal"
                                >Open</th
                            >
                            <th class="col-head px-3 text-right font-normal"
                                >Due in 7 days</th
                            >
                            <th class="col-head px-3 text-right font-normal"
                                >Overdue</th
                            >
                            <th class="col-head px-3 text-right font-normal"
                                >Done</th
                            >
                            <th class="col-head px-3 font-normal">Progress</th>
                            <th class="col-head pl-3 text-right font-normal"
                                >Last active</th
                            >
                        </tr>
                    </thead>
                    <tbody>
                        {#each progress.members as member (member.id)}
                            <tr
                                class={`cursor-pointer border-b border-line-soft hover:bg-hover ${memberFilter === member.id ? 'bg-hover' : ''} ${member.is_active ? '' : 'opacity-60'}`}
                                onclick={() => toggleMember(member.id)}
                                aria-selected={memberFilter === member.id}
                            >
                                <td class="py-1.5 pr-3">
                                    <div class="flex items-center gap-2">
                                        <Avatar name={member.name} size="md" />
                                        <div class="min-w-0">
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <span
                                                    class="truncate font-medium"
                                                    >{member.name}</span
                                                >
                                                {#if member.role === 'leader'}
                                                    <span
                                                        class="chip chip-accent"
                                                        >Leader</span
                                                    >
                                                {/if}
                                            </div>
                                            <div
                                                class="truncate text-xs text-fg-muted"
                                            >
                                                {[
                                                    member.title,
                                                    progress.team
                                                        .is_cross_organization
                                                        ? member.organization
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td
                                    class="px-3 text-right font-mono tabular-nums"
                                    >{member.open}</td
                                >
                                <td
                                    class={`px-3 text-right font-mono tabular-nums ${member.due_this_week > 0 ? 'text-warn' : 'text-fg-muted'}`}
                                    >{member.due_this_week}</td
                                >
                                <td
                                    class={`px-3 text-right font-mono tabular-nums ${member.overdue > 0 ? 'text-danger' : 'text-fg-muted'}`}
                                    >{member.overdue}</td
                                >
                                <td
                                    class="px-3 text-right font-mono text-fg-muted tabular-nums"
                                    >{member.done}</td
                                >
                                <td class="px-3">
                                    {#if member.progress === null}
                                        <span class="text-xs text-fg-faint"
                                            >No tasks</span
                                        >
                                    {:else}
                                        <span
                                            class="inline-flex items-center gap-1.5"
                                        >
                                            <ProgressRing
                                                percent={member.progress}
                                            />
                                            <span
                                                class="font-mono text-xs tabular-nums"
                                                >{member.progress}%</span
                                            >
                                        </span>
                                    {/if}
                                </td>
                                <td
                                    class="pl-3 text-right text-xs text-fg-muted"
                                    >{member.last_active_at
                                        ? formatTimeAgo(member.last_active_at)
                                        : 'No activity'}</td
                                >
                            </tr>
                        {/each}
                    </tbody>
                </table>
            </div>
            <p class="mt-1.5 text-xs text-fg-muted">
                Progress averages each person's own progress on their tasks,
                counting finished tasks as 100%. Click a person to see only
                their tasks below.
            </p>
        </section>

        <!-- Gantt -->
        <section>
            <div class="mb-2 flex flex-wrap items-center gap-3">
                <h2 class="section-title">
                    Timeline
                    <span class="section-count">{visibleTasks.length}</span>
                </h2>
                {#if filterName}
                    <button
                        type="button"
                        class="chip"
                        onclick={() => (memberFilter = null)}
                        title="Show everyone's tasks"
                        >{filterName} · show all</button
                    >
                {/if}
                <label
                    class="ml-auto flex items-center gap-2 text-[13px] text-fg-muted"
                >
                    <input type="checkbox" bind:checked={hideDone} />
                    Hide finished
                </label>
            </div>

            {#if !range}
                <p class="border-t border-line py-3 text-xs text-fg-muted">
                    {progress.tasks.length === 0
                        ? 'No tasks yet on the projects this team works on.'
                        : 'Nothing matches the filter.'}
                </p>
            {:else}
                <div class="overflow-x-auto border-t border-line">
                    <div class="relative min-w-[760px]">
                        <!-- Gridlines and today, behind the rows -->
                        <div
                            class="pointer-events-none absolute inset-y-0 right-0 left-[280px]"
                            aria-hidden="true"
                        >
                            {#each ticks as tick (tick.at)}
                                <div
                                    class="absolute inset-y-0 w-px bg-line-soft"
                                    style:left={`${tick.at}%`}
                                ></div>
                            {/each}
                            <div
                                class="absolute inset-y-0 w-px bg-accent"
                                style:left={`${pct(today)}%`}
                            ></div>
                        </div>

                        <div
                            class="grid h-8 grid-cols-[280px_1fr] border-b border-line"
                        >
                            <div class="col-head self-center">Task</div>
                            <div class="relative">
                                {#each ticks as tick (tick.at)}
                                    <span
                                        class="absolute top-1/2 -translate-y-1/2 pl-1 font-mono text-[11px] whitespace-nowrap text-fg-faint"
                                        style:left={`${tick.at}%`}
                                        >{tick.label}</span
                                    >
                                {/each}
                            </div>
                        </div>

                        {#each groups as group (group.project.slug)}
                            <div
                                class="group-head grid grid-cols-[280px_1fr] py-1"
                            >
                                <a
                                    href={`/workspace/projects/${group.project.slug}`}
                                    use:inertia
                                    class="truncate text-xs font-medium text-fg-muted hover:text-fg"
                                    >{group.project.title}</a
                                >
                            </div>
                            {#each group.rows as row (row.task.id)}
                                {@const colors = tone(row.task)}
                                <div
                                    class="grid h-8 grid-cols-[280px_1fr] items-center border-b border-line-soft hover:bg-hover"
                                >
                                    <button
                                        type="button"
                                        class="flex min-w-0 items-center gap-2 pr-3 text-left"
                                        onclick={() =>
                                            peek.open({
                                                id: row.task.id,
                                                slug: row.task.slug,
                                            })}
                                    >
                                        <span
                                            class="shrink-0 font-mono text-[11px] text-fg-faint tabular-nums"
                                            >{taskCode(row.task)}</span
                                        >
                                        <span class="min-w-0 truncate text-fg"
                                            >{row.task.title}</span
                                        >
                                        <span
                                            class="ml-auto flex shrink-0 items-center gap-0.5"
                                        >
                                            {#each row.task.assignees.slice(0, 2) as a (a.id)}
                                                <Avatar
                                                    name={a.name}
                                                    size="sm"
                                                />
                                            {/each}
                                            {#if row.task.assignees.length > 2}
                                                <span
                                                    class="font-mono text-[11px] text-fg-faint"
                                                    >+{row.task.assignees
                                                        .length - 2}</span
                                                >
                                            {/if}
                                        </span>
                                    </button>
                                    <div class="relative h-full">
                                        <button
                                            type="button"
                                            class={`absolute top-1/2 h-4 -translate-y-1/2 overflow-hidden rounded-sm ${colors.base} ${row.openEnded ? 'border border-dashed border-line' : ''}`}
                                            style:left={`${pct(row.from)}%`}
                                            style:width={`${Math.max(((row.to - row.from + DAY) / (range?.span ?? 1)) * 100, 0.8)}%`}
                                            title={describe(row.task)}
                                            aria-label={describe(row.task)}
                                            onclick={() =>
                                                peek.open({
                                                    id: row.task.id,
                                                    slug: row.task.slug,
                                                })}
                                        >
                                            <span
                                                class={`block h-full ${colors.fill}`}
                                                style:width={`${row.task.progress}%`}
                                            ></span>
                                        </button>
                                    </div>
                                </div>
                            {/each}
                        {/each}
                    </div>
                </div>
                <p class="mt-1.5 text-xs text-fg-muted">
                    Bars run from when a task was opened to when it was
                    finished, or to its deadline while open; the filled part is
                    its progress. Dashed bars have no deadline. The blue line is
                    today.
                    {#if progress.truncated}
                        Showing the first {progress.tasks.length} tasks.
                    {/if}
                </p>
            {/if}
        </section>

        <!-- Activity -->
        <section>
            <h2 class="section-title mb-2">Recent activity</h2>
            {#if progress.activity.length === 0}
                <p class="border-t border-line py-3 text-xs text-fg-muted">
                    Nothing yet.
                </p>
            {:else}
                <ul class="border-t border-line">
                    {#each progress.activity as item (item.id)}
                        <li class="row min-h-9 gap-2 px-2 py-1.5 text-[13px]">
                            <Avatar name={item.user_name ?? '?'} size="sm" />
                            <span class="min-w-0 flex-1 truncate">
                                <span class="font-medium"
                                    >{item.user_name ?? 'Someone'}</span
                                >
                                <span class="text-fg-muted"
                                    >{item.description}</span
                                >
                                {#if item.task_title && item.project_slug && item.task_slug}
                                    <a
                                        href={`/workspace/projects/${item.project_slug}/tasks/${item.task_slug}`}
                                        use:inertia
                                        class="text-fg hover:text-accent"
                                        >{item.task_title}</a
                                    >
                                {/if}
                            </span>
                            <span class="shrink-0 text-xs text-fg-faint"
                                >{formatTimeAgo(item.happened_at)}</span
                            >
                        </li>
                    {/each}
                </ul>
            {/if}
        </section>
    </div>
</AppShell>
