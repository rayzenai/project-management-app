<script lang="ts">
    import { ArrowDown, ArrowUp } from '@lucide/svelte';
    import type { Project, Status, Task } from '../../lib/types';
    import TaskTableRow from './TaskTableRow.svelte';

    type SortKey = 'item' | 'deadline' | 'priority';

    // `statuses` is accepted for parity with BoardView but the list renders
    // each row's own status chip, so it is not read here.
    let {
        project,
        tasks,
    }: { project: Project; tasks: Task[]; statuses?: Status[] } = $props();

    let sort = $state<{ key: SortKey; dir: 'asc' | 'desc' }>({
        key: 'item',
        dir: 'asc',
    });

    const PRIORITY_ORDER: Record<string, number> = {
        urgent: 0,
        high: 1,
        medium: 2,
        low: 3,
    };

    function sortValue(task: Task, key: SortKey): number | string | null {
        switch (key) {
            case 'item':
                return task.item_number ?? null;
            case 'deadline':
                return task.deadline_at ?? null; // ISO date strings compare lexicographically
            case 'priority':
                return PRIORITY_ORDER[task.priority] ?? PRIORITY_ORDER.medium;
        }
    }

    const sorted = $derived.by(() => {
        const dir = sort.dir === 'asc' ? 1 : -1;

        return [...tasks].sort((a, b) => {
            const av = sortValue(a, sort.key);
            const bv = sortValue(b, sort.key);

            if (av === null && bv === null) {
                return 0;
            }

            if (av === null) {
                return 1;
            } // nulls last regardless of direction

            if (bv === null) {
                return -1;
            }

            if (av < bv) {
                return -1 * dir;
            }

            if (av > bv) {
                return 1 * dir;
            }

            return 0;
        });
    });

    function toggleSort(key: SortKey) {
        sort =
            sort.key === key
                ? { key, dir: sort.dir === 'asc' ? 'desc' : 'asc' }
                : { key, dir: 'asc' };
    }
</script>

{#snippet sortArrow(key: SortKey)}
    {#if sort.key === key}
        {#if sort.dir === 'asc'}
            <ArrowUp class="h-3 w-3 text-accent" />
        {:else}
            <ArrowDown class="h-3 w-3 text-accent" />
        {/if}
    {/if}
{/snippet}

<div class="min-w-0 overflow-x-auto">
    <div
        class="col-head grid h-8 min-w-[640px] grid-cols-[16px_44px_minmax(0,1fr)_150px_130px_88px] items-center gap-3 border-b border-line px-4"
    >
        <span><span class="sr-only">Complete</span></span>
        <button
            type="button"
            class="inline-flex items-center gap-1 text-left hover:text-fg"
            onclick={() => toggleSort('item')}
        >
            # {@render sortArrow('item')}
        </button>
        <span>Title</span>
        <span>Status</span>
        <button
            type="button"
            class="inline-flex items-center gap-1 text-left hover:text-fg"
            onclick={() => toggleSort('deadline')}
        >
            Deadline {@render sortArrow('deadline')}
        </button>
        <button
            type="button"
            class="inline-flex items-center justify-end gap-1 hover:text-fg"
            onclick={() => toggleSort('priority')}
            aria-label="Sort by priority"
        >
            Priority {@render sortArrow('priority')}
        </button>
    </div>

    <div class="min-w-[640px]">
        {#each sorted as task (task.id)}
            <TaskTableRow {task} {project} />
        {/each}
    </div>
</div>
