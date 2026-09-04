<script lang="ts">
    import { inertia, page, router } from '@inertiajs/svelte';
    import { ChevronRight, StickyNote } from '@lucide/svelte';
    import AppShell from '../components/AppShell.svelte';
    import NotesStrip from '../components/NotesStrip.svelte';
    import StatusGlyph from '../components/StatusGlyph.svelte';
    import TaskRegisterHead from '../components/TaskRegisterHead.svelte';
    import TaskRow from '../components/TaskRow.svelte';
    import { notesBoard } from '../lib/notesBoard.svelte';
    import { peek } from '../lib/peek.svelte';
    import { quickAdd } from '../lib/quickAdd.svelte';
    import type { SharedProps, Task } from '../lib/types';

    type Bucket = { key: string; label: string; tasks: Task[] };
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
        complete: number;
        percent_complete: number;
        overdue: number;
        due_this_week: number;
        stalled: number;
    };
    type Stats = {
        open: number;
        overdue: number;
        today: number;
        week: number;
        unscheduled: number;
        stalled: number;
        percent_complete: number;
        done_this_week: number;
        total: number;
        complete: number;
    };

    let {
        scope,
        buckets,
        recently_done,
        stats,
        status_breakdown,
        projects,
    }: {
        scope: 'mine' | 'all';
        buckets: Bucket[];
        recently_done: Task[];
        stats: Stats;
        status_breakdown: StatusSlice[];
        projects: ProjectRow[];
    } = $props();

    /**
     * The metric cards double as the filter for the register below them:
     * `all` shows every bucket, a bucket key shows just that one, and
     * `stalled` cuts across all of them.
     */
    let filter = $state<string>('all');
    let doneOpen = $state(false);

    const isStalled = (task: Task): boolean =>
        task.freshness?.bucket === 'stalled' ||
        task.freshness?.bucket === 'cold';

    const shown = $derived(
        buckets
            .filter(
                (b) =>
                    filter === 'all' ||
                    filter === 'stalled' ||
                    filter === b.key,
            )
            .map((b) => ({
                ...b,
                tasks:
                    filter === 'stalled' ? b.tasks.filter(isStalled) : b.tasks,
            }))
            .filter((b) => b.tasks.length > 0),
    );

    const visibleCount = $derived(
        shown.reduce((sum, b) => sum + b.tasks.length, 0),
    );

    $effect(() => {
        peek.openFromUrl(
            [...buckets.flatMap((b) => b.tasks), ...recently_done].map((t) => ({
                id: t.id,
                slug: t.slug,
            })),
        );
    });

    // Cards, in the order they are read: what is late, what lands next, what
    // has gone quiet, what has no date at all.
    const cards = $derived([
        {
            key: 'overdue',
            label: 'Overdue',
            value: stats.overdue,
            hint: stats.overdue === 0 ? 'all on time' : 'past deadline',
            tone: 'danger',
        },
        {
            key: 'today',
            label: 'Due today',
            value: stats.today,
            hint: 'lands today',
            tone: 'accent',
        },
        {
            key: 'week',
            label: 'This week',
            value: stats.week,
            hint: 'next seven days',
            tone: 'plain',
        },
        {
            key: 'stalled',
            label: 'Stalled',
            value: stats.stalled,
            hint: 'no move in 14d',
            tone: 'warn',
        },
        {
            key: 'unscheduled',
            label: 'No date',
            value: stats.unscheduled,
            hint: 'unscheduled',
            tone: 'plain',
        },
    ]);

    const toneText: Record<string, string> = {
        danger: 'text-danger',
        warn: 'text-warn',
        accent: 'text-accent',
        plain: 'text-fg',
    };

    const breakdownTotal = $derived(
        status_breakdown.reduce((sum, s) => sum + s.count, 0),
    );

    // Stickies ride on the shared props of every workspace page, so Home reads
    // them from there rather than adding them to its own payload.
    const stickyNotes = $derived(
        ((page.props ?? {}) as unknown as SharedProps).workspaceNotes ?? [],
    );
    const noteFeed = $derived(
        ((page.props ?? {}) as unknown as SharedProps).taskNotes ?? [],
    );

    function setScope(next: 'mine' | 'all') {
        router.get(
            '/workspace',
            { scope: next },
            { preserveState: false, preserveScroll: true },
        );
    }

    const today = new Date();
    const heading = `${today.toLocaleDateString('en-GB', { weekday: 'long' })}, ${today.toLocaleDateString('en-GB', { day: 'numeric', month: 'long' })}`;
