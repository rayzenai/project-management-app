<script lang="ts">
    import { SvelteSet } from 'svelte/reactivity';
    import type { Status, Task } from '../../lib/types';

    // Always fed the UNFILTERED task list: the strip describes the project, not the filter.
    let { tasks, statuses }: { tasks: Task[]; statuses: Status[] } = $props();

    const completeSet = $derived(
        new SvelteSet(
            statuses.filter((s) => s.is_complete).map((s) => s.value),
        ),
    );
    const isComplete = (t: Task) => completeSet.has(t.status);
    const isOverdue = (t: Task) =>
        !!t.deadline_at &&
        !isComplete(t) &&
        new Date(t.deadline_at) < new Date(new Date().toDateString());

    const total = $derived(tasks.length);
    const doneCount = $derived(tasks.filter(isComplete).length);
    const openCount = $derived(total - doneCount);
    const percent = $derived(
        total === 0 ? 0 : Math.round((doneCount / total) * 100),
    );
    const overdueCount = $derived(tasks.filter(isOverdue).length);
    // Incomplete only: done-and-unassigned isn't actionable.
    const unassignedCount = $derived(
        tasks.filter(
            (t) => !isComplete(t) && (t.assignments ?? []).length === 0,
        ).length,
    );

    const segments = $derived(
        statuses
            .map((s) => ({
                value: s.value,
                label: s.label,
                color: s.color,
                count: tasks.filter((t) => t.status === s.value).length,
            }))
            .filter((s) => s.count > 0),
    );
</script>

<div
    class="grid grid-cols-2 divide-x divide-line overflow-hidden rounded-lg border border-line bg-surface-alt sm:grid-cols-3 lg:grid-cols-5"
>
    <div class="px-4 py-3">
        <div class="text-[22px] font-semibold tracking-[-0.02em] tabular-nums">
            {percent}%
        </div>
        <div class="text-xs text-fg-muted">Complete</div>
        <div
            class="mt-2 flex h-1.5 w-full overflow-hidden rounded-full bg-line"
            role="img"
            aria-label={`${percent}% complete`}
        >
            {#each segments as seg (seg.value)}
                <div
                    class="h-full"
                    style={`width:${total === 0 ? 0 : (seg.count / total) * 100}%; background:${seg.color};`}
                    title={`${seg.label}: ${seg.count}`}
                ></div>
            {/each}
        </div>
    </div>
    <div class="px-4 py-3">
        <div class="text-[22px] font-semibold tracking-[-0.02em] tabular-nums">
            {total}
        </div>
        <div class="text-xs text-fg-muted">Tasks</div>
    </div>
    <div class="px-4 py-3">
        <div class="text-[22px] font-semibold tracking-[-0.02em] tabular-nums">
            {openCount} / {doneCount}
        </div>
        <div class="text-xs text-fg-muted">Open / done</div>
    </div>
    <div class="px-4 py-3">
        <div
            class={`text-[22px] font-semibold tracking-[-0.02em] tabular-nums ${overdueCount > 0 ? 'text-danger' : ''}`}
        >
            {overdueCount}
        </div>
        <div class="text-xs text-fg-muted">Overdue</div>
    </div>
    <div class="px-4 py-3">
        <div class="text-[22px] font-semibold tracking-[-0.02em] tabular-nums">
            {unassignedCount}
        </div>
        <div class="text-xs text-fg-muted">Unassigned</div>
    </div>
</div>
