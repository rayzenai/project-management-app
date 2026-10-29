<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { formatTimeAgo } from '../lib/format';
    import type { ProgressReport, Status } from '../lib/types';
    import Avatar from './Avatar.svelte';
    import ProgressRing from './ProgressRing.svelte';
    import GanttChart from './team/GanttChart.svelte';
    import TaskReportDrawer from './team/TaskReportDrawer.svelte';

    /**
     * The body of a live progress page (a team's or a project's): headline
     * counts, each person's workload, the Gantt timeline and recent activity,
     * with a read-only panel for the task picked on the chart.
     */
    let {
        progress,
        showOrganization = false,
    }: {
        progress: ProgressReport;
        /** Say which organization each person is from, for mixed groups. */
        showOrganization?: boolean;
    } = $props();

    const REFRESH_MS = 15_000;

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

    // ---- What the chart shows ----
    let memberFilter = $state<number | null>(null);
    let show = $state('all');
    let selectedId = $state<number | null>(null);
    let timeline = $state<HTMLElement | null>(null);

    // Follows the live data: a refreshed task replaces the open one, and a
    // task that disappears closes the panel.
    const selectedTask = $derived(
        progress.tasks.find((t) => t.id === selectedId) ?? null,
    );

    function focusTimeline(filter: string) {
        show = filter;
        timeline?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const DAY = 86_400_000;

    function dayOf(iso: string): number {
        const [y, m, d] = iso.slice(0, 10).split('-').map(Number);

        return new Date(y, m - 1, d).getTime();
    }

    const summary = $derived.by(() => {
        const now = new Date();
        const today = new Date(
            now.getFullYear(),
            now.getMonth(),
            now.getDate(),
        ).getTime();
        const open = progress.tasks.filter((t) => !t.is_complete);

        return {
            open: open.length,
            overdue: open.filter((t) => t.is_overdue).length,
            soon: open.filter((t) => {
                const due = t.deadline_at ? dayOf(t.deadline_at) : null;

                return due !== null && due >= today && due <= today + 7 * DAY;
            }).length,
            done: progress.tasks.length - open.length,
        };
    });

    const stats = $derived([
        {
            label: 'Open tasks',
            value: summary.open,
            tone: 'text-fg',
            filter: 'open',
        },
        {
            label: 'Due in 7 days',
            value: summary.soon,
            tone: summary.soon > 0 ? 'text-warn' : 'text-fg',
            filter: 'soon',
        },
        {
            label: 'Overdue',
            value: summary.overdue,
            tone: summary.overdue > 0 ? 'text-danger' : 'text-fg',
            filter: 'late',
        },
        {
            label: 'Done',
            value: summary.done,
            tone: 'text-fg',
            filter: 'done',
        },
    ]);
</script>

<div class="space-y-8">
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        {#each stats as stat (stat.label)}
            <button
                type="button"
                class={`panel px-3 py-2.5 text-left transition hover:bg-hover ${show === stat.filter ? 'border-accent' : ''}`}
                aria-pressed={show === stat.filter}
                onclick={() =>
                    focusTimeline(show === stat.filter ? 'all' : stat.filter)}
            >
                <div class="text-xs text-fg-muted">{stat.label}</div>
                <div class={`font-mono text-xl tabular-nums ${stat.tone}`}>
                    {stat.value}
                </div>
            </button>
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
                        <th class="col-head py-1.5 pr-3 font-normal">Person</th>
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
                        {@const filtering = memberFilter === member.id}
                        <tr
                            class={`border-b border-line-soft ${filtering ? 'bg-accent-soft' : 'hover:bg-hover'} ${member.is_active ? '' : 'opacity-60'}`}
                        >
                            <td class="py-1.5 pr-3">
                                <button
                                    type="button"
                                    class="flex w-full min-w-0 items-center gap-2 text-left"
                                    aria-pressed={filtering}
                                    title={filtering
                                        ? 'Show everyone on the timeline'
                                        : `Show only ${member.name}'s tasks on the timeline`}
                                    onclick={() =>
                                        (memberFilter = filtering
                                            ? null
                                            : member.id)}
                                >
                                    <Avatar name={member.name} size="md" />
                                    <span class="min-w-0">
                                        <span class="flex items-center gap-2">
                                            <span
                                                class="truncate font-medium hover:text-accent"
                                                >{member.name}</span
                                            >
                                            {#if member.role === 'leader'}
                                                <span class="chip chip-accent"
                                                    >Leader</span
                                                >
                                            {/if}
                                        </span>
                                        <span
                                            class="block truncate text-xs text-fg-muted"
                                        >
                                            {[
                                                member.title,
                                                showOrganization
                                                    ? member.organization
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                </button>
                            </td>
                            <td class="px-3 text-right font-mono tabular-nums"
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
                            <td class="pl-3 text-right text-xs text-fg-muted"
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
            counting finished tasks as 100%. Click a name to see only their
            tasks on the timeline.
        </p>
    </section>

    <div bind:this={timeline} class="scroll-mt-14">
        <GanttChart
            tasks={progress.tasks}
            members={progress.members}
            {statuses}
            {selectedId}
            {memberFilter}
            bind:show
            onselect={(task) => (selectedId = task.id)}
            onclearmember={() => (memberFilter = null)}
        />
        {#if progress.truncated}
            <p class="mt-1 text-xs text-fg-muted">
                Showing the first {progress.tasks.length} tasks.
            </p>
        {/if}
    </div>

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
                    {@const task = progress.tasks.find(
                        (t) =>
                            t.slug === item.task_slug &&
                            t.project.slug === item.project_slug,
                    )}
                    <li class="row min-h-9 gap-2 px-2 py-1.5 text-[13px]">
                        <Avatar name={item.user_name ?? '?'} size="sm" />
                        <span class="min-w-0 flex-1 truncate">
                            <span class="font-medium"
                                >{item.user_name ?? 'Someone'}</span
                            >
                            <span class="text-fg-muted">{item.description}</span
                            >
                            {#if item.task_title && task}
                                <button
                                    type="button"
                                    class="text-fg hover:text-accent"
                                    onclick={() => (selectedId = task.id)}
                                    >{item.task_title}</button
                                >
                            {:else if item.task_title}
                                <span class="text-fg">{item.task_title}</span>
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

<TaskReportDrawer
    task={selectedTask}
    tasks={progress.tasks}
    {statuses}
    onselect={(task) => (selectedId = task.id)}
    refreshKey={progress.generated_at}
    onclose={() => (selectedId = null)}
/>
