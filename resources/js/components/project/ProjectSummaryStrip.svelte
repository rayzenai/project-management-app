<script lang="ts">
    import { SvelteSet } from 'svelte/reactivity';
    import type { Status, Task } from '../../lib/types';

    // Always fed the UNFILTERED task list — the strip describes the project, not the filter.
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
    // Incomplete only — done-and-unassigned isn't actionable.
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

<section class="mb-4 rounded-xl border border-line bg-surface p-4">
    <div class="flex items-center gap-4">
        <div class="shrink-0">
            <div class="text-2xl font-bold text-fg tabular-nums">
                {percent}%
            </div>
            <div class="ws-eyebrow text-fg-muted">Complete</div>
        </div>
        <div
            class="flex h-2 flex-1 overflow-hidden rounded-full bg-surface-alt"
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

    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <div>
            <div class="text-lg font-semibold text-fg tabular-nums">
                {total}
            </div>
            <div class="ws-eyebrow text-fg-muted">Tasks</div>
        </div>
        <div>
            <div class="text-lg font-semibold text-fg tabular-nums">
                {openCount} / {doneCount}
            </div>
            <div class="ws-eyebrow text-fg-muted">Open/Done</div>
        </div>
        <div>
            <div
                class={`text-lg font-semibold tabular-nums ${overdueCount > 0 ? 'text-danger' : 'text-fg'}`}
            >
                {overdueCount} <span class="text-sm">⚠</span>
            </div>
            <div class="ws-eyebrow text-fg-muted">Overdue</div>
        </div>
        <div>
            <div class="text-lg font-semibold text-fg tabular-nums">
                {unassignedCount} <span class="text-sm text-fg-faint">◌</span>
            </div>
            <div class="ws-eyebrow text-fg-muted">Unassigned</div>
        </div>
    </div>
</section>
