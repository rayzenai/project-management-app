<script lang="ts">
    import AppShell from '../components/AppShell.svelte';
    import Avatar from '../components/Avatar.svelte';
    import ProgressRing from '../components/ProgressRing.svelte';
    import StatusGlyph from '../components/StatusGlyph.svelte';
    import { formatTimeAgo } from '../lib/format';
    import { quickAdd } from '../lib/quickAdd.svelte';

    type StatusSlice = {
        value: string;
        label: string;
        color: string;
        count: number;
    };
    type ProjectRow = {
        slug: string;
        title: string;
        title_np: string | null;
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

    const today = new Date();
    const heading = `${today.toLocaleDateString('en-GB', { weekday: 'long' })}, ${today.toLocaleDateString('en-GB', { day: 'numeric', month: 'long' })}`;

    const metrics = $derived([
        { label: 'Tasks', value: String(stats.tasks), danger: false },
        {
            label: 'Complete',
            value: `${stats.percent_complete}%`,
            danger: false,
        },
        {
            label: 'Due this week',
            value: String(stats.due_this_week),
            danger: false,
        },
        { label: 'Stalled', value: String(stats.stalled), danger: false },
        {
            label: 'Overdue',
            value: String(stats.overdue),
            danger: stats.overdue > 0,
        },
        { label: 'Projects', value: String(stats.projects), danger: false },
    ]);

    const rowGrid =
        'grid grid-cols-[16px_minmax(0,1fr)_56px_90px_70px_110px] items-center gap-3';
</script>

<svelte:head><title>Overview · Workspace</title></svelte:head>

<AppShell>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Overview</span>
        </div>
        <div class="flex items-center gap-1.5">
            <button
                type="button"
                class="btn-primary"
                onclick={() => quickAdd.open({})}
                >New task <kbd class="kbd">N</kbd></button
            >
        </div>
    {/snippet}

    <header class="mb-6">
        <h1 class="text-[22px] font-semibold tracking-[-0.02em]">{heading}</h1>
        <p class="mt-1 text-fg-muted">
            {#if stats.overdue === 1}
                1 item is past its deadline.
            {:else if stats.overdue > 1}
                {stats.overdue} items are past their deadline.
            {:else}
                Nothing is past its deadline.
            {/if}
        </p>
    </header>

    <div
        class="grid grid-cols-2 divide-x divide-line overflow-hidden rounded-lg border border-line bg-surface-alt sm:grid-cols-3 lg:grid-cols-6"
    >
        {#each metrics as metric (metric.label)}
            <div class="px-4 py-3">
                <div
                    class={`text-[22px] font-semibold tracking-[-0.02em] tabular-nums ${metric.danger ? 'text-danger' : ''}`}
                >
                    {metric.value}
                </div>
                <div class="text-xs text-fg-muted">{metric.label}</div>
            </div>
        {/each}
    </div>

    <section class="mt-8">
        <h2 class="section-title">
            Status
            <span class="section-count">{stats.tasks}</span>
        </h2>
        {#if nonZero(status_breakdown).length > 0}
            <div
                class="mt-3 flex h-2 w-full overflow-hidden rounded-full bg-line"
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
            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1.5">
                {#each nonZero(status_breakdown) as slice (slice.value)}
                    <span
                        class="inline-flex items-center gap-1.5 text-xs text-fg-muted"
                    >
                        <StatusGlyph status={slice.value} size={12} />
                        {slice.label}
                        <span class="font-mono text-fg-faint tabular-nums"
                            >{slice.count}</span
                        >
                    </span>
                {/each}
            </div>
        {:else}
            <p class="mt-2 text-fg-muted">No tasks yet.</p>
        {/if}
    </section>

    <div class="mt-8 grid gap-10 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="min-w-0">
            <h2 class="section-title">
                Projects
                <span class="section-count">{projects.length}</span>
            </h2>
            {#if projects.length > 0}
                <div
                    class={`${rowGrid} col-head mt-3 border-b border-line pb-1.5`}
                >
                    <span></span>
                    <span>Name</span>
                    <span class="text-right">Tasks</span>
                    <span class="text-right">This week</span>
                    <span class="text-right">Overdue</span>
                    <span>Progress</span>
                </div>
                {#each projects as project (project.slug)}
                    <a
                        href={`/workspace/projects/${project.slug}`}
                        class={`${rowGrid} -mx-2 rounded-md border-b border-line-soft px-2 py-2 transition hover:bg-hover`}
                    >
                        <ProgressRing percent={project.percent_complete} />
                        <span class="flex min-w-0 items-baseline gap-2.5">
                            <span class="truncate font-medium text-fg"
                                >{project.title}</span
                            >
                            {#if project.title_np}
                                <span
                                    class="font-np truncate text-xs text-fg-muted"
                                    >{project.title_np}</span
                                >
                            {/if}
                        </span>
                        <span
                            class="text-right font-mono text-xs text-fg-muted tabular-nums"
                            >{project.tasks_count}</span
                        >
                        <span
                            class="text-right font-mono text-xs text-fg-muted tabular-nums"
                            >{project.due_this_week}</span
                        >
                        <span
                            class={`text-right font-mono text-xs tabular-nums ${project.overdue > 0 ? 'text-danger' : 'text-fg-faint'}`}
                            >{project.overdue}</span
                        >
                        <span class="flex items-center gap-2">
                            <span class="h-1 w-14 rounded-full bg-line">
                                <span
                                    class="block h-full rounded-full bg-accent"
                                    style={`width:${project.percent_complete}%`}
                                ></span>
                            </span>
                            <span
                                class="font-mono text-xs text-fg-muted tabular-nums"
                                >{project.percent_complete}%</span
                            >
                        </span>
                    </a>
                {/each}
            {:else}
                <p class="mt-2 text-fg-muted">No projects yet.</p>
            {/if}
        </section>

        <aside class="min-w-0">
            <h2 class="section-title">
                Activity
                <span class="section-count">{recent_activity.length}</span>
            </h2>
            {#if recent_activity.length > 0}
                <ul class="mt-3 flex flex-col">
                    {#each recent_activity as item (item.id)}
                        <li
                            class="flex items-start gap-2.5 border-b border-line-soft py-2.5"
                        >
                            {#if item.user_name}
                                <Avatar name={item.user_name} class="mt-px" />
                            {:else}
                                <StatusGlyph status={null} class="mt-[3px]" />
                            {/if}
                            <p class="min-w-0 flex-1 text-xs leading-5">
                                {#if item.user_name}
                                    <span class="font-medium text-fg"
                                        >{item.user_name}</span
                                    >
                                {/if}
                                {#if item.description}
                                    <span class="text-fg-muted"
                                        >{item.description}</span
                                    >
                                {/if}
                                {#if item.task_title && item.task_slug && item.project_slug}
                                    <a
                                        href={`/workspace/projects/${item.project_slug}?task=${item.task_slug}`}
                                        class="text-fg hover:text-accent"
                                        >{item.task_title}</a
                                    >
                                {/if}
                            </p>
                            {#if item.happened_at}
                                <span
                                    class="shrink-0 text-xs text-fg-faint tabular-nums"
                                    >{formatTimeAgo(item.happened_at)}</span
                                >
                            {/if}
                        </li>
                    {/each}
                </ul>
            {:else}
                <p class="mt-2 text-fg-muted">No recent activity.</p>
            {/if}
        </aside>
    </div>
</AppShell>
