<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { Search } from '@lucide/svelte';
    import AppShell from '../components/AppShell.svelte';
    import TaskRow from '../components/TaskRow.svelte';
    import type { Project, SharedProps, Task } from '../lib/types';

    let {
        project,
        tasks,
        categories,
        statusMap,
        planDay = null,
    }: {
        project: Project;
        tasks: Task[];
        oathDate?: string | null;
        planDay?: number | null;
        categories: Record<string, { label: string; color: string }>;
        statusMap: Record<string, { label: string; color: string }>;
        deadlineTypes: Record<string, { label: string; days?: number }>;
    } = $props();

    let categoryFilter = $state<string | null>(null);
    let statusFilter = $state<string | null>(null);
    let query = $state('');

    const filtered = $derived(
        tasks.filter((t) => {
            if (categoryFilter && t.category !== categoryFilter) {
                return false;
            }

            if (statusFilter && t.status !== statusFilter) {
                return false;
            }

            if (query.trim()) {
                const q = query.toLowerCase();
                const hay =
                    `${t.title} ${t.title_np ?? ''} ${t.responsible_ministry ?? ''}`.toLowerCase();

                if (!hay.includes(q)) {
                    return false;
                }
            }

            return true;
        }),
    );

    const counts = $derived.by(() => {
        const out: Record<string, number> = {};

        for (const t of tasks) {
            const c = t.category ?? 'uncategorized';
            out[c] = (out[c] ?? 0) + 1;
        }

        return out;
    });

    // Finished is whatever the workflow flags `is_complete`, never a hardcoded
    // status string (mirrors MyWorkspace).
    const shared = $derived((page.props ?? {}) as unknown as SharedProps);
    const completeStatuses = $derived(
        new Set(
            (shared.statuses ?? [])
                .filter((s) => s.is_complete)
                .map((s) => s.value),
        ),
    );

    const summary = $derived.by(() => {
        const complete = tasks.filter((t) =>
            completeStatuses.has(t.status),
        ).length;
        const late = tasks.filter((t) => t.is_late).length;
        const total = tasks.length;

        return {
            total,
            complete,
            percent: total === 0 ? 0 : Math.round((complete / total) * 100),
            late,
            showing: filtered.length,
        };
    });

    // Presentation only: group the filtered rows by category, in the order
    // config/government.php lists them; uncategorized rows come last.
    const groups = $derived.by(() => {
        const order = [...Object.keys(categories), 'uncategorized'];
        const buckets: Record<string, Task[]> = {};

        for (const t of filtered) {
            const key =
                t.category && categories[t.category]
                    ? t.category
                    : 'uncategorized';
            (buckets[key] ??= []).push(t);
        }

        return order
            .filter((key) => (buckets[key]?.length ?? 0) > 0)
            .map((key) => ({
                key,
                label: categories[key]?.label ?? 'Uncategorized',
                tasks: buckets[key] ?? [],
            }));
    });

    const selectClass =
        'h-7 rounded-md border border-line bg-surface px-2 text-[13px] text-fg focus:border-accent focus:outline-none';
</script>

<svelte:head><title>100-Point Tracker · Workspace</title></svelte:head>

<AppShell flush>
    {#snippet bar()}
        <div class="flex min-w-0 flex-1 items-center gap-1.5">
            <a href="/workspace/projects" class="text-fg-muted hover:text-fg"
                >Projects</a
            >
            <span class="text-fg-faint">/</span>
            <a
                href={`/workspace/projects/${project.slug}`}
                class="truncate text-fg-muted hover:text-fg">{project.title}</a
            >
            <span class="text-fg-faint">/</span>
            <span class="truncate font-medium">100-point tracker</span>
        </div>
    {/snippet}

    <div
        class="flex h-10 flex-wrap items-center gap-2 border-b border-line px-3"
    >
        <label class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-2 h-3.5 w-3.5 -translate-y-1/2 text-fg-faint"
            />
            <input
                type="text"
                bind:value={query}
                placeholder="Search tasks, ministries"
                class="input h-7 w-56 py-0 pl-7"
            />
        </label>
        <select bind:value={categoryFilter} class={selectClass}>
            <option value={null}>All categories</option>
            {#each Object.entries(categories) as [slug, info] (slug)}
                <option value={slug}>{info.label} ({counts[slug] ?? 0})</option>
            {/each}
        </select>
        <select bind:value={statusFilter} class={selectClass}>
            <option value={null}>All statuses</option>
            {#each Object.entries(statusMap) as [slug, info] (slug)}
                <option value={slug}>{info.label}</option>
            {/each}
        </select>
        <span class="ml-auto font-mono text-xs text-fg-faint tabular-nums"
            >{summary.showing} / {summary.total}</span
        >
    </div>

    <div class="px-4 py-5 lg:px-8 lg:py-6">
        <h1 class="text-[22px] font-semibold tracking-[-0.02em]">
            100-point tracker
        </h1>
        <p class="mt-1 text-fg-muted">
            {#if planDay !== null}
                <span class="font-medium text-fg">Day {planDay}</span> since the swearing-in.
            {/if}
            The full plan, filterable by category, status and ministry.
        </p>

        <div
            class="mt-5 grid grid-cols-2 divide-x divide-line overflow-hidden rounded-lg border border-line bg-surface-alt sm:grid-cols-4"
        >
            <div class="px-4 py-3">
                <div
                    class="text-[22px] font-semibold tracking-[-0.02em] tabular-nums"
                >
                    {summary.total}
                </div>
                <div class="text-xs text-fg-muted">Items</div>
            </div>
            <div class="px-4 py-3">
                <div
                    class="text-[22px] font-semibold tracking-[-0.02em] tabular-nums"
                >
                    {summary.percent}%
                </div>
                <div class="text-xs text-fg-muted">Complete</div>
            </div>
            <div class="px-4 py-3">
                <div
                    class="text-[22px] font-semibold tracking-[-0.02em] tabular-nums"
                >
                    {summary.complete}
                </div>
                <div class="text-xs text-fg-muted">Done</div>
            </div>
            <div class="px-4 py-3">
                <div
                    class={`text-[22px] font-semibold tracking-[-0.02em] tabular-nums ${summary.late > 0 ? 'text-danger' : ''}`}
                >
                    {summary.late}
                </div>
                <div class="text-xs text-fg-muted">Late</div>
            </div>
        </div>
    </div>

    {#if groups.length > 0}
        {#each groups as group (group.key)}
            <div class="group-head">
                <span>{group.label}</span>
                <span class="section-count">{group.tasks.length}</span>
            </div>
            {#each group.tasks as task (task.id)}
                <TaskRow {task} {project} />
            {/each}
        {/each}
    {:else}
        <p class="px-4 text-fg-muted lg:px-8">No tasks match those filters.</p>
    {/if}
</AppShell>