</script>

<svelte:head><title>Home · Workspace</title></svelte:head>

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <span class="truncate font-medium">Home</span>
        </div>
        <div class="flex items-center gap-1.5">
            <div
                class="flex items-center rounded-md border border-line bg-surface-alt p-[2px]"
                role="group"
                aria-label="Scope"
            >
                {#each [{ key: 'mine', label: 'Mine' }, { key: 'all', label: 'All' }] as opt (opt.key)}
                    <button
                        type="button"
                        class={`h-[22px] rounded-[5px] px-2 text-xs font-medium transition ${
                            scope === opt.key
                                ? 'bg-surface text-fg'
                                : 'text-fg-muted hover:text-fg'
                        }`}
                        aria-pressed={scope === opt.key}
                        onclick={() => setScope(opt.key as 'mine' | 'all')}
                    >
                        {opt.label}
                    </button>
                {/each}
            </div>
            <button
                type="button"
                class="btn-primary"
                onclick={() => quickAdd.open({})}
                >New task <kbd class="kbd">N</kbd></button
            >
        </div>
    {/snippet}

    <div class="xl:grid xl:grid-cols-[minmax(0,1fr)_312px]">
        <div class="px-5 pt-6 pb-8 lg:px-6">
            <header class="mb-4">
                <h1 class="text-[19px] font-semibold tracking-[-0.02em]">
                    {heading}
                </h1>
                <p class="mt-0.5 text-[13px] text-fg-muted">
                    {scope === 'mine'
                        ? 'Your assigned work'
                        : 'Everything across your projects'} · {stats.open} open
                </p>
            </header>

            <!-- Completion is the one hero figure; the cards beside it are the
             exceptions worth acting on. -->
            <div
                class="grid gap-4 lg:grid-cols-[minmax(0,260px)_minmax(0,1fr)]"
            >
                <div class="panel flex flex-col justify-between p-5">
                    <div class="text-xs font-medium text-fg-muted">
                        Completion
                    </div>
                    <div class="mt-3 flex items-end gap-2">
                        <span
                            class="text-[48px] leading-none font-semibold tracking-[-0.03em]"
                            >{stats.percent_complete}<span
                                class="text-[26px] text-fg-faint">%</span
                            ></span
                        >
                    </div>
                    <div
                        class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-alt"
                    >
                        <div
                            class="h-full rounded-full bg-accent"
                            style={`width: ${stats.percent_complete}%`}
                        ></div>
                    </div>
                    <div class="mt-2 text-xs text-fg-muted tabular-nums">
                        {stats.complete} of {stats.total} done · {stats.done_this_week}
                        finished this week
                    </div>
                </div>

                <div
                    class="grid grid-cols-2 gap-4 sm:grid-cols-3 xl:grid-cols-5"
                >
                    {#each cards as card (card.key)}
                        {@const active = filter === card.key}
                        <button
                            type="button"
                            aria-pressed={active}
                            disabled={card.value === 0}
                            onclick={() => (filter = active ? 'all' : card.key)}
                            class={`panel flex flex-col justify-between p-4 text-left transition disabled:pointer-events-none disabled:opacity-45 ${
                                active
                                    ? 'border-accent bg-accent-soft'
                                    : 'hover:bg-hover'
                            }`}
                        >
                            <span class="text-xs font-medium text-fg-muted"
                                >{card.label}</span
                            >
                            <span
                                class={`mt-3 text-[28px] leading-none font-semibold tracking-[-0.02em] ${
                                    card.value === 0
                                        ? 'text-fg-faint'
                                        : toneText[card.tone]
                                }`}>{card.value}</span
                            >
                            <span class="mt-1.5 text-[11px] text-fg-faint"
                                >{card.hint}</span
                            >
                        </button>
                    {/each}
                </div>
            </div>

            <!-- Part-to-whole across the workflow. Segments are separated by a 2px
             surface gap because two statuses share a colour in config. -->
            {#if breakdownTotal > 0}
                <section class="panel mt-4 p-5">
                    <div class="section-title">
                        Status <span class="section-count"
                            >{breakdownTotal}</span
                        >
                    </div>
                    <div class="mt-3 flex h-2 gap-[2px] overflow-hidden">
                        {#each status_breakdown.filter((s) => s.count > 0) as slice (slice.value)}
                            <div
                                class="h-full rounded-[4px]"
                                style={`width: ${(slice.count / breakdownTotal) * 100}%; background: ${slice.color}`}
                            ></div>
                        {/each}
                    </div>
                    <div
                        class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-2"
                    >
                        {#each status_breakdown as slice (slice.value)}
                            <span
                                class={`flex items-center gap-1.5 text-xs ${slice.count === 0 ? 'text-fg-faint' : 'text-fg-muted'}`}
                            >
                                <StatusGlyph status={slice.value} />
                                {slice.label}
                                <span
                                    class="font-mono text-fg-faint tabular-nums"
                                    >{slice.count}</span
                                >
                            </span>
                        {/each}
                    </div>
                </section>
            {/if}

            {#if projects.length > 0}
                <section class="mt-6">
                    <div class="section-title mb-2.5">
                        Projects <span class="section-count"
                            >{projects.length}</span
                        >
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {#each projects as project (project.slug)}
                            <a
                                href={`/workspace/projects/${project.slug}`}
                                use:inertia
                                class="panel block p-5 transition hover:bg-hover"
                            >
                                <div
                                    class="flex items-baseline justify-between gap-3"
                                >
                                    <span
                                        class="truncate text-[13px] font-medium"
                                        >{project.title}</span
                                    >
                                    <span
                                        class="shrink-0 font-mono text-xs text-fg-muted tabular-nums"
                                        >{project.percent_complete}%</span
                                    >
                                </div>
                                {#if project.title_np}
                                    <div
                                        class="font-np mt-0.5 truncate text-xs text-fg-faint"
                                    >
                                        {project.title_np}
                                    </div>
                                {/if}
                                <div
                                    class="mt-3 h-1.5 overflow-hidden rounded-full bg-surface-alt"
                                >
                                    <div
                                        class="h-full rounded-full bg-accent"
                                        style={`width: ${project.percent_complete}%`}
                                    ></div>
                                </div>
                                <div
                                    class="mt-2.5 flex items-center gap-3 text-xs tabular-nums"
                                >
                                    <span class="text-fg-muted"
                                        >{project.complete}/{project.tasks_count}
                                        done</span
                                    >
                                    {#if project.overdue > 0}
                                        <span class="text-danger"
                                            >{project.overdue} overdue</span
                                        >
                                    {/if}
                                    {#if project.stalled > 0}
                                        <span class="text-warn"
                                            >{project.stalled} stalled</span
                                        >
                                    {/if}
                                </div>
                            </a>
                        {/each}
                    </div>
                </section>
            {/if}
        </div>

        <aside
            class="border-t border-line xl:sticky xl:top-12 xl:self-start xl:border-t-0 xl:border-l"
        >
            <section class="px-5 py-6 lg:px-6">
                <div class="mb-3 flex items-center gap-2">
                    <StickyNote
                        class="h-[15px] w-[15px] shrink-0 text-fg-faint"
                    />
                    <span class="section-title">
                        My notes
                        <span class="section-count">{stickyNotes.length}</span>
                    </span>
                    <button
                        type="button"
                        class="btn-ghost ml-auto h-6"
                        onclick={() => notesBoard.show()}>Open board</button
                    >
                </div>
                {#if stickyNotes.length === 0}
                    <button
                        type="button"
                        onclick={() => notesBoard.show({ compose: true })}
                        class="panel flex w-full items-center justify-center p-5 text-[13px] text-fg-muted transition hover:bg-hover"
                    >
                        Jot down your first note
                    </button>
                {:else}
                    <NotesStrip {stickyNotes} taskNotes={[]} />
                {/if}
            </section>

            {#if noteFeed.length > 0}
                <section class="border-t border-line px-5 py-6 lg:px-6">
                    <h2 class="section-title mb-3">
                        From my tasks
                        <span class="section-count">{noteFeed.length}</span>
                    </h2>
                    <NotesStrip taskNotes={noteFeed} compose={false} />
                </section>
            {/if}
        </aside>
    </div>

    <div class="border-t border-line">
        <div class="flex h-11 items-center gap-2 px-5 lg:px-6">
            <span class="section-title">
                {filter === 'all' ? 'Open work' : 'Filtered'}
                <span class="section-count">{visibleCount}</span>
            </span>
            {#if filter !== 'all'}
                <button
                    type="button"
                    class="btn-ghost h-6"
                    onclick={() => (filter = 'all')}>Clear filter</button
                >
            {/if}
        </div>
    </div>

    {#if visibleCount === 0}
        <div class="flex flex-col items-center gap-3 px-4 py-14">
            <p class="text-[13px] text-fg-muted">
                {#if filter !== 'all'}
                    Nothing in this view.
                {:else if scope === 'mine'}
                    Nothing is assigned to you.
                {:else}
                    No open work.
                {/if}
            </p>
            <div class="flex items-center gap-1.5">
                {#if filter !== 'all'}
                    <button
                        type="button"
                        class="btn"
                        onclick={() => (filter = 'all')}>Show everything</button
                    >
                {:else if scope === 'mine'}
                    <button
                        type="button"
                        class="btn"
                        onclick={() => setScope('all')}>Show all tasks</button
                    >
                {/if}
                <button
                    type="button"
                    class="btn-primary"
                    onclick={() => quickAdd.open({})}>New task</button
                >
            </div>
        </div>
    {:else}
        <TaskRegisterHead />
        {#each shown as bucket (bucket.key)}
            <section>
                <div class="group-head">
                    <span class={bucket.key === 'overdue' ? 'text-danger' : ''}
                        >{bucket.label}</span
                    >
                    <span class="text-xs font-normal text-fg-faint tabular-nums"
                        >{bucket.tasks.length}</span
                    >
                </div>
                {#each bucket.tasks as task (task.id)}
                    <TaskRow {task} showProject compact />
                {/each}
            </section>
        {/each}
    {/if}

    {#if recently_done.length > 0}
        <section>
            <button
                type="button"
                class="group-head w-full text-left"
                aria-expanded={doneOpen}
                onclick={() => (doneOpen = !doneOpen)}
            >
                <ChevronRight
                    class={`h-3.5 w-3.5 text-fg-faint transition-transform ${doneOpen ? 'rotate-90' : ''}`}
                />
                <span class="text-fg-muted">Completed this week</span>
                <span class="text-xs font-normal text-fg-faint tabular-nums"
                    >{recently_done.length}</span
                >
            </button>
            {#if doneOpen}
                {#each recently_done as task (task.id)}
                    <TaskRow {task} showProject compact />
                {/each}
            {/if}
        </section>
    {/if}
</AppShell>
