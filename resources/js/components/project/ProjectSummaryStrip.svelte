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

<div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
    <div class="panel px-4 py-3">
        <div class="text-xs font-medium text-fg-muted">Complete</div>
        <div class="mt-1.5 flex items-center gap-2.5">
            <span
                class="text-[22px] leading-none font-semibold tracking-[-0.02em]"
                >{percent}%</span
            >
            <span
                class="flex h-1.5 min-w-0 flex-1 overflow-hidden rounded-full bg-line"
                role="img"
                aria-label={`${percent}% complete`}
            >
                {#each segments as seg (seg.value)}
                    <span
                        class="h-full"
                        style={`width:${total === 0 ? 0 : (seg.count / total) * 100}%; background:${seg.color};`}
                        title={`${seg.label}: ${seg.count}`}
                    ></span>
                {/each}
            </span>
        </div>
    </div>

    <div class="panel px-4 py-3">
        <div class="text-xs font-medium text-fg-muted">Tasks</div>
        <div
            class="mt-1.5 text-[22px] leading-none font-semibold tracking-[-0.02em] tabular-nums"
        >
            {total}
        </div>
    </div>

    <div class="panel px-4 py-3">
        <div class="text-xs font-medium text-fg-muted">Open / done</div>
        <div
            class="mt-1.5 text-[22px] leading-none font-semibold tracking-[-0.02em] tabular-nums"
        >
            {openCount} <span class="text-fg-faint">/</span>
            {doneCount}
        </div>
    </div>

    <div class="panel px-4 py-3">
        <div class="text-xs font-medium text-fg-muted">Overdue</div>
        <div
            class={`mt-1.5 text-[22px] leading-none font-semibold tracking-[-0.02em] tabular-nums ${overdueCount > 0 ? 'text-danger' : 'text-fg-faint'}`}
        >
            {overdueCount}
        </div>
    </div>

    <div class="panel px-4 py-3">
        <div class="text-xs font-medium text-fg-muted">Unassigned</div>
        <div
            class={`mt-1.5 text-[22px] leading-none font-semibold tracking-[-0.02em] tabular-nums ${unassignedCount > 0 ? 'text-warn' : 'text-fg-faint'}`}
        >
            {unassignedCount}
        </div>
    </div>
</div>
