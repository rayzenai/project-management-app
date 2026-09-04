<script lang="ts">
    import { ChevronDown, ChevronRight, UserRound } from '@lucide/svelte';
    import { SvelteMap, SvelteSet } from 'svelte/reactivity';
    import type { Member, Project, Status, Task } from '../../lib/types';
    import Avatar from '../Avatar.svelte';
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

<div class="min-w-0 overflow-x-auto">
    {#if onlyUnassigned}
        <p class="px-4 py-3 text-fg-muted">
            No one is assigned yet. Open a task and add assignees from the peek.
        </p>
    {/if}

    {#each buckets as bucket (bucket.key)}
        {@const isCollapsed = collapsed.has(bucket.key)}
        <section class="min-w-[640px]">
            <header
                class="flex h-10 items-center gap-2.5 border-b border-line bg-surface-alt px-4"
            >
                {#if bucket.member}
                    <Avatar name={bucket.member.name} size="md" />
                    <h3 class="section-title min-w-0">
                        <span class="truncate">{bucket.member.name}</span>
                        <span class="section-count">
                            {bucket.open} open{#if bucket.done > 0},
                                {bucket.done} done{/if}
                        </span>
                    </h3>
                {:else}
                    <span
                        class="inline-grid h-6 w-6 shrink-0 place-items-center rounded-md border border-line text-fg-faint"
                    >
                        <UserRound class="h-3.5 w-3.5" />
                    </span>
                    <h3 class="section-title min-w-0">
                        <span class="truncate text-fg-muted">Unassigned</span>
                        <span class="section-count">
                            {bucket.open} open{#if bucket.done > 0},
                                {bucket.done} done{/if}
                        </span>
                    </h3>
                {/if}
                <button
                    type="button"
                    aria-expanded={!isCollapsed}
                    aria-label={isCollapsed
                        ? `Expand ${bucket.member?.name ?? 'Unassigned'}`
                        : `Collapse ${bucket.member?.name ?? 'Unassigned'}`}
                    class="btn-icon ml-auto"
                    onclick={() => toggleCollapsed(bucket.key)}
                >
                    {#if isCollapsed}
                        <ChevronRight class="h-4 w-4" />
                    {:else}
                        <ChevronDown class="h-4 w-4" />
                    {/if}
                </button>
            </header>

            {#if !isCollapsed}
                {#if bucket.tasks.length === 0}
                    <p class="px-4 py-3 text-fg-muted">All done</p>
                {:else}
                    {#each bucket.tasks as task (task.id)}
                        <TaskTableRow {task} {project} />
                    {/each}
                {/if}
            {/if}
        </section>
    {/each}
</div>
