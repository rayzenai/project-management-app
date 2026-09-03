<script lang="ts">
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import { initials } from '../../lib/format';
    import type { Member, Project, Status, Task } from '../../lib/types';
    import TaskTableRow from './TaskTableRow.svelte';

    let {
        project,
        tasks,
        statuses,
    }: { project: Project; tasks: Task[]; statuses: Status[] } = $props();

    interface PersonBucket {
        key: string;
        member: Member | null;
        open: number;
        done: number;
        tasks: Task[];
    }

    const completeSet = $derived(
        new SvelteSet(
            statuses.filter((s) => s.is_complete).map((s) => s.value),
        ),
    );

    // A task with N assignees appears in N sections (the full AssigneeStack is still
    // shown per row, so the duplication stays legible).
    const buckets = $derived.by(() => {
        const map = new SvelteMap<string, PersonBucket>();

        function push(key: string, member: Member | null, task: Task) {
            if (!map.has(key)) {
                map.set(key, { key, member, open: 0, done: 0, tasks: [] });
            }

            const bucket = map.get(key)!;
            bucket.tasks.push(task);

            if (completeSet.has(task.status)) {
                bucket.done += 1;
            } else {
                bucket.open += 1;
            }
        }

        for (const t of tasks) {
            const assignments = t.assignments ?? [];

            if (assignments.length === 0) {
                push('unassigned', null, t);
            } else {
                for (const a of assignments) {
                    if (a.member) {
                        push(`member:${a.member.id}`, a.member, t);
                    }
                }
            }
        }

        const unassigned = map.get('unassigned') ?? null;
        const people = [...map.values()]
            .filter((b) => b.key !== 'unassigned')
            .sort(
                (a, b) =>
                    b.open - a.open ||
                    (a.member?.name ?? '').localeCompare(b.member?.name ?? ''),
            );

        if (unassigned) {
            people.push(unassigned);
        } // always last; omitted when empty since empty buckets never get created

        return people;
    });

    const onlyUnassigned = $derived(
        buckets.length > 0 && buckets.every((b) => b.key === 'unassigned'),
    );

    const collapsed = new SvelteSet<string>();

    function toggleCollapsed(key: string) {
        if (collapsed.has(key)) {
            collapsed.delete(key);
        } else {
            collapsed.add(key);
        }
    }
</script>

<div class="space-y-4">
    {#if onlyUnassigned}
        <p class="text-sm text-fg-muted">
            No one is assigned yet — open a task and add assignees from the
            Peek.
        </p>
    {/if}

    {#each buckets as bucket (bucket.key)}
        <section class="rounded-xl border border-line bg-surface">
            <header class="flex items-center gap-3 px-4 py-3">
                {#if bucket.member}
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-surface-alt text-[10px] font-semibold text-fg-muted"
                    >
                        {initials(bucket.member.name)}
                    </span>
                    <h3
                        class="min-w-0 flex-1 truncate text-sm font-semibold text-fg"
                    >
                        {bucket.member.name}
                    </h3>
                {:else}
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full border border-dashed border-line text-fg-faint"
                    >
                        ◌
                    </span>
                    <h3
                        class="min-w-0 flex-1 truncate text-sm font-semibold text-fg-muted"
                    >
                        Unassigned
                    </h3>
                {/if}
                <span class="ws-eyebrow shrink-0 text-fg-muted">
                    {bucket.open} open{#if bucket.done > 0}&nbsp;· {bucket.done} done{/if}
                </span>
                <button
                    type="button"
                    aria-expanded={!collapsed.has(bucket.key)}
                    aria-label={collapsed.has(bucket.key)
                        ? `Expand ${bucket.member?.name ?? 'Unassigned'}`
                        : `Collapse ${bucket.member?.name ?? 'Unassigned'}`}
                    class="shrink-0 rounded px-1 text-fg-faint transition hover:text-fg"
                    onclick={() => toggleCollapsed(bucket.key)}
                >
                    {collapsed.has(bucket.key) ? '▸' : '▾'}
                </button>
            </header>

            {#if !collapsed.has(bucket.key)}
                <div class="border-t border-line-soft">
                    {#if bucket.tasks.length === 0}
                        <p class="px-4 py-3 text-sm text-fg-muted">
                            All done ✓
                        </p>
                    {:else}
                        <table class="w-full text-left">
                            <tbody>
                                {#each bucket.tasks as task (task.id)}
                                    <TaskTableRow {task} {project} />
                                {/each}
                            </tbody>
                        </table>
                    {/if}
                </div>
            {/if}
        </section>
    {/each}
</div>
