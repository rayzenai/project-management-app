<script lang="ts">
    import AppShell from '../components/AppShell.svelte';
    import { formatRelative } from '../lib/format';

    type StatusSlice = {
        value: string;
        label: string;
        color: string;
        count: number;
    };
    type ProjectRow = {
        slug: string;
        title: string;
        tasks_count: number;
        percent_complete: number;
        stalled: number;
        due_this_week: number;
        overdue: number;
        status_breakdown: StatusSlice[];
    };
    type Activity = {
        id: number;
        description: string | null;
        user_name: string | null;
        task_title: string | null;
        task_slug: string | null;
        project_slug: string | null;
        happened_at: string | null;
    };
    type Stats = {
        projects: number;
        tasks: number;
        percent_complete: number;
        due_this_week: number;
        stalled: number;
        overdue: number;
    };

    let {
        stats,
        status_breakdown,
        projects,
        recent_activity,
    }: {
        stats: Stats;
        status_breakdown: StatusSlice[];
        projects: ProjectRow[];
        recent_activity: Activity[];
    } = $props();

    function nonZero(slices: StatusSlice[]): StatusSlice[] {
        return slices.filter((s) => s.count > 0);
    }

    function pct(slice: StatusSlice, slices: StatusSlice[]): number {
        const total = slices.reduce((sum, s) => sum + s.count, 0);

        return total === 0 ? 0 : (slice.count / total) * 100;
    }

    const statCards = $derived([
        { label: 'Projects', value: stats.projects, tone: 'text-fg' },
        { label: 'Tasks', value: stats.tasks, tone: 'text-fg' },
        {
            label: '% Complete',
            value: `${stats.percent_complete}%`,
            tone: 'text-success',
        },
        {
            label: 'Due This Week',
            value: stats.due_this_week,
            tone: 'text-warn',
        },
        { label: 'Stalled', value: stats.stalled, tone: 'text-danger' },
        { label: 'Overdue', value: stats.overdue, tone: 'text-danger' },
    ]);
</script>

<svelte:head><title>Overview · Workspace</title></svelte:head>

<AppShell>
    <header class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Workspace Overview</h1>
        <p class="mt-1 text-sm text-fg-muted">
            Portfolio health across all projects.
        </p>
    </header>

    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {#each statCards as card (card.label)}
            <div class="rounded-xl border border-line bg-surface p-4">
                <div class="text-2xl font-bold {card.tone}">{card.value}</div>
                <div class="ws-eyebrow mt-1 text-fg-muted">
                    {card.label}
                </div>
            </div>
        {/each}
    </div>

    <section class="mb-6 rounded-xl border border-line bg-surface p-4">
        <h2 class="ws-eyebrow mb-3 text-fg-muted">Status breakdown</h2>
        {#if nonZero(status_breakdown).length > 0}
            <div
                class="flex h-3 w-full overflow-hidden rounded-full bg-surface-alt"
            >
                {#each nonZero(status_breakdown) as slice (slice.value)}
                    <div
                        class="h-full"
                        style="width: {pct(
                            slice,
                            status_breakdown,
                        )}%; background-color: {slice.color};"
                        title={`${slice.label}: ${slice.count}`}
                    ></div>
                {/each}
            </div>
            <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1.5">
                {#each nonZero(status_breakdown) as slice (slice.value)}
                    <span
                        class="inline-flex items-center gap-1.5 text-xs text-fg-muted"
                    >
                        <span
                            class="h-2.5 w-2.5 rounded-full"
                            style="background-color: {slice.color};"
                        ></span>
                        {slice.label} · {slice.count}
                    </span>
                {/each}
            </div>
        {:else}
            <p class="text-sm text-fg-muted">No tasks yet.</p>
        {/if}
    </section>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <section class="lg:col-span-2">
            <h2 class="ws-eyebrow mb-3 text-fg-muted">Projects</h2>
            <div
                class="overflow-hidden rounded-xl border border-line bg-surface"
            >
                {#each projects as project (project.slug)}
                    <a
                        href={`/workspace/projects/${project.slug}`}
                        class="flex items-center gap-4 border-b border-line-soft px-4 py-3 last:border-0 hover:bg-surface-alt"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-medium">
                                {project.title}
                            </div>
                            <div
                                class="mt-1.5 flex h-1.5 w-full overflow-hidden rounded-full bg-surface-alt"
                            >
                                {#each nonZero(project.status_breakdown) as slice (slice.value)}
                                    <div
                                        class="h-full"
                                        style="width: {pct(
                                            slice,
                                            project.status_breakdown,
                                        )}%; background-color: {slice.color};"
                                    ></div>
                                {/each}
                            </div>
                            <div class="mt-1 text-xs text-fg-muted">
                                {project.tasks_count} tasks
                                {#if project.stalled > 0}· <span
                                        class="text-danger"
                                        >{project.stalled} stalled</span
                                    >{/if}
                                {#if project.due_this_week > 0}· <span
                                        class="text-warn"
                                        >{project.due_this_week} due this week</span
                                    >{/if}
                                {#if project.overdue > 0}· <span
                                        class="text-danger"
                                        >{project.overdue} overdue</span
                                    >{/if}
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="text-lg font-bold text-success">
                                {project.percent_complete}%
                            </div>
                            <div
                                class="text-[10px] tracking-wider text-fg-faint uppercase"
                            >
                                complete
                            </div>
                        </div>
                    </a>
                {:else}
                    <p class="px-4 py-6 text-sm text-fg-muted">
                        No projects yet.
                    </p>
                {/each}
            </div>
        </section>

        <aside>
            <h2 class="ws-eyebrow mb-3 text-fg-muted">Recent activity</h2>
            <div class="rounded-xl border border-line bg-surface p-2">
                {#each recent_activity as item (item.id)}
                    <div
                        class="border-b border-line-soft px-2 py-2.5 last:border-0"
                    >
                        <p class="text-sm text-fg-muted">{item.description}</p>
                        <div
                            class="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-fg-muted"
                        >
                            {#if item.user_name}<span>{item.user_name}</span
                                >{/if}
                            {#if item.task_title && item.task_slug && item.project_slug}
                                · <a
                                    href={`/workspace/projects/${item.project_slug}?task=${item.task_slug}`}
                                    class="truncate hover:underline"
                                    >{item.task_title}</a
                                >
                            {/if}
                            {#if item.happened_at}· <span
                                    >{formatRelative(item.happened_at)}</span
                                >{/if}
                        </div>
                    </div>
                {:else}
                    <p class="px-2 py-6 text-sm text-fg-muted">
                        No recent activity.
                    </p>
                {/each}
            </div>
        </aside>
    </div>
</AppShell>
