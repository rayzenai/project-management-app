<script lang="ts">
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
        <span class="text-accent">{sort.dir === 'asc' ? '↑' : '↓'}</span>
    {/if}
{/snippet}

<div class="bg-surface overflow-x-auto rounded-xl border border-line">
    <table class="w-full text-left">
        <thead>
            <tr class="border-b border-line">
                <th class="w-8 px-3 py-2"
                    ><span class="sr-only">Complete</span></th
                >
                <th class="w-14 px-2 py-2">
                    <button
                        type="button"
                        class="ws-eyebrow text-fg-muted hover:text-fg"
                        onclick={() => toggleSort('item')}
                    >
                        # {@render sortArrow('item')}
                    </button>
                </th>
                <th class="px-2 py-2"
                    ><span class="ws-eyebrow text-fg-muted">Title</span></th
                >
                <th class="w-36 px-2 py-2"
                    ><span class="ws-eyebrow text-fg-muted">Status</span></th
                >
                <th class="w-10 px-2 py-2 text-center">
                    <button
                        type="button"
                        class="ws-eyebrow text-fg-muted hover:text-fg"
                        onclick={() => toggleSort('priority')}
                        aria-label="Sort by priority"
                    >
                        <span
                            class="bg-fg-faint inline-block h-2 w-2 rounded-full align-middle"
                        ></span>
                        {@render sortArrow('priority')}
                    </button>
                </th>
                <th class="w-28 px-2 py-2">
                    <button
                        type="button"
                        class="ws-eyebrow text-fg-muted hover:text-fg"
                        onclick={() => toggleSort('deadline')}
                    >
                        Deadline {@render sortArrow('deadline')}
                    </button>
                </th>
                <th class="w-24 px-3 py-2"
                    ><span class="ws-eyebrow text-fg-muted">Who</span></th
                >
            </tr>
        </thead>
        <tbody>
            {#each sorted as task (task.id)}
                <TaskTableRow {task} {project} />
            {/each}
        </tbody>
    </table>
</div>
